<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\ExitCode;
use AlexSkrypnyk\SkillTest\Live\AgentPreflight;
use AlexSkrypnyk\SkillTest\Live\LlmReport;
use AlexSkrypnyk\SkillTest\Live\LlmSuite;
use AlexSkrypnyk\SkillTest\Live\ModelOutcome;
use AlexSkrypnyk\SkillTest\Live\SkillOutcome;
use AlexSkrypnyk\SkillTest\Live\TaskOutcome;
use AlexSkrypnyk\SkillTest\Live\TrialResult;
use AlexSkrypnyk\SkillTest\Run\Redactor;
use AlexSkrypnyk\SkillTest\Run\ResultsWriter;
use AlexSkrypnyk\SkillTest\Run\RunSelection;
use AlexSkrypnyk\SkillTest\Validation\ConfigValidator;
use AlexSkrypnyk\SkillTest\Validation\ValidationMessage;
use AlexSkrypnyk\SkillTest\Version;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The `llm` command: run skills live and gate on a pass-rate threshold.
 *
 * The explicit, token-spending counterpart to the deterministic gate: for each
 * selected skill and task it runs the skill headlessly through Claude Code
 * `trials` times per model, asserts the same contract the deterministic suite
 * asserts against every live transcript, and fails when any model's pass rate
 * drops below the task threshold. It never runs implicitly - it needs an
 * authenticated agent and spends tokens - so a missing binary or credential, or
 * the not-yet-supported docker environment, is a configuration error (exit 2)
 * before any trial runs. Results go to stdout; diagnostics to stderr; `--json`
 * emits the machine-readable document and `--output`/`--output-dir` persist it
 * with per-trial transcripts, redacted.
 */
class LlmCommand extends Command {

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setName('llm')
      ->setDescription('Run the live llm suite: headless trials asserted against the contract, gated on a pass-rate threshold')
      ->addOption(name: 'dir', mode: InputOption::VALUE_REQUIRED, description: 'Repository root (default: current directory)')
      ->addOption(name: 'skill', mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, description: 'Select skills by name glob (repeatable)')
      ->addOption(name: 'task', mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, description: 'Select tasks by name glob (repeatable)')
      ->addOption(name: 'models', mode: InputOption::VALUE_REQUIRED, description: 'Override models (aliases or ids, comma-separated)')
      ->addOption(name: 'trials', mode: InputOption::VALUE_REQUIRED, description: 'Override the trial count per model')
      ->addOption(name: 'threshold', mode: InputOption::VALUE_REQUIRED, description: 'Override the pass-rate threshold (0..1)')
      ->addOption(name: 'env', mode: InputOption::VALUE_REQUIRED, description: 'Execution environment: host (docker not yet supported)')
      ->addOption(name: 'parallel', mode: InputOption::VALUE_REQUIRED, description: 'Number of concurrent trials (default 1)')
      ->addOption(name: 'judge-model', mode: InputOption::VALUE_REQUIRED, description: 'Override the judge model (reserved; the judge is not yet wired)')
      ->addOption(name: 'json', mode: InputOption::VALUE_NONE, description: 'Emit the machine-readable results document on stdout and nothing else')
      ->addOption(name: 'output', mode: InputOption::VALUE_REQUIRED, description: 'Persist the results document to this file')
      ->addOption(name: 'output-dir', mode: InputOption::VALUE_REQUIRED, description: 'Persist the results document and transcripts to a timestamped subdirectory of this directory');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $started = microtime(TRUE);
    $started_at = date(DATE_ATOM);
    $root = $this->resolveRoot($input);
    $json = (bool) $input->getOption('json');
    $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

    $parallel_option = $this->stringOption($input, 'parallel');
    $parallel = $parallel_option === NULL ? 1 : $this->intOption($input, 'parallel');

    if ($parallel === NULL) {
      return $this->reportErrors($output, $stderr, $json, [ValidationMessage::error('', '', '--parallel must be an integer.')]);
    }

    if ($parallel < 1) {
      return $this->reportErrors($output, $stderr, $json, [ValidationMessage::error('', '', '--parallel must be at least 1.')]);
    }

    try {
      $selection = RunSelection::create($this->globs($input, 'skill'), NULL, NULL);
      $loaded = (new ConfigLoader($root))->load($this->overrides($input));
    }
    catch (ConfigException $config_exception) {
      return $this->reportErrors($output, $stderr, $json, [$this->toMessage($config_exception)]);
    }

    $validation = (new ConfigValidator($root))->validate($loaded);

    foreach ($validation->warnings() as $warning) {
      $stderr->writeln('WARNING ' . $warning->render());
    }

    if ($validation->hasErrors()) {
      return $this->reportErrors($output, $stderr, $json, $validation->errors());
    }

    $environment = $this->stringOption($input, 'env') ?? $loaded->repo->environment;

