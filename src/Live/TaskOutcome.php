<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

/**
 * One task's outcome across every model it was run on.
 *
 * A task is the unit an author writes and a threshold gates: it passes only
 * when every model it ran on met the threshold, so a task that holds on the
 * strong model but not the weak one is not silently green. The per-model
 * breakdown is preserved for the report and the model-matrix verdict.
 */
final readonly class TaskOutcome {

  /**
   * Constructs a TaskOutcome.
   *
   * @param string $task
   *   The task name.
   * @param \AlexSkrypnyk\SkillTest\Live\ModelOutcome[] $models
   *   The per-model outcomes, in model order.
   */
  public function __construct(
    public string $task,
    public array $models,
  ) {}

  /**
   * Whether the task passed on every model it ran on.
   *
   * @return bool
   *   TRUE when there is at least one model and all of them met the threshold.
   */
  public function passed(): bool {
    foreach ($this->models as $model) {
      if (!$model->passed()) {
        return FALSE;
      }
    }

    return $this->models !== [];
  }

  /**
   * Renders the task as a results-document row.
   *
   * @return array<string, mixed>
   *   The task row matching the results schema.
   */
  public function toArray(): array {
    return [
      'task' => $this->task,
      'models' => array_map(static fn(ModelOutcome $model): array => $model->toArray(), $this->models),
    ];
  }

}
