<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Coverage\CoverageRow;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\ExitCode;
use AlexSkrypnyk\SkillTest\Run\Redactor;
use AlexSkrypnyk\SkillTest\Run\ResultsWriter;
use AlexSkrypnyk\SkillTest\Run\RunPlan;
use AlexSkrypnyk\SkillTest\Run\RunReport;
use AlexSkrypnyk\SkillTest\Run\RunSelection;
use AlexSkrypnyk\SkillTest\Run\RunSuite;
use AlexSkrypnyk\SkillTest\Run\SkillRunResult;
use AlexSkrypnyk\SkillTest\Security\SecurityFinding;
use AlexSkrypnyk\SkillTest\Structure\StructureResult;
use AlexSkrypnyk\SkillTest\Validation\ConfigValidator;
use AlexSkrypnyk\SkillTest\Validation\ValidationMessage;
use AlexSkrypnyk\SkillTest\Version;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run command.
 *
 * The default command and the CI gate: runs the deterministic suite -
 * `structure`, `security`, and `transcript` per selected skill, `hooks` once
 * at repo level - plus the coverage gate, with no network, no model, and no
 * tokens anywhere in the path. Any failing check exits 1; a configuration
 * error (malformed or incoherent YAML, an impossible selection, no skills
 * found) exits 2 before any check runs. Results go to stdout; diagnostics go
 * to stderr. Symfony's `--quiet` verbosity flag narrows output to failure
 * lines only, and `--json` emits the machine-readable results document and
 * nothing else.
 */
class RunCommand extends Command {

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setName('run')
      ->setDescription('Run the deterministic suite (structure, security, hooks, transcript) and the coverage gate')
      ->addOption(name: 'dir', mode: InputOption::VALUE_REQUIRED, description: 'Repository root (default: current directory)')
      ->addOption(name: 'skill', mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, description: 'Select skills by name glob (repeatable)')
      ->addOption(name: 'group', mode: InputOption::VALUE_REQUIRED, description: 'Run one group only: structure, security, hooks, or transcript')
      ->addOption(name: 'check', mode: InputOption::VALUE_REQUIRED, description: 'Run one check id only')
      ->addOption(name: 'list', mode: InputOption::VALUE_NONE, description: 'List selected skills and the checks that would run, without running')
      ->addOption(name: 'json', mode: InputOption::VALUE_NONE, description: 'Emit the machine-readable results document on stdout and nothing else')
      ->addOption(name: 'output', mode: InputOption::VALUE_REQUIRED, description: 'Persist the results document to this file')
      ->addOption(name: 'output-dir', mode: InputOption::VALUE_REQUIRED, description: 'Persist the results document to a timestamped subdirectory of this directory, with artifacts');
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

    try {
      $selection = $this->selection($input);
      $loaded = (new ConfigLoader($root))->load();
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

    if ($filtered->skills === [] && $filtered->skillsWithoutEval === []) {
      $message = $selection->globs === [] ? 'no skills found under the configured skills paths.' : sprintf('no skills matched --skill %s.', implode(', ', $selection->globs));

      return $this->reportErrors($output, $stderr, $json, [ValidationMessage::error('', '', $message)]);
    }

    if ((bool) $input->getOption('list')) {
      $this->renderPlan($output, $filtered, $selection, $json);

      return ExitCode::PASS;
    }

    try {
      $report = (new RunSuite($root))->run($filtered, $selection);
    }
    catch (ConfigException $config_exception) {
      return $this->reportErrors($output, $stderr, $json, [$this->toMessage($config_exception)]);
    }

    if ($selection->check !== NULL && $report->checks() === 0) {
      return $this->reportErrors($output, $stderr, $json, [ValidationMessage::error('', '', sprintf("check '%s' matched nothing in this run; verify the id with --list.", $selection->check))]);
    }

    $output_file = $this->stringOption($input, 'output');
    $output_dir = $this->stringOption($input, 'output-dir');

    if ($json || $output_file !== NULL || $output_dir !== NULL) {
      $document = $report->toResults(Version::RESULTS_SCHEMA_VERSION, ['name' => Version::NAME, 'version' => Version::id()], [
        'id' => 'st-' . date('Ymd-His'),
        'started' => $started_at,
        'duration_ms' => (int) round((microtime(TRUE) - $started) * 1000),
        'command' => 'run',
        'environment' => $filtered->repo->environment,
      ]);

      if ($output_file !== NULL || $output_dir !== NULL) {
        $this->persist($filtered, $stderr, $document, $output_file, $output_dir);
      }

      if ($json) {
        $output->writeln($this->encode($document), OutputInterface::VERBOSITY_QUIET);
      }
    }

    if (!$json) {
      $this->renderReport($output, $filtered, $selection, $report);
    }

    return $report->failed() ? ExitCode::FAIL : ExitCode::PASS;
  }

