<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\ExitCode;
use AlexSkrypnyk\SkillTest\Live\AgentPreflight;
use AlexSkrypnyk\SkillTest\Live\HostEnvironment;
use AlexSkrypnyk\SkillTest\Live\Lifecycle;
use AlexSkrypnyk\SkillTest\Live\LlmSuite;
use AlexSkrypnyk\SkillTest\Live\Matrix\MatrixPlan;
use AlexSkrypnyk\SkillTest\Live\Matrix\MatrixRenderer;
use AlexSkrypnyk\SkillTest\Live\Matrix\MatrixReport;
use AlexSkrypnyk\SkillTest\Render\Table;
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
 * The `matrix` command: run the ladder and report the minimal model per skill.
 *
 * The multi-model answer machine. It runs the same live suite the `llm`
 * command runs, but across the whole model ladder weakest first, and renders
 * the model matrix: each skill's per-model grid, the minimal-model verdict
 * ("the smallest model whose pass rate meets the threshold"), the per-model
 * failure modes, the repo-level grid across skills, and the cost totals ending
 * with the per-run cost difference between the minimal model and the repo
 * default. Unlike `llm` it is a report, not a gate, so it exits 0 whatever the
 * verdicts; a configuration error still exits 2. `--stop-at-pass` climbs only
 * until the first supporting model, and `--estimate` prints the plan and its
 * rough price without running anything - so it needs neither credentials nor a
 * token to answer "how big is this run".
 */
class MatrixCommand extends Command {

  use LiveOptionsTrait;

  /**
   * The supported human output formats.
   */
  public const array FORMATS = ['text', 'markdown'];

  /**
   * The default trials per model per task, applied when nothing else sets it.
   */
  protected const int DEFAULT_TRIALS = 3;

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
      ->setName('matrix')
      ->setDescription('Run the model ladder and report the minimal model per skill')
      ->addOption(name: 'dir', mode: InputOption::VALUE_REQUIRED, description: 'Repository root (default: current directory)')
      ->addOption(name: 'skill', mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, description: 'Select skills by name glob (repeatable)')
      ->addOption(name: 'task', mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, description: 'Select tasks by name glob (repeatable)')
      ->addOption(name: 'models', mode: InputOption::VALUE_REQUIRED, description: 'Override the ladder (aliases or ids, comma-separated)')
      ->addOption(name: 'trials', mode: InputOption::VALUE_REQUIRED, description: 'Trials per model per task (default 3)')
      ->addOption(name: 'threshold', mode: InputOption::VALUE_REQUIRED, description: 'Override the pass-rate threshold (0..1)')
      ->addOption(name: 'env', mode: InputOption::VALUE_REQUIRED, description: 'Execution environment: host (docker not yet supported)')
      ->addOption(name: 'parallel', mode: InputOption::VALUE_REQUIRED, description: 'Number of concurrent trials (default 1)')
      ->addOption(name: 'judge-model', mode: InputOption::VALUE_REQUIRED, description: 'Override the judge model (alias or id); the judge model never follows the ladder')
      ->addOption(name: 'stop-at-pass', mode: InputOption::VALUE_NONE, description: 'Stop climbing the ladder at the first passing model (cheaper, no full matrix)')
      ->addOption(name: 'estimate', mode: InputOption::VALUE_NONE, description: 'Print the plan (skills x tasks x trials x models) and a rough cost, then exit without running')
      ->addOption(name: 'format', mode: InputOption::VALUE_REQUIRED, description: 'Human output format: text or markdown', default: 'text')
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
    $format = $this->stringOption($input, 'format') ?? 'text';
    $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

    if (!in_array($format, self::FORMATS, TRUE)) {
      return $this->reportErrors($output, $stderr, $json, [ValidationMessage::error('', '', sprintf('unknown format; expected one of: %s.', implode(', ', self::FORMATS)))]);
    }

