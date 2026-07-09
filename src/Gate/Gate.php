<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Gate;

use AlexSkrypnyk\SkillTest\Results\ResultsDocument;
use AlexSkrypnyk\SkillTest\Results\TaskView;

/**
 * Compares a current run against a baseline and applies the gate policy.
 *
 * The policy engine behind `skilltest gate`. It answers four independent
 * questions and folds them into one verdict: did the aggregate pass rate drop
 * beyond tolerance; did a golden task stop passing (which fails the gate no
 * matter the aggregate); did a skill's minimal model climb the ladder (a cost
 * regression is a decision, not an accident); and did the task set drift, held
 * to the configured allow/warn/fail policy. It reads both runs through the same
 * {@see ResultsDocument} model, so the numbers it gates on are the numbers every
 * report shows.
 */
final readonly class Gate {

  /**
   * Compares a current run against a baseline under a policy.
   *
   * @param \AlexSkrypnyk\SkillTest\Results\ResultsDocument $current
   *   The current run.
   * @param \AlexSkrypnyk\SkillTest\Results\ResultsDocument $baseline
   *   The committed baseline.
   * @param \AlexSkrypnyk\SkillTest\Gate\GateOptions $options
   *   The regression tolerance and drift policy.
   * @param string[] $golden_keys
   *   The golden task keys ({@see TaskView::key}) that must pass in the current
   *   run.
   *
   * @return \AlexSkrypnyk\SkillTest\Gate\GateReport
   *   The findings and verdict.
   */
  public function compare(ResultsDocument $current, ResultsDocument $baseline, GateOptions $options, array $golden_keys): GateReport {
    $baseline_rate = $baseline->passRate();
    $current_rate = $current->passRate();

    $findings = array_merge(
      $this->regressionFindings($baseline_rate, $current_rate, $options->maxRegression),
      $this->goldenFindings($current, $golden_keys),
      $this->minimalModelFindings($current, $baseline),
      $this->driftFindings($current, $baseline, $options),
    );

    return new GateReport($baseline_rate, $current_rate, $options->maxRegression, $findings);
  }

  /**
   * The aggregate regression finding, when the drop exceeds tolerance.
   *
   * @param float $baseline_rate
   *   The baseline pass rate.
   * @param float $current_rate
   *   The current pass rate.
   * @param float $max_regression
   *   The tolerated drop, in percentage points.
   *
   * @return \AlexSkrypnyk\SkillTest\Gate\GateFinding[]
   *   The single regression finding, or an empty list.
   */
  protected function regressionFindings(float $baseline_rate, float $current_rate, float $max_regression): array {
    $drop = ($baseline_rate - $current_rate) * 100;

    if ($drop <= $max_regression) {
      return [];
    }

    return [GateFinding::fail('regression', sprintf('aggregate pass rate dropped %s points (%s%% -> %s%%), beyond the %s allowed.', Format::number($drop), Format::number($baseline_rate * 100), Format::number($current_rate * 100), Format::number($max_regression)))];
  }

  /**
   * The golden-task findings: each golden task must pass in the current run.
   *
   * @param \AlexSkrypnyk\SkillTest\Results\ResultsDocument $current
   *   The current run.
   * @param string[] $golden_keys
   *   The golden task keys.
   *
   * @return \AlexSkrypnyk\SkillTest\Gate\GateFinding[]
   *   One finding per absent or failing golden task.
   */
  protected function goldenFindings(ResultsDocument $current, array $golden_keys): array {
    $tasks = $current->tasks();
    $findings = [];

    foreach ($golden_keys as $key) {
      $view = $tasks[$key] ?? NULL;

      if (!$view instanceof TaskView) {
        $findings[] = GateFinding::fail('golden', sprintf("golden task '%s' is absent from the current run.", self::keyLabel($key)));

        continue;
      }

      if (!$view->passed()) {
        $findings[] = GateFinding::fail('golden', sprintf("golden task '%s' did not pass in the current run.", self::keyLabel($key)));
      }
    }

    return $findings;
  }

  /**
   * The minimal-model findings: a skill whose minimal model climbed the ladder.
   *
   * @param \AlexSkrypnyk\SkillTest\Results\ResultsDocument $current
   *   The current run.
   * @param \AlexSkrypnyk\SkillTest\Results\ResultsDocument $baseline
   *   The baseline run.
   *
   * @return \AlexSkrypnyk\SkillTest\Gate\GateFinding[]
   *   One finding per skill whose minimal model became more expensive.
   */
  protected function minimalModelFindings(ResultsDocument $current, ResultsDocument $baseline): array {
    $current_minimals = $current->skillMinimalModels();
    $baseline_minimals = $baseline->skillMinimalModels();
    $current_ladders = $current->skillLadders();
    $baseline_ladders = $baseline->skillLadders();

    $findings = [];

    foreach ($current_minimals as $skill => $current_min) {
      if (!array_key_exists($skill, $baseline_minimals)) {
        continue;
      }

      $baseline_min = $baseline_minimals[$skill];
      $ladder = self::mergeLadder($current_ladders[$skill] ?? [], $baseline_ladders[$skill] ?? []);

      if (self::ladderIndex($ladder, $current_min) > self::ladderIndex($ladder, $baseline_min)) {
        $findings[] = GateFinding::fail('minimal-model', sprintf("minimal model for '%s' climbed the ladder %s -> %s.", $skill, self::modelLabel($baseline_min), self::modelLabel($current_min)));
      }
    }

    return $findings;
  }

  /**
   * The task-set drift findings, held to the configured policy.
   *
   * @param \AlexSkrypnyk\SkillTest\Results\ResultsDocument $current
   *   The current run.
   * @param \AlexSkrypnyk\SkillTest\Results\ResultsDocument $baseline
   *   The baseline run.
   * @param \AlexSkrypnyk\SkillTest\Gate\GateOptions $options
   *   The drift policy.
   *
   * @return \AlexSkrypnyk\SkillTest\Gate\GateFinding[]
   *   The drift findings, added tasks before removed tasks.
   */
  protected function driftFindings(ResultsDocument $current, ResultsDocument $baseline, GateOptions $options): array {
    $current_keys = array_keys($current->tasks());
    $baseline_keys = array_keys($baseline->tasks());

    $added = array_values(array_diff($current_keys, $baseline_keys));
    $removed = array_values(array_diff($baseline_keys, $current_keys));

    return array_merge(
      $this->drift($added, $options->newTasks, 'new-task', 'is new (absent from the baseline run)'),
      $this->drift($removed, $options->removedTasks, 'removed-task', 'was removed (absent from the current run)'),
    );
  }

  /**
   * Renders one side of the drift under its policy.
   *
   * @param string[] $keys
   *   The drifted task keys.
   * @param string $policy
   *   The policy: `allow`, `warn`, or `fail`.
   * @param string $category
   *   The finding category.
   * @param string $reason
   *   The trailing clause describing the drift.
   *
   * @return \AlexSkrypnyk\SkillTest\Gate\GateFinding[]
   *   One finding per drifted task, or an empty list under `allow`.
   */
  protected function drift(array $keys, string $policy, string $category, string $reason): array {
    if ($policy === 'allow') {
      return [];
    }

    $findings = [];

    foreach ($keys as $key) {
      $message = sprintf("task '%s' %s.", self::keyLabel($key), $reason);
      $findings[] = $policy === 'fail' ? GateFinding::fail($category, $message) : GateFinding::warn($category, $message);
    }

    return $findings;
  }

  /**
   * Merges two ladders, keeping the current order and appending baseline-only.
   *
   * @param list<string> $current
   *   The current ladder, in order.
   * @param list<string> $baseline
   *   The baseline ladder, in order.
   *
   * @return list<string>
   *   The merged ladder.
   */
  protected static function mergeLadder(array $current, array $baseline): array {
    $ladder = $current;

    foreach ($baseline as $alias) {
      if (!in_array($alias, $ladder, TRUE)) {
        $ladder[] = $alias;
      }
    }

    return $ladder;
  }

  /**
   * The position of a minimal model on a ladder, worst for none or unknown.
   *
   * @param list<string> $ladder
   *   The merged ladder.
   * @param string|null $alias
   *   The minimal model alias, or NULL when no model supported the skill.
   *
   * @return int
   *   The zero-based index, or the ladder length when the model is NULL or off
   *   the ladder (either way, the worst position).
   */
  protected static function ladderIndex(array $ladder, ?string $alias): int {
    if ($alias === NULL) {
      return count($ladder);
    }

    $index = array_search($alias, $ladder, TRUE);

    return $index === FALSE ? count($ladder) : $index;
  }

  /**
   * The display label for a minimal model, naming the absence of one.
   *
   * @param string|null $alias
   *   The model alias, or NULL.
   *
   * @return string
   *   The alias, or `none` when NULL.
   */
  protected static function modelLabel(?string $alias): string {
    return $alias ?? 'none';
  }

  /**
   * Renders a task key as a `skill / task` label.
   *
   * @param string $key
   *   The task key.
   *
   * @return string
   *   The human label.
   */
  protected static function keyLabel(string $key): string {
    return str_replace("\t", ' / ', $key);
  }

}
