<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Config\EffectiveConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedSkill;
use AlexSkrypnyk\SkillTest\Config\RepoConfig;
use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Contract\TranscriptGrader;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\ExitCode;
use AlexSkrypnyk\SkillTest\Live\AgentPreflight;
use AlexSkrypnyk\SkillTest\Live\DockerEnvironment;
use AlexSkrypnyk\SkillTest\Live\DockerPreflight;
use AlexSkrypnyk\SkillTest\Live\HostEnvironment;
use AlexSkrypnyk\SkillTest\Live\LlmSuite;
use AlexSkrypnyk\SkillTest\Live\ProcessPool;
use AlexSkrypnyk\SkillTest\Live\RecordRunner;
use AlexSkrypnyk\SkillTest\Run\Redactor;
use AlexSkrypnyk\SkillTest\Validation\ConfigValidator;
use AlexSkrypnyk\SkillTest\Validation\ValidationMessage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The `record` command: one live trial captured as the deterministic fixture.
 *
 * The bridge between the two suites. It runs a single live trial of one
 * skill's task, writes the transcript (redacted) to the skill's configured
 * `deterministic.transcript` path, then asserts the contract against the file
 * it wrote - so the verdict is graded from the fixture that ships, not the
 * live run, and "passes record" means "passes the deterministic transcript
 * gate". The workflow it serves is deliberate: change a skill, run
 * `skilltest record`, review the diff, commit. An existing fixture is never
 * clobbered without `--force`. A recording whose contract fails is still
 * written for inspection but exits 1, so a fixture that would poison the gate
 * is caught here rather than on the next push. Like the llm suite it spends
 * tokens and needs an authenticated agent, so a missing binary or credential,
 * or for docker an unreachable daemon, is a configuration error (exit 2)
 * before any trial runs.
 */
class RecordCommand extends Command {

  /**
   * The fixture path used when a skill sets no `deterministic.transcript`.
   */
  public const string DEFAULT_FIXTURE = 'fixtures/transcript.jsonl';

  /**
   * The validation pointer of the "declared fixture is missing" error.
   *
   * Recording is precisely what creates that fixture, so a not-yet-recorded
   * fixture is the normal starting state and must never block the command that
   * would produce it; every other validation error still does.
   */
  protected const string FIXTURE_POINTER = 'deterministic.transcript';

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setName('record')
      ->setDescription("Run one live trial and write its transcript as the skill's deterministic fixture")
      ->addOption(name: 'dir', mode: InputOption::VALUE_REQUIRED, description: 'Repository root (default: current directory)')
      ->addOption(name: 'skill', mode: InputOption::VALUE_REQUIRED, description: 'Required: the skill to record')
      ->addOption(name: 'task', mode: InputOption::VALUE_REQUIRED, description: 'Task to record (default: the first declared task)')
      ->addOption(name: 'model', mode: InputOption::VALUE_REQUIRED, description: 'Model to record with (default: the repo default)')
      ->addOption(name: 'force', mode: InputOption::VALUE_NONE, description: 'Overwrite an existing fixture');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $root = $this->resolveRoot($input);
    $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

    $skill_name = $this->stringOption($input, 'skill');

    if ($skill_name === NULL) {
      return $this->reportError($stderr, ValidationMessage::error('', '', 'the --skill option is required.'));
    }

    try {
      $loaded = (new ConfigLoader($root))->load();
    }
    catch (ConfigException $config_exception) {
      return $this->reportError($stderr, $this->toMessage($config_exception));
    }

    $validation = (new ConfigValidator($root))->validate($loaded);

    foreach ($validation->warnings() as $warning) {
      $stderr->writeln('WARNING ' . $warning->render());
    }

    $blocking = array_values(array_filter($validation->errors(), static fn(ValidationMessage $error): bool => $error->pointer !== self::FIXTURE_POINTER));

    if ($blocking !== []) {
      foreach ($blocking as $error) {
        $stderr->writeln('ERROR ' . $error->render(), OutputInterface::VERBOSITY_QUIET);
      }

      return ExitCode::CONFIG_ERROR;
    }

    $skill = $this->selectSkill($loaded, $skill_name);

    if (!$skill instanceof LoadedSkill) {
      return $this->reportError($stderr, ValidationMessage::error('', '', sprintf("no skill named '%s' with an %s was found.", $skill_name, $loaded->repo->evalFile)));
    }

    try {
      $entry = $this->selectTask($skill, $this->stringOption($input, 'task'));
    }
    catch (ConfigException $config_exception) {
      return $this->reportError($stderr, $this->toMessage($config_exception));
    }