  /**
   * Builds the validated selection from the command input.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   *
   * @return \AlexSkrypnyk\SkillTest\Run\RunSelection
   *   The validated selection.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the requested group or check is impossible.
   */
  protected function selection(InputInterface $input): RunSelection {
    $raw_globs = $input->getOption('skill');
    $globs = array_values(array_filter(is_array($raw_globs) ? $raw_globs : [], static fn(mixed $glob): bool => is_string($glob) && $glob !== ''));

    $group = $input->getOption('group');
    $check = $input->getOption('check');

    return RunSelection::create($globs, is_string($group) && $group !== '' ? $group : NULL, is_string($check) && $check !== '' ? $check : NULL);
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
   * Persists the results document, redacted, to the requested destinations.
   *
   * Redaction is on unless `report.redact` is explicitly false, in which case a
   * loud warning is forced to stderr because secrets may then reach disk. The
   * redactor reads the process environment so a credential exported for the run
   * never lands in a persisted artifact.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $filtered
   *   The selected configuration, carrying the repo `report` block.
   * @param \Symfony\Component\Console\Output\OutputInterface $stderr
   *   The error output for the disabled-redaction warning and write notices.
   * @param array<string, mixed> $document
   *   The results document to persist.
   * @param string|null $file
   *   The `--output` file destination, when set.
   * @param string|null $dir
   *   The `--output-dir` parent directory, when set.
   */
  protected function persist(LoadedConfig $filtered, OutputInterface $stderr, array $document, ?string $file, ?string $dir): void {
    $redact = Data::toBoolOrNull(Data::get($filtered->repo->report, 'redact')) ?? TRUE;

    if (!$redact) {
      $stderr->writeln('WARNING redaction disabled (report.redact: false); environment secrets may be written to persisted artifacts.', OutputInterface::VERBOSITY_QUIET);
    }

    $writer = new ResultsWriter(Redactor::fromEnvironment((array) getenv(), $redact));

    if ($dir !== NULL) {
      $stderr->writeln(sprintf('results written to %s', $writer->writeDir($document, $dir, gmdate('Ymd-His'))));
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
   * Human-readable errors are diagnostics, so they go to stderr; under
   * `--json` the error document is the machine-readable result and goes to
   * stdout. Both are forced past a quiet verbosity, because an unexplained
   * exit 2 is not debuggable.
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
   * Renders the `--list` plan without running anything.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $filtered
   *   The selected configuration.
   * @param \AlexSkrypnyk\SkillTest\Run\RunSelection $selection
   *   The validated selection.
   * @param bool $json
   *   Whether to emit the plan as JSON.
   */
  protected function renderPlan(OutputInterface $output, LoadedConfig $filtered, RunSelection $selection, bool $json): void {
    $plan = (new RunPlan($filtered, $selection))->describe();

    if ($json) {
      $output->writeln($this->encode(['plan' => $plan]), OutputInterface::VERBOSITY_QUIET);

      return;
    }

    $output->writeln(sprintf('plan: %d skill(s); groups: %s; coverage gate: %s', count($plan['skills']), implode(', ', $plan['groups']), $plan['coverage'] ? 'on' : 'off'));

    foreach ($plan['skills'] as $skill_plan) {
      $output->writeln(sprintf('%s (%s)', $skill_plan['skill'], $skill_plan['path']));

      foreach ($skill_plan['groups'] as $group => $lines) {
        $output->writeln(sprintf('  %s: %s', $group, $lines === [] ? '(none)' : implode(', ', $lines)));
      }
    }

    if ($plan['hooks'] !== []) {
      $output->writeln('repo');
      $output->writeln('  hooks: ' . implode(', ', $plan['hooks']));
    }
  }

  /**
   * Renders the human report: group status lines, failures, and totals.
   *
   * In quiet verbosity only the failure lines print, forced past the
   * verbosity gate, so a green run is silent and a red run names exactly
   * what failed.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $filtered
   *   The selected configuration.
   * @param \AlexSkrypnyk\SkillTest\Run\RunSelection $selection
   *   The validated selection.
   * @param \AlexSkrypnyk\SkillTest\Run\RunReport $report
   *   The run outcome.
   */
  protected function renderReport(OutputInterface $output, LoadedConfig $filtered, RunSelection $selection, RunReport $report): void {
    $quiet = $output->isQuiet();

    foreach ($report->skills as $skill) {
      $this->renderSkill($output, $selection, $skill, $quiet);
    }

    if ($selection->runs(RunSelection::GROUP_HOOKS) && $filtered->repo->hooks !== []) {
      $failed = count(array_filter($report->hooks, static fn(CheckResult $result): bool => !$result->pass));
      $this->groupLine($output, 'repo', 'hooks', $failed === 0, $failed === 0 ? sprintf('%d case(s)', count($report->hooks)) : sprintf('%d of %d case(s) failed', $failed, count($report->hooks)), $quiet);
      $this->failureLines($output, array_map(self::checkLine(...), array_filter($report->hooks, static fn(CheckResult $result): bool => !$result->pass)), $quiet);
    }

    if ($selection->coverageGateRuns()) {
      $subject = count($report->skills) + count($filtered->skillsWithoutEval);
      $this->groupLine($output, 'repo', 'coverage', $report->coverage === [], $report->coverage === [] ? sprintf('%d skill(s)', $subject) : sprintf('%d violation(s)', count($report->coverage)), $quiet);
      $this->failureLines($output, array_map(self::coverageLine(...), $report->coverage), $quiet);
    }

    if (!$quiet) {
      $checks = $report->checks();
      $failures = $report->failures();
      $suppressed = $report->suppressed();
      $skills = count($report->skills) + ($selection->coverageGateRuns() ? count($filtered->skillsWithoutEval) : 0);
      $output->writeln('');
      $output->writeln(sprintf('%d check(s) across %d skill(s): %d passed, %d failed, %d suppressed.', $checks, $skills, $checks - $failures - $suppressed, $failures, $suppressed));
    }
  }

  /**
   * Renders one skill's group status lines and notable results.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param \AlexSkrypnyk\SkillTest\Run\RunSelection $selection
   *   The validated selection.
   * @param \AlexSkrypnyk\SkillTest\Run\SkillRunResult $skill
   *   The skill's results.
   * @param bool $quiet
   *   Whether only failure lines should print.
   */
  protected function renderSkill(OutputInterface $output, RunSelection $selection, SkillRunResult $skill, bool $quiet): void {
    if ($selection->runs(RunSelection::GROUP_STRUCTURE)) {
      $failed = count(array_filter($skill->structure, static fn(StructureResult $result): bool => $result->failed()));
      $this->groupLine($output, $skill->skill, 'structure', $failed === 0, $failed === 0 ? sprintf('%d check(s)', count($skill->structure)) : sprintf('%d of %d check(s) failed', $failed, count($skill->structure)), $quiet);

      $notable = array_filter($skill->structure, static fn(StructureResult $result): bool => $result->status !== StructureResult::PASS);
      $failures = array_filter($skill->structure, static fn(StructureResult $result): bool => $result->failed());
      $lines = array_map(static fn(StructureResult $result): string => $result->render(), $quiet ? $failures : $notable);
      $this->failureLines($output, $lines, $quiet);
    }

    if ($selection->runs(RunSelection::GROUP_SECURITY)) {
      $this->groupLine($output, $skill->skill, 'security', $skill->security === [], $skill->security === [] ? '' : sprintf('%d finding(s)', count($skill->security)), $quiet);
      $this->failureLines($output, array_map(static fn(SecurityFinding $finding): string => $finding->render(), $skill->security), $quiet);
    }

    if ($selection->runs(RunSelection::GROUP_TRANSCRIPT)) {
      if ($skill->transcriptNote !== '') {
        if (!$quiet) {
          $output->writeln(sprintf('%s transcript SKIP (%s)', $skill->skill, $skill->transcriptNote));
        }
      }
      else {
        $failed = count(array_filter($skill->transcript, static fn(CheckResult $result): bool => !$result->pass));
        $this->groupLine($output, $skill->skill, 'transcript', $failed === 0, $failed === 0 ? sprintf('%d check(s)', count($skill->transcript)) : sprintf('%d of %d check(s) failed', $failed, count($skill->transcript)), $quiet);
        $this->failureLines($output, array_map(self::checkLine(...), array_filter($skill->transcript, static fn(CheckResult $result): bool => !$result->pass)), $quiet);
      }
    }
  }

  /**
   * Renders one group status line, suppressed entirely in quiet mode.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param string $subject
   *   The skill name, or `repo` for repo-level groups.
   * @param string $group
   *   The group name.
   * @param bool $pass
   *   Whether the group passed.
   * @param string $detail
   *   The parenthesised detail, or an empty string for none.
   * @param bool $quiet
   *   Whether only failure lines should print.
   */
  protected function groupLine(OutputInterface $output, string $subject, string $group, bool $pass, string $detail, bool $quiet): void {
    if ($quiet) {
      return;
    }

    $line = sprintf('%s %s %s', $subject, $group, $pass ? 'PASS' : 'FAIL');
    $output->writeln($detail === '' ? $line : sprintf('%s (%s)', $line, $detail));
  }

  /**
   * Renders expanded result lines: indented normally, bare and forced quiet.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param string[] $lines
   *   The pre-rendered result lines.
   * @param bool $quiet
   *   Whether only failure lines should print.
   */
  protected function failureLines(OutputInterface $output, array $lines, bool $quiet): void {
    foreach ($lines as $line) {
      if ($quiet) {
        $output->writeln($line, OutputInterface::VERBOSITY_QUIET);
      }
      else {
        $output->writeln('  ' . $line);
      }
    }
  }

  /**
   * Renders a failed contract, custom-check, or hook result as one line.
   *
   * @param \AlexSkrypnyk\SkillTest\Contract\CheckResult $result
   *   The failed result.
   *
   * @return string
   *   The rendered line.
   */
  protected static function checkLine(CheckResult $result): string {
    $line = sprintf('%s FAIL - %s', $result->id, $result->message);

    return $result->evidence === '' ? $line : sprintf('%s [%s]', $line, $result->evidence);
  }

  /**
   * Renders a coverage-gate violation as one line.
   *
   * @param \AlexSkrypnyk\SkillTest\Coverage\CoverageRow $row
   *   The violating coverage row.
   *
   * @return string
   *   The rendered line.
   */
  protected static function coverageLine(CoverageRow $row): string {
    return sprintf('%s FAIL %s - %s', RunReport::COVERAGE_CHECK, $row->path, RunReport::coverageMessage($row));
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
