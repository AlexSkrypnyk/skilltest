<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Results\Report;

use AlexSkrypnyk\SkillTest\Config\Data;

/**
 * Shared grid arithmetic for one skill's task-by-model matrix.
 *
 * Both the terminal and HTML reports draw the same grid - the models a skill
 * ran across the top, the tasks down the side, a pass-rate in each cell - and
 * the same minimal-model verdict line beneath it. The column ordering, the
 * missing-cell dash, and the verdict wording live here once so the two
 * renderers cannot drift apart; each one only decides how to lay the values
 * out.
 */
final class MatrixGrid {

  /**
   * The model columns across a skill's tasks, in first-seen order.
   *
   * @param array<int, array<mixed>> $tasks
   *   The skill's llm tasks.
   *
   * @return string[]
   *   The ordered, de-duplicated model column labels.
   */
  public static function columns(array $tasks): array {
    $columns = [];

    foreach ($tasks as $task) {
      foreach (Data::toArrayList(Data::get($task, 'models')) as $model) {
        $label = self::alias($model);
        $columns[$label] = $label;
      }
    }

    return array_values($columns);
  }

  /**
   * One task's pass-rate cells, aligned to the given columns.
   *
   * @param array<mixed> $task
   *   One llm task entry.
   * @param string[] $columns
   *   The model columns to align to.
   *
   * @return string[]
   *   The cell for each column: a percentage, or a dash when the task did not
   *   run on that model.
   */
  public static function cells(array $task, array $columns): array {
    $rates = self::rates($task);

    return array_map(static fn(string $column): string => $rates[$column] ?? '-', $columns);
  }

  /**
   * Each model column of a task mapped to its rendered pass-rate cell.
   *
   * @param array<mixed> $task
   *   One llm task entry.
   *
   * @return array<string, string>
   *   The pass-rate cell keyed by model column label.
   */
  public static function rates(array $task): array {
    $rates = [];

    foreach (Data::toArrayList(Data::get($task, 'models')) as $model) {
      $rate = Data::toFloatOrNull(Data::get($model, 'pass_rate'));
      $rates[self::alias($model)] = $rate === NULL ? '-' : ((int) round($rate * 100)) . '%';
    }

    return $rates;
  }

  /**
   * The minimal-model verdict line for one skill.
   *
   * @param array<mixed> $skill
   *   One skill entry.
   *
   * @return string
   *   The verdict line.
   */
  public static function verdictLine(array $skill): string {
    $minimal = Data::toStringOrNull(Data::get($skill, 'llm', 'verdict', 'minimal_model'));
    $threshold = Data::toFloatOrNull(Data::get($skill, 'llm', 'verdict', 'threshold')) ?? 0.0;
    $trials = Data::toIntOrNull(Data::get($skill, 'llm', 'verdict', 'trials')) ?? 0;

    $bar = sprintf('threshold %s, %d trial(s)', number_format($threshold, 2), $trials);

    if ($minimal === NULL || $minimal === '') {
      return sprintf('no minimal model (%s)', $bar);
    }

    return sprintf('minimal model: %s (%s)', $minimal, $bar);
  }

  /**
   * The display alias for a model row, falling back to its id.
   *
   * @param array<mixed> $model
   *   The model row.
   *
   * @return string
   *   The alias or id.
   */
  protected static function alias(array $model): string {
    return Data::toStringOrNull(Data::get($model, 'alias')) ?? Data::toStringOrNull(Data::get($model, 'model')) ?? '';
  }

}
