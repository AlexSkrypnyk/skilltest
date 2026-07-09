<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\ExitCode;
use AlexSkrypnyk\SkillTest\Structure\StructureChecker;
use AlexSkrypnyk\SkillTest\Structure\StructureResult;
use AlexSkrypnyk\SkillTest\Validation\ValidationMessage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Structure command.
 *
 * Runs the deterministic `structure` group: pre-baked, default-on checks that
 * prove each skill's files are well-formed, internally consistent, and honest
 * about what they reference. Any failing check fails the gate with exit 1;
 * warnings (advisories and warn thresholds) are always listed but never
 * affect the exit code. A hard configuration error such as malformed YAML, or
 * a `commands.resolve` binary that cannot run, fails with exit 2. Coherence
 * of each skill's own `eval.yaml` surfaces as the per-skill
 * `structure.contract-coherent` check rather than aborting the run, so one
 * incoherent skill does not mask the rest.
 */
class StructureCommand extends Command {

  /**
   * The supported output formats.
   */
  public const array FORMATS = ['text', 'markdown', 'json'];

  /**
   * The results-table column headers.
   */
  protected const array HEADERS = ['Check', 'Skill', 'Status', 'Detail', 'Evidence'];

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setName('structure')
      ->setDescription("Check that every skill's files are well-formed and honest (the structure group)")
      ->addOption(name: 'dir', mode: InputOption::VALUE_REQUIRED, description: 'Repository root (default: current directory)')
      ->addOption(name: 'format', mode: InputOption::VALUE_REQUIRED, description: 'Output format: text, markdown, or json', default: 'text');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $root = $this->resolveRoot($input);
    $format = $input->getOption('format');

    if (!is_string($format) || !in_array($format, self::FORMATS, TRUE)) {
      $output->writeln(sprintf('ERROR unknown format; expected one of: %s.', implode(', ', self::FORMATS)));

      return ExitCode::CONFIG_ERROR;
    }

    try {
      $loaded = (new ConfigLoader($root))->load();
      $results = (new StructureChecker($root))->check($loaded);
    }
    catch (ConfigException $config_exception) {
      $message = ValidationMessage::error($config_exception->configFile(), $config_exception->pointer(), $config_exception->getMessage());

      return $this->reportErrors($output, $format, [$message]);
    }

    $skills = count($loaded->skills);

    if ($format === 'json') {
      $output->writeln($this->renderJson($results, $skills));
    }
    else {
      $this->renderReport($output, $results, $skills, $format);
    }

    return $this->failed($results) ? ExitCode::FAIL : ExitCode::PASS;
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
   * Reports configuration errors in the requested format and returns exit 2.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param string $format
   *   The output format.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationMessage[] $errors
   *   The errors to report.
   *
   * @return int
   *   The config-error exit code.
   */
  protected function reportErrors(OutputInterface $output, string $format, array $errors): int {
    if ($format === 'json') {
      $payload = [
        'ok' => FALSE,
        'results' => [],
        'errors' => array_map(static fn(ValidationMessage $message): array => $message->toArray(), $errors),
      ];
      $output->writeln($this->encode($payload));
    }
    else {
      foreach ($errors as $error) {
        $output->writeln('ERROR ' . $error->render());
      }
    }

    return ExitCode::CONFIG_ERROR;
  }

  /**
   * Renders the results and summary as a single JSON document.
   *
   * @param \AlexSkrypnyk\SkillTest\Structure\StructureResult[] $results
   *   The results.
   * @param int $skills
   *   The number of skills checked.
   *
   * @return string
   *   The JSON document.
   */
  protected function renderJson(array $results, int $skills): string {
    return $this->encode([
      'ok' => !$this->failed($results),
      'results' => array_map(static fn(StructureResult $result): array => $result->toArray(), $results),
      'summary' => $this->summary($results, $skills),
    ]);
  }