    $model_id = $this->resolveModel($loaded->repo, $skill->effective, $this->stringOption($input, 'model'));

    if ($model_id === NULL) {
      return $this->reportError($stderr, ValidationMessage::error($skill->file, 'llm.models', 'no model configured; set models.default or pass --model.'));
    }

    $path = $this->fixturePath($skill);
    $existed = is_file($path);

    if ($existed && !$input->getOption('force')) {
      return $this->reportError($stderr, ValidationMessage::error('', '', sprintf('fixture %s already exists; pass --force to overwrite.', $this->relative($root, $path))));
    }

    $environment = $skill->effective->environment;
    $env_map = $this->environmentMap();

    $preflight = $environment === 'docker' ? new DockerPreflight($env_map, $root) : new AgentPreflight($env_map);
    $problem = $preflight->problem();

    if ($problem !== NULL) {
      return $this->reportError($stderr, ValidationMessage::error('', '', $problem));
    }

    try {
      if ($environment === 'docker') {
        $runtime = new DockerEnvironment($root, 1, $this->timeout(), $loaded->repo->docker, (string) $preflight->binary(), $env_map);
        // The agent runs inside the container, so record drives the image's
        // own `claude` rather than the host binary.
        $binary = AgentPreflight::DEFAULT_BINARY;
      }
      else {
        $runtime = new HostEnvironment($root, 1, $this->timeout());
        $binary = (string) $preflight->binary();
      }

      $result = (new RecordRunner($root, $binary, $runtime))->record($skill, $entry, $model_id);
    }
    catch (ConfigException $config_exception) {
      return $this->reportError($stderr, $this->toMessage($config_exception));
    }

    $this->write($root, $stderr, $loaded, $path, $result->transcript);

    $checks = $this->grade($root, $loaded, $skill, $path, $result->exitCode);
    $pass = array_reduce($checks, static fn(bool $carry, CheckResult $result): bool => $carry && $result->pass, TRUE);

    $this->report($output, $skill, $entry['name'], $model_id, $this->relative($root, $path), $existed && (bool) $input->getOption('force'), $checks, $pass);

    if ($skill->effective->transcript === NULL) {
      $stderr->writeln(sprintf("note: set 'deterministic.transcript: %s' in %s so the deterministic run consumes this fixture.", self::DEFAULT_FIXTURE, $this->relative($root, $skill->file)));
    }

