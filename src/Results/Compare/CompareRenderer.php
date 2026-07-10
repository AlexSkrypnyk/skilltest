<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Results\Compare;

use AlexSkrypnyk\SkillTest\Render\Table;

/**
 * Renders a comparison as aligned terminal tables, one section at a time.
 *
 * The aggregate section always prints; the per-model and per-task sections
 * appear only when the compared runs carried llm results, so a deterministic
 * comparison is not padded with empty grids. Each row shows the value from
 * every document and the baseline-to-latest delta, signed so the direction of
 * a change is legible at a glance and a null delta (a figure missing from one
 * side) reads as a dash rather than a fabricated zero.
 */
final readonly class CompareRenderer {

  /**
   * The metrics rendered as a percentage.
   */
  protected const array PERCENT_METRICS = ['pass_rate'];

  /**
   * The metrics rendered as a USD amount.
   */
  protected const array MONEY_METRICS = ['cost_usd'];

  /**
   * Constructs a CompareRenderer.
   *
   * @param \AlexSkrypnyk\SkillTest\Results\Compare\Comparison $comparison
   *   The comparison to render.
   */
  public function __construct(
    protected Comparison $comparison,
  ) {}

  /**
   * Renders the whole comparison as terminal lines.
   *
   * @return string[]
   *   The rendered lines.
   */
  public function text(): array {
    $lines = ['compare: ' . implode(' -> ', $this->comparison->labels), ''];

    $lines = array_merge($lines, $this->section('aggregate', 'metric', $this->aggregateRows()), ['']);

    $model_rows = $this->modelRows();

    if ($model_rows !== []) {
      $lines = array_merge($lines, $this->section('models', 'model', $model_rows), ['']);
    }

    $task_rows = $this->taskRows();

    if ($task_rows !== []) {
      $lines = array_merge($lines, $this->section('tasks', 'task', $task_rows), ['']);
    }

    return array_slice($lines, 0, -1);
  }

  /**
   * Renders one heading and its indented grid.
   *
   * @param string $heading
   *   The section heading.
   * @param string $first_header
   *   The header for the row-label column.
   * @param array<int, string[]> $rows
   *   The grid rows.
   *
   * @return string[]
   *   The section lines.
   */
  protected function section(string $heading, string $first_header, array $rows): array {
    $headers = [$first_header, ...$this->comparison->labels, 'delta'];
    $grid = array_map(static fn(string $line): string => '  ' . $line, Table::text($headers, $rows));

    return [$heading, ...$grid];
  }

  /**
   * Builds the aggregate grid rows.
   *
   * @return array<int, string[]>
   *   The rows.
   */
  protected function aggregateRows(): array {
    $rows = [];

    foreach ($this->comparison->aggregate as $metric => $series) {
      $rows[] = $this->row($metric, $metric, $series);
    }

    return $rows;
  }

  /**
   * Builds the per-model grid rows, one per model-and-metric pair.
   *
   * @return array<int, string[]>
   *   The rows.
   */
  protected function modelRows(): array {
    $rows = [];

    foreach ($this->comparison->models as $alias => $metrics) {
      foreach ($metrics as $metric => $series) {
        $rows[] = $this->row($metric, $alias . ' ' . $metric, $series);
      }
    }

    return $rows;
  }

  /**
   * Builds the per-task grid rows.
   *
   * @return array<int, string[]>
   *   The rows.
   */
  protected function taskRows(): array {
    $rows = [];

    foreach ($this->comparison->tasks as $key => $series) {
      $rows[] = $this->row('pass_rate', $key, $series);
    }

    return $rows;
  }

  /**
   * Builds one grid row: the label, each value, and the signed delta.
   *
   * @param string $metric
   *   The metric name, deciding the value and delta formatting.
   * @param string $label
   *   The row label.
   * @param array{values: array<int, int|float|null>, delta: int|float|null} $series
   *   The series.
   *
   * @return string[]
   *   The row cells.
   */
  protected function row(string $metric, string $label, array $series): array {
    $cells = array_map(fn(int|float|null $value): string => $this->value($metric, $value), $series['values']);

    return [$label, ...$cells, $this->delta($metric, $series['delta'])];
  }

  /**
   * Formats one value for its metric.
   *
   * @param string $metric
   *   The metric name.
   * @param int|float|null $value
   *   The value, or NULL when the metric is absent from that document.
   *
   * @return string
   *   The formatted value, or a dash when absent.
   */
  protected function value(string $metric, int|float|null $value): string {
    if ($value === NULL) {
      return '-';
    }

    if (in_array($metric, self::PERCENT_METRICS, TRUE)) {
      return ((int) round((float) $value * 100)) . '%';
    }

    if (in_array($metric, self::MONEY_METRICS, TRUE)) {
      return '$' . number_format((float) $value, 4);
    }

    return (string) (int) $value;
  }

  /**
   * Formats one delta for its metric, signed and unit-aware.
   *
   * @param string $metric
   *   The metric name.
   * @param int|float|null $delta
   *   The delta, or NULL when it cannot be computed.
   *
   * @return string
   *   The formatted, signed delta, or a dash when absent.
   */
  protected function delta(string $metric, int|float|null $delta): string {
    if ($delta === NULL) {
      return '-';
    }

    if (in_array($metric, self::PERCENT_METRICS, TRUE)) {
      $points = (int) round((float) $delta * 100);

      return $this->sign($points) . $points . '%';
    }

    if (in_array($metric, self::MONEY_METRICS, TRUE)) {
      $prefix = $delta > 0 ? '+$' : ($delta < 0 ? '-$' : '$');

      return $prefix . number_format(abs((float) $delta), 4);
    }

    $whole = (int) $delta;

    return $this->sign($whole) . $whole;
  }

  /**
   * The leading sign for a non-negative number ('+' or ''), '' for negatives.
   *
   * A negative number already prints its own minus sign, so only a positive one
   * needs a '+' prefix; zero renders unsigned.
   *
   * @param int $number
   *   The number.
   *
   * @return string
   *   The sign prefix.
   */
  protected function sign(int $number): string {
    return $number > 0 ? '+' : '';
  }

}