    try {
      $selection = RunSelection::create($this->globs($input, 'skill'), NULL, NULL);
      $overrides = $this->overridesFrom($input, self::OVERRIDES);
      $overrides['default-trials'] = (string) self::DEFAULT_TRIALS;
      $loaded = (new ConfigLoader($root))->load($overrides);
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

    $filtered = $selection->filter($loaded);

    if ($filtered->skills === []) {
      $message = $selection->globs === [] ? 'no skills found under the configured skills paths.' : sprintf('no skills matched --skill %s.', implode(', ', $selection->globs));

      return $this->reportErrors($output, $stderr, $json, [ValidationMessage::error('', '', $message)]);
    }

    $task_globs = $this->globs($input, 'task');

    // An estimate spends no tokens and needs no agent, so it answers before any
    // preflight - the cost of a run can be sized without credentials.
    if ((bool) $input->getOption('estimate')) {
      $this->renderEstimate($output, MatrixPlan::fromConfig($filtered, $task_globs), $json);

      return ExitCode::PASS;
    }

    $parallel_option = $this->stringOption($input, 'parallel');
    $parallel = $parallel_option === NULL ? 1 : $this->intOption($input, 'parallel');

    if ($parallel === NULL) {
      return $this->reportErrors($output, $stderr, $json, [ValidationMessage::error('', '', '--parallel must be an integer.')]);
    }

    if ($parallel < 1) {
      return $this->reportErrors($output, $stderr, $json, [ValidationMessage::error('', '', '--parallel must be at least 1.')]);
    }

    $environment = $this->stringOption($input, 'env') ?? $loaded->repo->environment;

    if ($environment === 'docker') {
      return $this->reportErrors($output, $stderr, $json, [ValidationMessage::error('', '', 'the docker environment is not yet implemented; run with --env host.')]);
    }

    $preflight = new AgentPreflight($this->environmentMap());
    $problem = $preflight->problem();

    if ($problem !== NULL) {
      return $this->reportErrors($output, $stderr, $json, [ValidationMessage::error('', '', $problem)]);
    }

    $binary = (string) $preflight->binary();
    $stop_at_pass = (bool) $input->getOption('stop-at-pass');

    try {
      $host = new HostEnvironment($root, $parallel, $this->timeout());
      $lifecycle = new Lifecycle($root, $loaded->repo->lifecycle, NULL, $this->warn($stderr));
      $report = (new LlmSuite($root, $binary, $host, $lifecycle, $parallel, $this->timeout()))->run($filtered, $task_globs, $stop_at_pass);
    }
    catch (ConfigException $config_exception) {
      return $this->reportErrors($output, $stderr, $json, [$this->toMessage($config_exception)]);
    }

    $document = $report->toResults(Version::RESULTS_SCHEMA_VERSION, ['name' => Version::NAME, 'version' => Version::id()], [
      'id' => 'st-' . date('Ymd-His'),
      'started' => $started_at,
      'duration_ms' => (int) round((microtime(TRUE) - $started) * 1000),
      'command' => 'matrix',
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
      if ($stop_at_pass) {
        $output->writeln('stop-at-pass: climbed to the first supporting model per skill.');
      }

      foreach ((new MatrixRenderer(MatrixReport::fromReport($report, $loaded->repo->defaultModel)))->render($format) as $line) {
        $output->writeln($line);
      }
    }

    return ExitCode::PASS;
  }

  /**
   * Renders the estimate plan as a JSON object or a human table.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param \AlexSkrypnyk\SkillTest\Live\Matrix\MatrixPlan $plan
   *   The computed plan.
   * @param bool $json
   *   Whether the JSON output contract is in effect.
   */
  protected function renderEstimate(OutputInterface $output, MatrixPlan $plan, bool $json): void {
    if ($json) {
      $output->writeln($this->encode([
        'estimate' => TRUE,
        'skills' => $plan->skills,
        'total_trials' => $plan->totalTrials,
        'rough_cost_usd' => $plan->roughCost(),
      ]), OutputInterface::VERBOSITY_QUIET);

      return;
    }

    $output->writeln('matrix plan (nothing runs with --estimate):');

    $rows = array_map(static fn(array $skill): array => [$skill['skill'], (string) $skill['tasks'], (string) $skill['models'], (string) $skill['trials'], (string) $skill['total']], $plan->skills);

    foreach (Table::text(['skill', 'tasks', 'models', 'trials', 'total'], $rows) as $line) {
      $output->writeln('  ' . $line);
    }

    $output->writeln(sprintf('  total trials: %d', $plan->totalTrials));
    $output->writeln(sprintf('  rough cost: ~$%s (nominal $%s/trial; actual cost is measured per run)', number_format($plan->roughCost(), 2), number_format(MatrixPlan::NOMINAL_COST_PER_TRIAL, 2)));
  }

}