    return $pass ? ExitCode::PASS : ExitCode::FAIL;
  }

  /**
   * The process environment as a name-keyed string map.
   *
   * @return array<string, string>
   *   The environment map.
   */
  protected function environmentMap(): array {
    return getenv();
  }

  /**
   * Resolves the per-trial timeout from the environment, or the default.
   *
   * @return float
   *   The timeout in seconds.
   */
  protected function timeout(): float {
    $value = getenv(LlmSuite::ENV_TIMEOUT);

    return is_string($value) && is_numeric($value) ? (float) $value : LlmSuite::DEFAULT_TIMEOUT;
  }

  /**
   * Finds the one loaded skill whose name matches, or NULL.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded
   *   The loaded configuration.
   * @param string $name
   *   The requested skill name.
   *
   * @return \AlexSkrypnyk\SkillTest\Config\LoadedSkill|null
   *   The matching skill, or NULL when none carries that name.
   */
  protected function selectSkill(LoadedConfig $loaded, string $name): ?LoadedSkill {
    foreach ($loaded->skills as $skill) {
      if ($skill->effective->skill === $name) {
        return $skill;
      }
    }

    return NULL;
  }

  /**
   * Selects and validates the task to record: the named one, or the first.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $skill
   *   The skill being recorded.
   * @param string|null $name
   *   The requested task name, or NULL for the first declared task.
   *
   * @return array{name: string, prompt: string, task: array<mixed>}
   *   The validated task entry.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the skill declares no tasks, a task omits its name or prompt, or no
   *   task carries the requested name.
   */
  protected function selectTask(LoadedSkill $skill, ?string $name): array {
    $tasks = $skill->effective->tasks;

    if ($tasks === []) {
      throw new ConfigException(sprintf("skill '%s' declares no llm tasks to record.", $skill->effective->skill), $skill->file, 'llm.tasks');
    }

    foreach ($tasks as $task) {
      $task_name = Data::toStringOrNull(Data::get($task, 'name'));

      if ($task_name === NULL || $task_name === '') {
        throw new ConfigException("an llm task requires a 'name'.", $skill->file, 'llm.tasks');
      }

      if ($name !== NULL && $task_name !== $name) {
        continue;
      }

      $prompt = Data::toStringOrNull(Data::get($task, 'prompt'));

      if ($prompt === NULL || $prompt === '') {
        throw new ConfigException(sprintf("llm task '%s' requires a 'prompt'.", $task_name), $skill->file, 'llm.tasks');
      }

      return ['name' => $task_name, 'prompt' => $prompt, 'task' => $task];
    }

    throw new ConfigException(sprintf("skill '%s' has no task named '%s'.", $skill->effective->skill, (string) $name), $skill->file, 'llm.tasks');
  }

  /**
   * Resolves the model id to record with: the override, the default, or first.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\RepoConfig $repo
   *   The repo configuration, supplying the default model and aliases.
   * @param \AlexSkrypnyk\SkillTest\Config\EffectiveConfig $effective
   *   The skill's effective configuration, supplying the model list fallback.
   * @param string|null $override
   *   The `--model` value (an alias or a full id), when given.
   *
   * @return string|null
   *   The resolved model id, or NULL when no model is configured at all.
   */
  protected function resolveModel(RepoConfig $repo, EffectiveConfig $effective, ?string $override): ?string {
    $token = $override ?? $repo->defaultModel ?? ($effective->models[0] ?? NULL);

    if ($token === NULL) {
      return NULL;
    }

    return $effective->modelAliases[$token] ?? $token;
  }

  /**
   * Resolves the absolute fixture path the transcript is written to.
   *
   * The path is resolved exactly as the deterministic transcript group resolves
   * it - relative to the skill directory - so the written file and the file the
   * gate later reads are one and the same.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $skill
   *   The skill being recorded.
   *
   * @return string
   *   The absolute fixture path.
   */
  protected function fixturePath(LoadedSkill $skill): string {
    $fixture = $skill->effective->transcript ?? self::DEFAULT_FIXTURE;

    return str_starts_with($fixture, '/') ? $fixture : dirname($skill->file) . '/' . $fixture;
  }

  /**
   * Redacts and writes the transcript to the fixture path, creating parents.
   *
   * @param string $root
   *   The repository root, for the write notice's relative path.
   * @param \Symfony\Component\Console\Output\OutputInterface $stderr
   *   The error output for the disabled-redaction warning.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded
   *   The loaded configuration, carrying the repo `report` block.
   * @param string $path
   *   The absolute fixture path.
   * @param string $transcript
   *   The raw transcript to redact and write.
   */
  protected function write(string $root, OutputInterface $stderr, LoadedConfig $loaded, string $path, string $transcript): void {
    $redact = Data::toBoolOrNull(Data::get($loaded->repo->report, 'redact')) ?? TRUE;

    if (!$redact) {
      $stderr->writeln('WARNING redaction disabled (report.redact: false); environment secrets may be written to the fixture.', OutputInterface::VERBOSITY_QUIET);
    }

    $contents = Redactor::fromEnvironment($this->environmentMap(), $redact)->redactString($transcript);

    $dir = dirname($path);

    if (!is_dir($dir)) {
      mkdir($dir, 0777, TRUE);
    }

    file_put_contents($path, $contents);
  }

  /**
   * Grades the written fixture against the contract, custom checks, and run.
   *
   * The fixture is read back from disk and graded exactly as the deterministic
   * transcript group grades it, so a fixture that passes here is one the gate
   * will accept. A non-zero agent exit is folded in as a failing check so a
   * broken or truncated recording can never be a passing fixture.
   *
   * @param string $root
   *   The repository root, the working directory custom checks run under.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded
   *   The loaded configuration, supplying the repo command aliases.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $skill
   *   The skill being recorded.
   * @param string $path
   *   The absolute fixture path just written.
   * @param int $exit_code
   *   The agent process exit code.
   *
   * @return \AlexSkrypnyk\SkillTest\Contract\CheckResult[]
   *   The graded checks, in assertion order.
   */
  protected function grade(string $root, LoadedConfig $loaded, LoadedSkill $skill, string $path, int $exit_code): array {
    $checks = (new TranscriptGrader($root, $loaded->repo->aliases))->grade($path, $skill->effective->contract, $skill->effective->checks, dirname($skill->file));

    if ($exit_code !== 0) {
      array_unshift($checks, $this->agentFailure($exit_code));
    }

    return $checks;
  }

  /**
   * Builds the failing check that folds a broken agent run into the verdict.
   *
   * @param int $exit_code
   *   The agent process exit code.
   *
   * @return \AlexSkrypnyk\SkillTest\Contract\CheckResult
   *   The failing agent check.
   */
  protected function agentFailure(int $exit_code): CheckResult {
    $message = $exit_code === ProcessPool::TIMEOUT_EXIT
      ? sprintf('agent run timed out after %ds.', (int) round($this->timeout()))
      : sprintf('agent run exited with code %d.', $exit_code);

    return CheckResult::fail(LlmSuite::CHECK_AGENT, 'agent run', '', $message);
  }

  /**
   * Renders the human report: what was recorded, where, and the verdict.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $skill
   *   The skill that was recorded.
   * @param string $task
   *   The task name that was recorded.
   * @param string $model_id
   *   The model id the trial ran on.
   * @param string $path
   *   The fixture path, relative to the repository root.
   * @param bool $overwrote
   *   TRUE when an existing fixture was overwritten under `--force`.
   * @param \AlexSkrypnyk\SkillTest\Contract\CheckResult[] $checks
   *   The graded checks.
   * @param bool $pass
   *   Whether every check passed.
   */
  protected function report(OutputInterface $output, LoadedSkill $skill, string $task, string $model_id, string $path, bool $overwrote, array $checks, bool $pass): void {
    $output->writeln(sprintf("%s %s (skill '%s', task '%s', model '%s').", $overwrote ? 'Overwrote' : 'Recorded', $path, $skill->effective->skill, $task, $model_id));

    if ($pass) {
      $output->writeln(sprintf('Contract holds: %d check(s) passed.', count($checks)));
      $output->writeln('Review the fixture diff before committing.');

      return;
    }

    $output->writeln('Contract failed - the fixture does not satisfy its own contract:');

    foreach ($checks as $check) {
      if (!$check->pass) {
        $output->writeln($this->failureLine($check));
      }
    }

    $output->writeln('The fixture was written for inspection; fix the skill and re-record before committing.');
  }

  /**
   * Renders one failed check as an indented line with its evidence.
   *
   * @param \AlexSkrypnyk\SkillTest\Contract\CheckResult $failure
   *   The failed check.
   *
   * @return string
   *   The rendered line.
   */
  protected function failureLine(CheckResult $failure): string {
    $line = sprintf('  %s FAIL - %s', $failure->id, $failure->message);

    return $failure->evidence === '' ? $line : sprintf('%s [%s]', $line, $failure->evidence);
  }

  /**
   * Reduces an absolute path under the root to a root-relative one.
   *
   * @param string $root
   *   The repository root.
   * @param string $path
   *   The absolute path.
   *
   * @return string
   *   The path relative to the root, or unchanged when it is outside the root.
   */
  protected function relative(string $root, string $path): string {
    $prefix = rtrim($root, '/') . '/';

    return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
  }

  /**
   * Reports a configuration error to stderr and returns exit 2.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $stderr
   *   The error output.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationMessage $error
   *   The error to report.
   *
   * @return int
   *   The config-error exit code.
   */
  protected function reportError(OutputInterface $stderr, ValidationMessage $error): int {
    $stderr->writeln('ERROR ' . $error->render(), OutputInterface::VERBOSITY_QUIET);

    return ExitCode::CONFIG_ERROR;
  }

  /**
   * Converts a thrown configuration error to a reportable message.
   *
   * @param \AlexSkrypnyk\SkillTest\Exception\ConfigException $config_exception
   *   The thrown error.
   *
   * @return \AlexSkrypnyk\SkillTest\Validation\ValidationMessage
   *   The equivalent validation message.
   */
  protected function toMessage(ConfigException $config_exception): ValidationMessage {
    return ValidationMessage::error($config_exception->configFile(), $config_exception->pointer(), $config_exception->getMessage());
  }

  /**
   * Resolves the repository root from the option or the current directory.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   *
   * @return string
   *   The repository root.
   */
  protected function resolveRoot(InputInterface $input): string {
    $dir = $input->getOption('dir');

    if (is_string($dir) && $dir !== '') {
      return $dir;
    }

    $cwd = getcwd();

    // @codeCoverageIgnoreStart
    if ($cwd === FALSE) {
      return '.';
    }
    // @codeCoverageIgnoreEnd
    return $cwd;
  }

  /**
   * Reads a string option, returning NULL when it is absent or empty.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   * @param string $name
   *   The option name.
   *
   * @return string|null
   *   The option value, or NULL when it is unset or blank.
   */
  protected function stringOption(InputInterface $input, string $name): ?string {
    $value = $input->getOption($name);

    return is_string($value) && $value !== '' ? $value : NULL;
  }

}
