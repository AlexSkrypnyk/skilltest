<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Results;

/**
 * One task's cross-model verdict as read back from a saved results document.
 *
 * A task is the unit gating and drift reason about: it carries the skill it
 * belongs to, the per-model pass verdict recomputed from the stored trials
 * against the skill's threshold (never the rounded display rate), and the
 * combined verdict a golden-task check consults - a task passes only when it ran
 * on at least one model and every model met the bar.
 */
final readonly class TaskView {

  /**
   * Constructs a TaskView.
   *
   * @param string $skill
   *   The skill the task belongs to.
   * @param string $task
   *   The task name.
   * @param array<string, bool> $modelPassed
   *   Whether each model met the threshold, keyed by model alias, in ladder
   *   order.
   */
  public function __construct(
    public string $skill,
    public string $task,
    public array $modelPassed,
  ) {}

  /**
   * The stable key identifying this task across two documents.
   *
   * @param string $skill
   *   The skill name.
   * @param string $task
   *   The task name.
   *
   * @return string
   *   The composite key.
   */
  public static function key(string $skill, string $task): string {
    return $skill . "\t" . $task;
  }

  /**
   * Whether the task passed on every model it ran on.
   *
   * @return bool
   *   TRUE when there is at least one model and all of them met the threshold.
   */
  public function passed(): bool {
    if ($this->modelPassed === []) {
      return FALSE;
    }

    foreach ($this->modelPassed as $passed) {
      if (!$passed) {
        return FALSE;
      }
    }

    return TRUE;
  }

}
