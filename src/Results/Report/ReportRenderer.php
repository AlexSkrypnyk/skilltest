<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Results\Report;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Render\Table;
use AlexSkrypnyk\SkillTest\Results\Metrics;

/**
 * Renders a saved results document as a terminal summary.
 *
 * The counterpart to the HTML report for a reader who just wants the shape of a
 * run at the command line: a status line, the headline totals, the ordered
 * failures (each with the evidence needed to act on it), and - when the run
 * carried llm results - the per-skill matrix grid with its minimal-model
 * verdict and the cost totals. Every number is read through `Metrics`, so the
 * summary never disagrees with what the run recorded.
 */
final readonly class ReportRenderer {

  /**
   * Renders the document as terminal lines.
   *
   * @param array<string, mixed> $document
   *   The results document.
   *
   * @return string[]
   *   The rendered lines.
   */
  public function text(array $document): array {
    $aggregate = Metrics::aggregate($document);
    $failures = Metrics::failures($document);

    $lines = [$this->status($document, $aggregate, $failures === []), ''];
    $lines = array_merge($lines, $this->summary($aggregate));

    if ($failures !== []) {
      $lines[] = '';
      $lines[] = 'failures';
      $lines = array_merge($lines, array_map(fn(array $finding): string => '  ' . $this->failureLine($finding), $failures));
    }

    if ($this->hasLlm($document)) {
      $lines[] = '';
      $lines = array_merge($lines, $this->matrix($document));
      $lines[] = '';
      $lines[] = $this->cost($document);
    }

    return $lines;
  }

  /**
   * Builds the status line naming the run and its verdict.
   *
   * @param array<string, mixed> $document
   *   The results document.
   * @param array<string, int|float> $aggregate
   *   The aggregate figures.
   * @param bool $passed
   *   Whether nothing failed.
   *
   * @return string
   *   The status line.
   */
  protected function status(array $document, array $aggregate, bool $passed): string {
    $id = Data::toStringOrNull(Data::get($document, 'run', 'id')) ?? '';
    $command = Data::toStringOrNull(Data::get($document, 'run', 'command')) ?? '';
    $environment = Data::toStringOrNull(Data::get($document, 'run', 'environment')) ?? '';

    $verdict = $passed ? sprintf('PASS - all %d check(s) passed', $aggregate['checks']) : sprintf('FAIL - %d of %d check(s) failed', $aggregate['failures'], $aggregate['checks']);

    return sprintf('%s (run %s, %s, %s)', $verdict, $id, $command, $environment);
  }

  /**
   * Builds the summary lines: counts, and trials, tokens, and cost when spent.
   *
   * @param array<string, int|float> $aggregate
   *   The aggregate figures.
   *
   * @return string[]
   *   The summary lines.
   */
  protected function summary(array $aggregate): array {
    $lines = [
      sprintf('checks: %d', $aggregate['checks']),
      sprintf('passed: %d', $aggregate['passed']),
      sprintf('failed: %d', $aggregate['failures']),
    ];

    if ($aggregate['trials'] > 0) {
      $lines[] = sprintf('trials: %d', $aggregate['trials']);
    }

    if ($aggregate['tokens_in'] + $aggregate['tokens_out'] > 0) {
      $lines[] = sprintf('tokens: %d in / %d out', $aggregate['tokens_in'], $aggregate['tokens_out']);
      $lines[] = sprintf('cost: $%s', number_format((float) $aggregate['cost_usd'], 4));
    }

    return $lines;
  }

  /**
   * Renders one finding as a single line.
   *
   * @param array<string, mixed> $finding
   *   The finding from Metrics::failures().
   *
   * @return string
   *   The rendered line.
   */
  protected function failureLine(array $finding): string {
    $scope = Data::toStringOrNull(Data::get($finding, 'scope')) ?? '';

    if ($finding['kind'] === 'llm') {
      $rate = (int) round((Data::toFloatOrNull(Data::get($finding, 'pass_rate')) ?? 0.0) * 100);
      $threshold = (int) round((Data::toFloatOrNull(Data::get($finding, 'threshold')) ?? 0.0) * 100);

      return sprintf('%s (%s) %s on %s: %d%% < %d%%', Data::toStringOrNull(Data::get($finding, 'task')) ?? '', $scope, 'llm', Data::toStringOrNull(Data::get($finding, 'model')) ?? '', $rate, $threshold);
    }

    $id = Data::toStringOrNull(Data::get($finding, 'id')) ?? '';
    $detail = Data::toStringOrNull(Data::get($finding, 'message')) ?: (Data::toStringOrNull(Data::get($finding, 'label')) ?? '');
    $evidence = Data::toStringOrNull(Data::get($finding, 'evidence')) ?? '';

    $line = sprintf('%s (%s)', $id, $scope);
    $line = $detail === '' ? $line : $line . ' - ' . $detail;

    return $evidence === '' ? $line : sprintf('%s [%s]', $line, $evidence);
  }

  /**
   * Renders the per-skill matrix grid and minimal-model verdict.
   *
   * @param array<string, mixed> $document
   *   The results document.
   *
   * @return string[]
   *   The matrix lines.
   */
  protected function matrix(array $document): array {
    $lines = ['matrix'];

    foreach (Data::toArrayList(Data::get($document, 'skills')) as $skill) {
      $tasks = Data::toArrayList(Data::get($skill, 'llm', 'tasks'));

      if ($tasks === []) {
        continue;
      }

      $name = Data::toStringOrNull(Data::get($skill, 'skill')) ?? '';
      $columns = MatrixGrid::columns($tasks);
      $rows = array_map(static fn(array $task): array => [Data::toStringOrNull(Data::get($task, 'task')) ?? '', ...MatrixGrid::cells($task, $columns)], $tasks);

      $lines[] = '  ' . $name;
      $lines = array_merge($lines, array_map(static fn(string $line): string => '    ' . $line, Table::text(['task', ...$columns], $rows)));
      $lines[] = '    ' . MatrixGrid::verdictLine($skill);
    }

    return $lines;
  }

  /**
   * Renders the per-model and total cost line.
   *
   * @param array<string, mixed> $document
   *   The results document.
   *
   * @return string
   *   The cost line.
   */
  protected function cost(array $document): string {
    $parts = [];
    $total = 0.0;

    foreach (Metrics::perModel($document) as $alias => $model) {
      $cost = (float) $model['cost_usd'];
      $total += $cost;
      $parts[] = sprintf('%s $%s', $alias, number_format($cost, 4));
    }

    $summary = $parts === [] ? 'no cost recorded' : implode(', ', $parts);

    return sprintf('cost per model: %s. total $%s.', $summary, number_format($total, 4));
  }

  /**
   * Whether any skill carried llm tasks.
   *
   * @param array<string, mixed> $document
   *   The results document.
   *
   * @return bool
   *   TRUE when at least one skill ran llm tasks.
   */
  protected function hasLlm(array $document): bool {
    foreach (Data::toArrayList(Data::get($document, 'skills')) as $skill) {
      if (Data::toArrayList(Data::get($skill, 'llm', 'tasks')) !== []) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