  /**
   * Renders the non-passing results, then the summary line.
   *
   * Passing checks are counted in the summary but not listed, so the report
   * draws the eye to what needs attention: failures and suppressions.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param \AlexSkrypnyk\SkillTest\Structure\StructureResult[] $results
   *   The results.
   * @param int $skills
   *   The number of skills checked.
   * @param string $format
   *   Either `text` or `markdown`.
   */
  protected function renderReport(OutputInterface $output, array $results, int $skills, string $format): void {
    $notable = array_values(array_filter($results, static fn(StructureResult $result): bool => $result->status !== StructureResult::PASS));

    if ($notable !== []) {
      if ($format === 'markdown') {
        $this->renderMarkdown($output, $notable);
      }
      else {
        foreach ($notable as $result) {
          $output->writeln($result->render());
        }
      }

      $output->writeln('');
    }

    $output->writeln($this->summaryLine($results, $skills));
  }

  /**
   * Renders the notable results as a markdown pipe table.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param \AlexSkrypnyk\SkillTest\Structure\StructureResult[] $results
   *   The notable (failed or suppressed) results.
   */
  protected function renderMarkdown(OutputInterface $output, array $results): void {
    $output->writeln('| ' . implode(' | ', self::HEADERS) . ' |');
    $output->writeln('| ' . implode(' | ', array_fill(0, count(self::HEADERS), '---')) . ' |');

    foreach ($results as $result) {
      $location = $result->file === '' ? '' : sprintf('%s:%d', $result->file, $result->line);
      $cells = [$result->check, $result->skill, $result->status, $result->message, $result->evidence === '' ? $location : $result->evidence];
      $escaped = array_map($this->escape(...), $cells);
      $output->writeln('| ' . implode(' | ', $escaped) . ' |');
    }
  }

  /**
   * Whether any result fails the gate.
   *
   * @param \AlexSkrypnyk\SkillTest\Structure\StructureResult[] $results
   *   The results.
   *
   * @return bool
   *   TRUE when at least one result failed.
   */
  protected function failed(array $results): bool {
    foreach ($results as $result) {
      if ($result->failed()) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Builds the machine-readable summary counts.
   *
   * @param \AlexSkrypnyk\SkillTest\Structure\StructureResult[] $results
   *   The results.
   * @param int $skills
   *   The number of skills checked.
   *
   * @return array{checks: int, skills: int, passed: int, failed: int, warned: int, suppressed: int}
   *   The summary counts.
   */
  protected function summary(array $results, int $skills): array {
    return [
      'checks' => count($results),
      'skills' => $skills,
      'passed' => $this->countStatus($results, StructureResult::PASS),
      'failed' => $this->countStatus($results, StructureResult::FAIL),
      'warned' => $this->countStatus($results, StructureResult::WARN),
      'suppressed' => $this->countStatus($results, StructureResult::SUPPRESSED),
    ];
  }

  /**
   * Builds the human-readable summary line.
   *
   * @param \AlexSkrypnyk\SkillTest\Structure\StructureResult[] $results
   *   The results.
   * @param int $skills
   *   The number of skills checked.
   *
   * @return string
   *   The summary line.
   */
  protected function summaryLine(array $results, int $skills): string {
    $summary = $this->summary($results, $skills);

    return sprintf('%d check(s) across %d skill(s): %d passed, %d failed, %d warned, %d suppressed.', $summary['checks'], $summary['skills'], $summary['passed'], $summary['failed'], $summary['warned'], $summary['suppressed']);
  }

  /**
   * Counts the results with a given status.
   *
   * @param \AlexSkrypnyk\SkillTest\Structure\StructureResult[] $results
   *   The results.
   * @param string $status
   *   The status to count.
   *
   * @return int
   *   The number of results with the status.
   */
  protected function countStatus(array $results, string $status): int {
    return count(array_filter($results, static fn(StructureResult $result): bool => $result->status === $status));
  }

  /**
   * Escapes a cell so a pipe or newline cannot break the markdown table.
   *
   * @param string $value
   *   The cell value.
   *
   * @return string
   *   The escaped, single-line value.
   */
  protected function escape(string $value): string {
    $single_line = str_replace(["\r\n", "\r", "\n"], ' ', $value);

    return str_replace('|', '\\|', $single_line);
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