    if ($environment === 'docker') {
      return $this->reportErrors($output, $stderr, $json, [ValidationMessage::error('', '', 'the docker environment is not yet implemented; run with --env host.')]);
    }

    // The host agent is only a precondition once the run is known to target the
    // host environment, so its preflight runs after docker has been ruled out.
    $preflight = new AgentPreflight($this->environmentMap());
    $problem = $preflight->problem();

    if ($problem !== NULL) {
      return $this->reportErrors($output, $stderr, $json, [ValidationMessage::error('', '', $problem)]);
    }

    $filtered = $selection->filter($loaded);

    if ($filtered->skills === []) {
      $message = $selection->globs === [] ? 'no skills found under the configured skills paths.' : sprintf('no skills matched --skill %s.', implode(', ', $selection->globs));

      return $this->reportErrors($output, $stderr, $json, [ValidationMessage::error('', '', $message)]);
    }

    $binary = (string) $preflight->binary();

    try {
      $report = (new LlmSuite($root, $binary, $parallel, $this->timeout()))->run($filtered, $this->globs($input, 'task'));
    }
    catch (ConfigException $config_exception) {
      return $this->reportErrors($output, $stderr, $json, [$this->toMessage($config_exception)]);
    }

    $document = $report->toResults(Version::RESULTS_SCHEMA_VERSION, ['name' => Version::NAME, 'version' => Version::id()], [
      'id' => 'st-' . date('Ymd-His'),
      'started' => $started_at,
      'duration_ms' => (int) round((microtime(TRUE) - $started) * 1000),
      'command' => 'llm',
      'environment' => $environment,
    ]);

    $output_file = $this->stringOption($input, 'output');
    $output_dir = $this->stringOption($input, 'output-dir');

    if ($output_file !== NULL || $output_dir !== NULL) {
      $this->persist($loaded, $stderr, $report, $document, $output_file, $output_dir);
    }

    if ($json) {
      $output->writeln($this->encode($document), OutputInterface::VERBOSITY_QUIET);
    }
    else {
      $this->renderReport($output, $report);
    }

    return $report->failed() ? ExitCode::FAIL : ExitCode::PASS;
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
   * Builds the CLI configuration overrides from the command input.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   *
   * @return array<string, string>
   *   The overrides keyed by name (models, threshold, trials, env).
   */
  protected function overrides(InputInterface $input): array {
    $overrides = [];

    foreach (['models', 'threshold', 'trials', 'env'] as $name) {
      $value = $this->stringOption($input, $name);

      if ($value !== NULL) {
        $overrides[$name] = $value;
      }
    }

    return $overrides;
  }

