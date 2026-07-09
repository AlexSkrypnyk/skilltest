<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live\Matrix;

use AlexSkrypnyk\SkillTest\Judge\UnknownPolicy;
use AlexSkrypnyk\SkillTest\Live\ModelOutcome;
use AlexSkrypnyk\SkillTest\Live\SkillOutcome;

/**
 * One skill's matrix: a grid row per ladder model and the minimal verdict.
 *
 * Every task of the skill runs the same ladder in the same order, so a model
 * occupies the same position in each task; this folds those positions into one
 * grid row per model and carries the skill's minimal-model verdict beside them.
 * A failing model also carries its failure modes, so the report can say not
 * just that a model failed but why. The rows are in ladder order, weakest
 * first.
 */
final readonly class SkillMatrix {

  /**
   * Constructs a SkillMatrix.
   *
   * @param string $skill
   *   The skill name.
   * @param string $path
   *   The skill directory, relative to the repo root.
   * @param \AlexSkrypnyk\SkillTest\Live\Matrix\MatrixModelRow[] $rows
   *   The per-model grid rows, in ladder order.
   * @param string|null $minimal
   *   The minimal supporting model alias, or NULL when no model supports the
   *   skill.
   * @param float $threshold
   *   The pass-rate threshold the verdict was measured against.
   * @param int $trials
   *   The trials each model ran per task.
   * @param array<string, \AlexSkrypnyk\SkillTest\Live\Matrix\MatrixFailureModes> $failureModes
   *   The failure modes of each failing model, keyed by alias.
   */
  public function __construct(
    public string $skill,
    public string $path,
    public array $rows,
    public ?string $minimal,
    public float $threshold,
    public int $trials,
    public array $failureModes,
  ) {}

  /**
   * Builds a skill matrix from a live skill outcome.
   *
   * @param \AlexSkrypnyk\SkillTest\Live\SkillOutcome $skill
   *   The skill's live outcome.
   *
   * @return self
   *   The per-skill matrix.
   */
  public static function fromOutcome(SkillOutcome $skill): self {
    $rows = [];
    $failure_modes = [];
    $policy = UnknownPolicy::fromConfig($skill->judgeUnknown);
    $positions = $skill->tasks === [] ? 0 : count($skill->tasks[0]->models);

    for ($position = 0; $position < $positions; $position++) {
      $models = [];

      foreach ($skill->tasks as $task) {
        $model = $task->models[$position] ?? NULL;

        if ($model instanceof ModelOutcome) {
          $models[] = $model;
        }
      }

      if ($models === []) {
        // @codeCoverageIgnoreStart
        continue;
        // @codeCoverageIgnoreEnd
      }

      $row = MatrixModelRow::fromModels($models);
      $rows[] = $row;

      if (!$row->passed) {
        $failure_modes[$row->alias] = MatrixFailureModes::fromModels($models, $skill->rubric, $policy);
      }
    }

    return new self($skill->skill, $skill->path, $rows, $skill->minimalModel(), $skill->threshold, $skill->trials, $failure_modes);
  }

  /**
   * The grid row for a model alias, or NULL when the skill did not run it.
   *
   * @param string $alias
   *   The model alias.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\Matrix\MatrixModelRow|null
   *   The row, or NULL.
   */
  public function row(string $alias): ?MatrixModelRow {
    foreach ($this->rows as $row) {
      if ($row->alias === $alias) {
        return $row;
      }
    }

    return NULL;
  }

}
