<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use AlexSkrypnyk\SkillTest\Coverage\Coverage;
use AlexSkrypnyk\SkillTest\Coverage\CoverageRow;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\ExitCode;
use AlexSkrypnyk\SkillTest\Validation\ConfigValidator;
use AlexSkrypnyk\SkillTest\Validation\ValidationMessage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Coverage command.
 *
 * Renders the skill-to-eval coverage grid - which skills have an `eval.yaml`,
 * a transcript fixture, and llm tasks - in text, markdown, or JSON, and
 * enforces the coverage gate. A discovered skill with no `eval.yaml` that is
 * not excluded fails with exit 1; a configuration error such as an exclusion
 * with no reason fails with exit 2.
 */
class CoverageCommand extends Command {

  /**
   * The supported output formats.
   */
  public const array FORMATS = ['text', 'markdown', 'json'];

  /**
   * The grid column headers.
   */
  protected const array HEADERS = ['Skill', 'Eval', 'Transcript', 'Tasks', 'Status', 'Reason'];

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setName('coverage')
      ->setDescription('Render the skill-to-eval coverage grid and enforce the coverage gate')
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
    }
    catch (ConfigException $config_exception) {
      $message = ValidationMessage::error($config_exception->configFile(), $config_exception->pointer(), $config_exception->getMessage());

      return $this->reportErrors($output, $format, [$message]);
    }

    $result = (new ConfigValidator($root))->validate($loaded);

    if ($result->hasErrors()) {
      return $this->reportErrors($output, $format, $result->errors());
    }

    $coverage = new Coverage($loaded);

    if ($format === 'json') {
      $output->writeln($this->renderJson($coverage));
    }
    else {
      $this->renderTable($output, $coverage, $format);
    }

    return $coverage->violations() === [] ? ExitCode::PASS : ExitCode::FAIL;
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
        'skills' => [],
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
   * Renders the coverage grid and summary as a single JSON document.
   *
   * @param \AlexSkrypnyk\SkillTest\Coverage\Coverage $coverage
   *   The computed coverage.
   *
   * @return string
   *   The JSON document.
   */
  protected function renderJson(Coverage $coverage): string {
    return $this->encode([
      'ok' => $coverage->violations() === [],
      'skills' => array_map(static fn(CoverageRow $row): array => $row->toArray(), $coverage->rows),
      'summary' => $coverage->summary(),
    ]);
  }

  /**
   * Renders the coverage grid, summary, and any violation lines as a table.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param \AlexSkrypnyk\SkillTest\Coverage\Coverage $coverage
   *   The computed coverage.
   * @param string $format
   *   Either `text` or `markdown`.
   */
  protected function renderTable(OutputInterface $output, Coverage $coverage, string $format): void {
    $matrix = array_map($this->cells(...), $coverage->rows);

    if ($format === 'markdown') {
      $this->renderMarkdown($output, $matrix);
    }
    else {
      $this->renderText($output, $matrix);
    }

    $summary = $coverage->summary();
    $output->writeln('');
    $output->writeln(sprintf('%d skill(s): %d covered, %d excluded, %d uncovered.', $summary['total'], $summary['covered'], $summary['excluded'], $summary['uncovered']));

    foreach ($coverage->violations() as $violation) {
      $output->writeln(sprintf("coverage: skill '%s' has no eval.yaml and is not excluded (%s).", $violation->skill, $violation->path));
    }
  }

  /**
   * Maps a coverage row to its display cells.
   *
   * @param \AlexSkrypnyk\SkillTest\Coverage\CoverageRow $row
   *   The row.
   *
   * @return string[]
   *   The cells, aligned with HEADERS.
   */
  protected function cells(CoverageRow $row): array {
    return array_map($this->flatten(...), [
      $row->skill,
      $row->eval ? 'yes' : 'no',
      $row->transcript ? 'yes' : 'no',
      (string) $row->tasks,
      $row->status(),
      $row->reason ?? '',
    ]);
  }

  /**
   * Collapses newlines to spaces so a free-text cell stays on its own row.
   *
   * The skill name and exclusion reason are author-controlled, so a newline in
   * either would otherwise split a single grid row across lines.
   *
   * @param string $value
   *   The cell value.
   *
   * @return string
   *   The single-line value.
   */
  protected function flatten(string $value): string {
    return str_replace(["\r\n", "\r", "\n"], ' ', $value);
  }

  /**
   * Renders an aligned plain-text table.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param array<int, string[]> $matrix
   *   The body rows.
   */
  protected function renderText(OutputInterface $output, array $matrix): void {
    $widths = $this->columnWidths($matrix);

    $output->writeln($this->textRow(self::HEADERS, $widths));

    foreach ($matrix as $cells) {
      $output->writeln($this->textRow($cells, $widths));
    }
  }

  /**
   * Computes each column's width from the headers and body.
   *
   * @param array<int, string[]> $matrix
   *   The body rows.
   *
   * @return int[]
   *   The column widths.
   */
  protected function columnWidths(array $matrix): array {
    $widths = array_map(strlen(...), self::HEADERS);

    foreach ($matrix as $cells) {
      foreach ($cells as $column => $cell) {
        $widths[$column] = max($widths[$column], strlen($cell));
      }
    }

    return $widths;
  }

  /**
   * Renders one padded, space-separated text row.
   *
   * @param string[] $cells
   *   The cells.
   * @param int[] $widths
   *   The column widths.
   *
   * @return string
   *   The rendered row, without trailing whitespace.
   */
  protected function textRow(array $cells, array $widths): string {
    $padded = [];

    foreach ($cells as $column => $cell) {
      $padded[] = str_pad($cell, $widths[$column]);
    }

    return rtrim(implode('  ', $padded));
  }

  /**
   * Renders a markdown pipe table.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param array<int, string[]> $matrix
   *   The body rows.
   */
  protected function renderMarkdown(OutputInterface $output, array $matrix): void {
    $output->writeln('| ' . implode(' | ', self::HEADERS) . ' |');
    $output->writeln('| ' . implode(' | ', array_fill(0, count(self::HEADERS), '---')) . ' |');

    foreach ($matrix as $cells) {
      $escaped = array_map(static fn(string $cell): string => str_replace('|', '\\|', $cell), $cells);
      $output->writeln('| ' . implode(' | ', $escaped) . ' |');
    }
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