  /**
   * Extracts the string-glob values of a repeatable option.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   * @param string $name
   *   The option name.
   *
   * @return string[]
   *   The non-empty glob strings.
   */
  protected function globs(InputInterface $input, string $name): array {
    $raw = $input->getOption($name);

    return array_values(array_filter(is_array($raw) ? $raw : [], static fn(mixed $glob): bool => is_string($glob) && $glob !== ''));
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

  /**
   * Reads an integer option, returning NULL when it is absent or non-numeric.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   * @param string $name
   *   The option name.
   *
   * @return int|null
   *   The option value, or NULL when unset or not an integer.
   */
  protected function intOption(InputInterface $input, string $name): ?int {
    return Data::toIntOrNull($input->getOption($name));
  }

  /**
   * Persists the results document and transcripts, redacted, to disk.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded
   *   The loaded configuration, carrying the repo `report` block.
   * @param \Symfony\Component\Console\Output\OutputInterface $stderr
   *   The error output for the disabled-redaction warning and write notices.
   * @param \AlexSkrypnyk\SkillTest\Live\LlmReport $report
   *   The run outcome, supplying the transcript artifacts.
   * @param array<string, mixed> $document
   *   The results document to persist.
   * @param string|null $file
   *   The `--output` file destination, when set.
   * @param string|null $dir
   *   The `--output-dir` parent directory, when set.
   */
  protected function persist(LoadedConfig $loaded, OutputInterface $stderr, LlmReport $report, array $document, ?string $file, ?string $dir): void {
    $redact = Data::toBoolOrNull(Data::get($loaded->repo->report, 'redact')) ?? TRUE;

    if (!$redact) {
      $stderr->writeln('WARNING redaction disabled (report.redact: false); environment secrets may be written to persisted artifacts.', OutputInterface::VERBOSITY_QUIET);
    }

    $writer = new ResultsWriter(Redactor::fromEnvironment(getenv(), $redact));

    if ($dir !== NULL) {
      $stderr->writeln(sprintf('results written to %s', $writer->writeDir($document, $dir, gmdate('Ymd-His'), $report->artifacts())));
    }

    if ($file !== NULL) {
      $stderr->writeln(sprintf('results written to %s', $writer->writeFile($document, $file)));
    }
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
   * Reports configuration errors and returns exit 2.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The standard output.
   * @param \Symfony\Component\Console\Output\OutputInterface $stderr
   *   The error output.
   * @param bool $json
   *   Whether the JSON output contract is in effect.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationMessage[] $errors
   *   The errors to report.
   *
   * @return int
   *   The config-error exit code.
   */
  protected function reportErrors(OutputInterface $output, OutputInterface $stderr, bool $json, array $errors): int {
    if ($json) {
      $payload = [
        'ok' => FALSE,
        'skills' => [],
        'errors' => array_map(static fn(ValidationMessage $message): array => $message->toArray(), $errors),
      ];
      $output->writeln($this->encode($payload), OutputInterface::VERBOSITY_QUIET);
    }
    else {
      foreach ($errors as $error) {
        $stderr->writeln('ERROR ' . $error->render(), OutputInterface::VERBOSITY_QUIET);
      }
    }

    return ExitCode::CONFIG_ERROR;
  }

  /**
   * Renders the human report: a verdict line per task-model, then totals.
   *
   * In quiet verbosity only failing verdicts and their failed trials print,
   * so a green run is silent and a red run names exactly what failed.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param \AlexSkrypnyk\SkillTest\Live\LlmReport $report
   *   The run outcome.
   */
  protected function renderReport(OutputInterface $output, LlmReport $report): void {
    $quiet = $output->isQuiet();

    foreach ($report->skills as $skill) {
      $this->renderSkill($output, $skill, $quiet);
    }

    if (!$quiet) {
      $gates = $report->gates();
      $failures = $report->failures();
      $tokens = $report->tokens();
      $output->writeln('');
      $output->writeln(sprintf('%d verdict(s) across %d skill(s): %d passed, %d failed. %d trial(s), %d in / %d out tokens, $%s.', $gates, count($report->skills), $gates - $failures, $failures, $report->trials(), $tokens['in'], $tokens['out'], number_format($report->cost(), 4)));
    }
  }

  /**
   * Renders one skill's task-model verdict lines and failed trials.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param \AlexSkrypnyk\SkillTest\Live\SkillOutcome $skill
   *   The skill's outcome.
   * @param bool $quiet
   *   Whether only failing verdicts should print.
   */
  protected function renderSkill(OutputInterface $output, SkillOutcome $skill, bool $quiet): void {
    foreach ($skill->tasks as $task) {
      foreach ($task->models as $model) {
        $this->renderModel($output, $skill->skill, $task, $model, $quiet);
      }
    }
  }

  /**
   * Renders one task-model verdict line and, on failure, its failed trials.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param string $skill
   *   The skill name.
   * @param \AlexSkrypnyk\SkillTest\Live\TaskOutcome $task
   *   The task outcome.
   * @param \AlexSkrypnyk\SkillTest\Live\ModelOutcome $model
   *   The model outcome.
   * @param bool $quiet
   *   Whether only failing verdicts should print.
   */
  protected function renderModel(OutputInterface $output, string $skill, TaskOutcome $task, ModelOutcome $model, bool $quiet): void {
    $passed = $model->passed();
    $passing = count(array_filter($model->trials, static fn(TrialResult $trial): bool => $trial->pass));
    $line = sprintf('%s %s %s %s (pass_rate %s, %d/%d trials)', $skill, $task->task, $model->alias, $passed ? 'PASS' : 'FAIL', number_format($model->passRate(), 2), $passing, count($model->trials));

    if ($passed) {
      if (!$quiet) {
        $output->writeln($line);
      }

      return;
    }

    $output->writeln($line, $quiet ? OutputInterface::VERBOSITY_QUIET : OutputInterface::VERBOSITY_NORMAL);

    foreach ($model->trials as $trial) {
      foreach ($trial->failures() as $failure) {
        $output->writeln($this->failureLine($trial->number, $failure), $quiet ? OutputInterface::VERBOSITY_QUIET : OutputInterface::VERBOSITY_NORMAL);
      }
    }
  }

  /**
   * Renders one failed check of one trial as an indented line.
   *
   * @param int $number
   *   The 1-based trial number.
   * @param \AlexSkrypnyk\SkillTest\Contract\CheckResult $failure
   *   The failed check.
   *
   * @return string
   *   The rendered line.
   */
  protected function failureLine(int $number, CheckResult $failure): string {
    $line = sprintf('  trial %d %s FAIL - %s', $number, $failure->id, $failure->message);

    return $failure->evidence === '' ? $line : sprintf('%s [%s]', $line, $failure->evidence);
  }

  /**
   * Encodes a payload as a single JSON line.
   *
   * @param array<string, mixed> $payload
   *   The payload to encode.
   *
   * @return string
   *   The JSON encoding.
   */
  protected function encode(array $payload): string {
    return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
  }

}
