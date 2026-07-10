<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Config\SchemaVersion;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\ExitCode;
use AlexSkrypnyk\SkillTest\Gate\Gate;
use AlexSkrypnyk\SkillTest\Gate\GateOptions;
use AlexSkrypnyk\SkillTest\Gate\GateRenderer;
use AlexSkrypnyk\SkillTest\Results\ResultsDocument;
use AlexSkrypnyk\SkillTest\Results\ResultsException;
use AlexSkrypnyk\SkillTest\Results\TaskView;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The `gate` command: fail a run that regressed against a committed baseline.
 *
 * The nightly counterpart to the free push gate. It never spends a token - it
 * reads two already-produced `results.json` files and applies policy: the
 * pass rate may not drop beyond `--max-regression`; a golden task must
 * keep passing regardless of the aggregate; a skill's minimal model may not
 * climb the ladder; and the task set may not drift beyond the configured
 * allow/warn/fail policy. Golden tasks come from `eval.yaml` in `--dir` on a
 * best-effort basis, so the command still works as a pure two-file comparison
 * when no repo is present. It renders in four formats and mirrors the tool exit
 * contract: `0` pass, `1` regression/golden failure, `2` configuration error.
 */
class GateCommand extends Command {

  use ResultsCommandTrait;

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setName('gate')
      ->setDescription('Compare a run against a committed baseline and fail on regression')
      ->addOption(name: 'current', mode: InputOption::VALUE_REQUIRED, description: 'The current results.json to gate')
      ->addOption(name: 'baseline', mode: InputOption::VALUE_REQUIRED, description: 'The committed baseline results.json to compare against')
      ->addOption(name: 'max-regression', mode: InputOption::VALUE_REQUIRED, description: 'Tolerated aggregate pass-rate drop, in percentage points (default 0)')
      ->addOption(name: 'on-new-tasks', mode: InputOption::VALUE_REQUIRED, description: 'Policy for tasks new since the baseline: allow, warn, or fail (default warn)')
      ->addOption(name: 'on-removed-tasks', mode: InputOption::VALUE_REQUIRED, description: 'Policy for tasks removed since the baseline: allow, warn, or fail (default warn)')
      ->addOption(name: 'format', mode: InputOption::VALUE_REQUIRED, description: 'Output format: human, json, markdown, or github-actions', default: 'human')
      ->addOption(name: 'dir', mode: InputOption::VALUE_REQUIRED, description: 'Repository root, for reading golden tasks from eval.yaml (default: current directory)');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $stderr = $this->stderr($output);

    $format = $this->stringOption($input, 'format') ?? 'human';

    if (!in_array($format, GateRenderer::FORMATS, TRUE)) {
      return $this->configError($stderr, sprintf('unknown format; expected one of: %s.', implode(', ', GateRenderer::FORMATS)));
    }

    $current_file = $this->stringOption($input, 'current');
    $baseline_file = $this->stringOption($input, 'baseline');

    if ($current_file === NULL) {
      return $this->configError($stderr, 'the --current option is required.');
    }

    if ($baseline_file === NULL) {
      return $this->configError($stderr, 'the --baseline option is required.');
    }

    [$options, $errors] = GateOptions::parse($this->stringOption($input, 'max-regression'), $this->stringOption($input, 'on-new-tasks'), $this->stringOption($input, 'on-removed-tasks'));

    if ($options === NULL) {
      foreach ($errors as $error) {
        $stderr->writeln('ERROR ' . $error, OutputInterface::VERBOSITY_QUIET);
      }

      return ExitCode::CONFIG_ERROR;
    }

    try {
      $current = $this->load($current_file, 'current');
      $baseline = $this->load($baseline_file, 'baseline');
    }
    catch (ResultsException $results_exception) {
      return $this->configError($stderr, $results_exception->getMessage());
    }

    $golden = $this->goldenKeys($this->resolveRoot($input), $stderr);

    $report = (new Gate())->compare($current, $baseline, $options, $golden);

    $output->writeln((new GateRenderer())->render($report, $format), OutputInterface::VERBOSITY_QUIET);

    return $report->failed() ? ExitCode::FAIL : ExitCode::PASS;
  }

  /**
   * Loads a results file and rejects a foreign-major schema.
   *
   * @param string $file
   *   The results file path.
   * @param string $role
   *   The role of the file (`current` or `baseline`), for the error message.
   *
   * @return \AlexSkrypnyk\SkillTest\Results\ResultsDocument
   *   The parsed document.
   *
   * @throws \AlexSkrypnyk\SkillTest\Results\ResultsException
   *   When the file is missing, unparseable, or a foreign schema major.
   */
  protected function load(string $file, string $role): ResultsDocument {
    $document = ResultsDocument::fromFile($file);

    if (!$document->isCurrentMajor()) {
      throw new ResultsException(sprintf("the %s results file is schema version '%s'; this tool reads major %d (run 'skilltest migrate').", $role, $document->version(), SchemaVersion::CURRENT_MAJOR));
    }

    return $document;
  }

  /**
   * Collects the golden task keys declared in the repo config, best-effort.
   *
   * The config is only consulted for the golden set, so a repo that will not
   * load is a warning, not a fatal error - the gate still compares the two
   * files. A task is golden when its `eval.yaml` entry sets `golden: true`.
   *
   * @param string $root
   *   The repository root to load config from.
   * @param \Symfony\Component\Console\Output\OutputInterface $stderr
   *   The error output for the best-effort warning.
   *
   * @return string[]
   *   The golden task keys ({@see TaskView::key}).
   */
  protected function goldenKeys(string $root, OutputInterface $stderr): array {
    try {
      $loaded = (new ConfigLoader($root))->load();
    }
    catch (ConfigException $config_exception) {
      $stderr->writeln('WARNING could not load config for golden tasks; comparing files only: ' . $config_exception->getMessage());

      return [];
    }

    $keys = [];

    foreach ($loaded->skills as $skill) {
      foreach ($skill->effective->tasks as $task) {
        if (Data::toBoolOrNull(Data::get($task, 'golden')) !== TRUE) {
          continue;
        }

        $name = Data::toStringOrNull(Data::get($task, 'name'));

        if ($name !== NULL && $name !== '') {
          $keys[] = TaskView::key($skill->effective->skill, $name);
        }
      }
    }

    return $keys;
  }

}
