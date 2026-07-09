<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live\Matrix;

use AlexSkrypnyk\SkillTest\Live\LlmReport;

/**
 * The whole matrix run as a report: per-skill grids, the repo grid, and costs.
 *
 * Turns a live run into the multi-model answer machine's output: each skill's
 * grid and minimal-model verdict, the repo-level grid that answers "which of my
 * skills are Haiku-safe", and the cost totals that turn the price of the
 * exercise into a number. The repo grid's columns are the models that actually
 * ran, in first-seen (ladder) order, so a skill that stopped early or ran a
 * narrower model list simply leaves the unrun columns blank rather than forcing
 * a run it never made. The default model is carried so a skill's verdict can be
 * priced against it.
 */
final readonly class MatrixReport {

  /**
   * Constructs a MatrixReport.
   *
   * @param \AlexSkrypnyk\SkillTest\Live\Matrix\SkillMatrix[] $skills
   *   The per-skill matrices, in discovery order.
   * @param string|null $defaultModel
   *   The repo default model alias, or NULL when none is configured; the model
   *   a skill's minimal-model verdict is priced against.
   */
  public function __construct(
    public array $skills,
    public ?string $defaultModel,
  ) {}

  /**
   * Builds a matrix report from a live run.
   *
   * @param \AlexSkrypnyk\SkillTest\Live\LlmReport $report
   *   The live run outcome.
   * @param string|null $default_model
   *   The repo default model alias, or NULL.
   *
   * @return self
   *   The matrix report.
   */
  public static function fromReport(LlmReport $report, ?string $default_model): self {
    return new self(array_map(SkillMatrix::fromOutcome(...), $report->skills), $default_model);
  }

  /**
   * The model columns that ran, in first-seen (ladder) order.
   *
   * @return string[]
   *   The ordered model aliases.
   */
  public function columns(): array {
    $columns = [];

    foreach ($this->skills as $skill) {
      foreach ($skill->rows as $row) {
        if (!in_array($row->alias, $columns, TRUE)) {
          $columns[] = $row->alias;
        }
      }
    }

    return $columns;
  }

  /**
   * The repo-level grid: a skill per row, its per-model pass rate, and minimal.
   *
   * @return array<int, string[]>
   *   One row per skill: the skill name, each column's rounded pass rate (or
   *   `-` when the skill did not run that model), then the minimal model.
   */
  public function repoGrid(): array {
    $columns = $this->columns();
    $grid = [];

    foreach ($this->skills as $skill) {
      $cells = [$skill->skill];

      foreach ($columns as $column) {
        $row = $skill->row($column);
        $cells[] = $row instanceof MatrixModelRow ? $row->rate() : '-';
      }

      $cells[] = $skill->minimal ?? '-';
      $grid[] = $cells;
    }

    return $grid;
  }

  /**
   * The summed cost of every trial on each model, keyed by alias.
   *
   * @return array<string, float>
   *   The total cost per model, in first-seen order.
   */
  public function costPerModel(): array {
    $costs = [];

    foreach ($this->skills as $skill) {
      foreach ($skill->rows as $row) {
        $costs[$row->alias] = round(($costs[$row->alias] ?? 0.0) + $row->cost, 4);
      }
    }

    return $costs;
  }

  /**
   * The summed cost across every model and skill.
   *
   * @return float
   *   The total cost in USD.
   */
  public function totalCost(): float {
    return round(array_sum($this->costPerModel()), 4);
  }

}
