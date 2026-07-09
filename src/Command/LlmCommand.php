<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\ExitCode;
use AlexSkrypnyk\SkillTest\Live\AgentPreflight;
use AlexSkrypnyk\SkillTest\Live\DockerEnvironment;
use AlexSkrypnyk\SkillTest\Live\DockerPreflight;
use AlexSkrypnyk\SkillTest\Live\HostEnvironment;
use AlexSkrypnyk\SkillTest\Live\Lifecycle;
use AlexSkrypnyk\SkillTest\Live\LlmReport;
use AlexSkrypnyk\SkillTest\Live\LlmSuite;
use AlexSkrypnyk\SkillTest\Live\ModelOutcome;
use AlexSkrypnyk\SkillTest\Live\SkillOutcome;
use AlexSkrypnyk\SkillTest\Live\TaskOutcome;
use AlexSkrypnyk\SkillTest\Live\TrialCache;
use AlexSkrypnyk\SkillTest\Live\TrialResult;
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
 * authenticated agent and spends tokens - so a missing binary or credential,
 * or for docker an unreachable daemon, is a configuration error (exit 2)
 * before any trial runs. Results go to stdout; diagnostics to stderr; `--json`
 * emits the machine-readable document and `--output`/`--output-dir` persist it
 * with per-trial transcripts, redacted.
 */
class LlmCommand extends Command {

  use LiveOptionsTrait;

  /**
   * The input options folded into the CLI configuration overrides.
   */
  protected const array OVERRIDES = [
    'models' => 'models',
    'threshold' => 'threshold',
    'trials' => 'trials',
    'env' => 'env',
    'judge-model' => 'judge-model',
  ];

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
      ->addOption(name: 'env', mode: InputOption::VALUE_REQUIRED, description: 'Execution environment: host or docker')
      ->addOption(name: 'parallel', mode: InputOption::VALUE_REQUIRED, description: 'Number of concurrent trials (default 1)')
      ->addOption(name: 'judge-model', mode: InputOption::VALUE_REQUIRED, description: 'Override the judge model (alias or id); the judge model never follows --models')
      ->addOption(name: 'json', mode: InputOption::VALUE_NONE, description: 'Emit the machine-readable results document on stdout and nothing else')
      ->addOption(name: 'output', mode: InputOption::VALUE_REQUIRED, description: 'Persist the results document to this file')
      ->addOption(name: 'output-dir', mode: InputOption::VALUE_REQUIRED, description: 'Persist the results document and transcripts to a timestamped subdirectory of this directory')
      ->addOption(name: 'keep-workspace', mode: InputOption::VALUE_NONE, description: 'Preserve each trial workspace after the run and print its path for debugging')
      ->addOption(name: 'cache', mode: InputOption::VALUE_NONE, description: 'Reuse cached trial results keyed on the task, fixtures, model, and skill content')
      ->addOption(name: 'no-cache', mode: InputOption::VALUE_NONE, description: 'Ignore and do not write cached trial results (overrides --cache)');
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
      $loaded = (new ConfigLoader($root))->load($this->overridesFrom($input, self::OVERRIDES));
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
    $env_map = $this->environmentMap();

    // The preflight is environment-specific: host needs an agent binary on the
    // machine, docker needs the CLI and a reachable daemon; both need
    // credentials. Either problem is a configuration error before any trial.
    $preflight = $environment === 'docker' ? new DockerPreflight($env_map, $root) : new AgentPreflight($env_map);
    $problem = $preflight->problem();

    if ($problem !== NULL) {
      return $this->reportErrors($output, $stderr, $json, [ValidationMessage::error('', '', $problem)]);
    }

    $filtered = $selection->filter($loaded);

    if ($filtered->skills === []) {
      $message = $selection->globs === [] ? 'no skills found under the configured skills paths.' : sprintf('no skills matched --skill %s.', implode(', ', $selection->globs));

      return $this->reportErrors($output, $stderr, $json, [ValidationMessage::error('', '', $message)]);
    }

    $keep = (bool) $input->getOption('keep-workspace');

    try {
      if ($environment === 'docker') {
        $docker = new DockerEnvironment($root, $parallel, $this->timeout(), $loaded->repo->docker, (string) $preflight->binary(), $env_map, keepWorkspaces: $keep);
        $environment_impl = $docker;
        // The agent runs inside the container, so the suite drives the image's
        // own `claude`; lifecycle hooks share the trial's isolation unless a
        // hook opts back onto the host with `on-host`.
        $binary = AgentPreflight::DEFAULT_BINARY;
        $lifecycle = new Lifecycle($root, $loaded->repo->lifecycle, NULL, $this->warn($stderr), containerRunner: $docker->hookRunner());
      }
      else {
        $environment_impl = new HostEnvironment($root, $parallel, $this->timeout(), keepWorkspaces: $keep);
        $binary = (string) $preflight->binary();
        $lifecycle = new Lifecycle($root, $loaded->repo->lifecycle, NULL, $this->warn($stderr));
      }

      $report = (new LlmSuite($root, $binary, $environment_impl, $lifecycle, $parallel, $this->timeout(), cache: $this->cache($input, $root)))->run($filtered, $this->globs($input, 'task'));

      foreach ($environment_impl->keptWorkspaces() as $path) {
        $stderr->writeln(sprintf('workspace preserved: %s', $path));
      }
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
   * Builds the trial cache, or NULL when caching is off for this run.
   *
   * Caching is opt-in: `--cache` turns it on and `--no-cache` forces it off, so
   * a `--no-cache` always wins and the default is to run every trial live.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   * @param string $root
   *   The repository root, under which the cache lives.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\TrialCache|null
   *   The cache, or NULL when caching is disabled.
   */
  protected function cache(InputInterface $input, string $root): ?TrialCache {
    if (!(bool) $input->getOption('cache') || (bool) $input->getOption('no-cache')) {
      return NULL;
    }

    return new TrialCache(rtrim($root, '/') . '/' . TrialCache::CACHE_DIR, Version::id());
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
    $cached = $model->trials !== [] && array_reduce($model->trials, static fn(bool $carry, TrialResult $trial): bool => $carry && $trial->cached, TRUE);
    $line = sprintf('%s %s %s %s (pass_rate %s, %d/%d trials)%s', $skill, $task->task, $model->alias, $passed ? 'PASS' : 'FAIL', number_format($model->passRate(), 2), $passing, count($model->trials), $cached ? ' (cached)' : '');

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

}
