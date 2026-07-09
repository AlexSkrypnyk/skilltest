<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

/**
 * One skill's live outcome: its tasks and the minimal-model verdict over them.
 *
 * The verdict answers the cost question the matrix exists for - the weakest
 * model, in ladder order, on which every one of the skill's tasks still passes.
 * A skill with no such model reports a null verdict rather than pretending the
 * cheapest one works. The threshold and trial count travel with the verdict so
 * a reader knows the bar the answer was measured against.
 */
final readonly class SkillOutcome {

  /**
   * Constructs a SkillOutcome.
   *
   * @param string $skill
   *   The skill name.
   * @param string $path
   *   The skill directory, relative to the repository root.
   * @param \AlexSkrypnyk\SkillTest\Live\TaskOutcome[] $tasks
   *   The per-task outcomes, in declaration order.
   * @param float $threshold
   *   The pass-rate threshold the tasks were gated against.
   * @param int $trials
   *   The number of trials each model ran per task.
   * @param string[] $rubric
   *   The judge rubric criteria, in id order, empty when the skill declares no
   *   rubric; carried so a failure-mode report can name a criterion by text.
   * @param string $judgeUnknown
   *   The judge abstention policy (`fail` or `ignore`) a criterion is measured
   *   against when deciding whether it blocked a trial.
   */
  public function __construct(
    public string $skill,
    public string $path,
    public array $tasks,
    public float $threshold,
    public int $trials,
    public array $rubric = [],
    public string $judgeUnknown = 'fail',
  ) {}

  /**
   * The weakest model, in ladder order, on which every task passed.
   *
   * @return string|null
   *   The alias of the minimal passing model, or NULL when no model passes
   *   every task or the skill declares no tasks.
   */
  public function minimalModel(): ?string {
    if ($this->tasks === []) {
      return NULL;
    }

    foreach ($this->tasks[0]->models as $index => $candidate) {
      if ($this->modelPassesEveryTask($index)) {
        return $candidate->alias;
      }
    }

    return NULL;
  }

  /**
   * Whether the model at a ladder position passed in every task.
   *
   * @param int $index
   *   The model's position in each task's model list.
   *
   * @return bool
   *   TRUE when every task's model at that position met the threshold.
   */
  protected function modelPassesEveryTask(int $index): bool {
    foreach ($this->tasks as $task) {
      $model = $task->models[$index] ?? NULL;

      if (!$model instanceof ModelOutcome || !$model->passed()) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Renders the skill as a results-document row.
   *
   * @return array<string, mixed>
   *   The skill row matching the results schema.
   */
  public function toArray(): array {
    return [
      'skill' => $this->skill,
      'path' => $this->path,
      'llm' => [
        'tasks' => array_map(static fn(TaskOutcome $task): array => $task->toArray(), $this->tasks),
        'verdict' => [
          'minimal_model' => $this->minimalModel(),
          'threshold' => $this->threshold,
          'trials' => $this->trials,
        ],
      ],
    ];
  }

}
