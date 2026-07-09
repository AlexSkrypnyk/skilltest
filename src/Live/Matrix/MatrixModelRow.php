<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live\Matrix;

use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Live\LlmSuite;
use AlexSkrypnyk\SkillTest\Live\ModelOutcome;
use AlexSkrypnyk\SkillTest\Live\TrialResult;

/**
 * One model's row in a skill's matrix grid, aggregated over the skill's tasks.
 *
 * A skill runs every task on the same model, so a model's grid row folds
 * those tasks together: the trial count, the contract-passing and
 * judge-passing tallies, and the summed cost are the sums across the skill's
 * tasks, and the row's verdict is whether the model supports the skill (every
 * task met its threshold), not merely whether the aggregate pass rate did.
 * Contract and judge are counted separately because a model that obeys the
 * contract but writes output the judge rejects is a different failure from one
 * that goes off-contract; a skill with no rubric has no judge tally at all.
 */
final readonly class MatrixModelRow {

  /**
   * Constructs a MatrixModelRow.
   *
   * @param string $alias
   *   The model alias the row is labelled by.
   * @param string $model
   *   The resolved model id.
   * @param int $trials
   *   The total number of trials across the skill's tasks on this model.
   * @param int $passing
   *   The number of those trials that passed every check.
   * @param int $contractPassing
   *   The number of trials whose contract (non-judge) checks all passed.
   * @param int $judgePassing
   *   The number of trials whose rubric the judge affirmed.
   * @param bool $hasRubric
   *   Whether the skill declares a judge rubric, so the judge tally is
   *   meaningful.
   * @param bool $passed
   *   Whether the model supports the skill: every task met its threshold.
   * @param float $cost
   *   The summed trial cost, in USD, on this model.
   */
  public function __construct(
    public string $alias,
    public string $model,
    public int $trials,
    public int $passing,
    public int $contractPassing,
    public int $judgePassing,
    public bool $hasRubric,
    public bool $passed,
    public float $cost,
  ) {}

  /**
   * Folds a model's outcome across a skill's tasks into one grid row.
   *
   * @param \AlexSkrypnyk\SkillTest\Live\ModelOutcome[] $models
   *   The same model's outcome in each of the skill's tasks; must be non-empty
   *   and share the alias and id.
   *
   * @return self
   *   The aggregated row.
   */
  public static function fromModels(array $models): self {
    $first = $models[0];
    $trials = 0;
    $passing = 0;
    $contract = 0;
    $judge = 0;
    $rubric = FALSE;
    $cost = 0.0;
    $passed = TRUE;

    foreach ($models as $model) {
      if (!$model->passed()) {
        $passed = FALSE;
      }

      foreach ($model->trials as $trial) {
        $trials++;
        $passing += $trial->pass ? 1 : 0;
        $contract += self::contractPassed($trial) ? 1 : 0;
        $judge += self::judgePassed($trial) ? 1 : 0;
        $rubric = $rubric || $trial->judgeModel !== NULL;
        $cost += $trial->cost;
      }
    }

    return new self($first->alias, $first->model, $trials, $passing, $contract, $judge, $rubric, $passed, round($cost, 4));
  }

  /**
   * The fraction of trials that passed every check.
   *
   * @return float
   *   The pass rate in 0..1; zero when the model ran no trials.
   */
  public function passRate(): float {
    return $this->trials === 0 ? 0.0 : $this->passing / $this->trials;
  }

  /**
   * The pass rate formatted to two decimals for the grid.
   *
   * @return string
   *   The formatted rate.
   */
  public function rate(): string {
    return number_format($this->passRate(), 2);
  }

  /**
   * The contract column: trials that stayed on-contract over total trials.
   *
   * @return string
   *   The `N/M` cell.
   */
  public function contractCell(): string {
    return sprintf('%d/%d', $this->contractPassing, $this->trials);
  }

  /**
   * The judge column: trials whose rubric was affirmed, or a dash when
   * unjudged.
   *
   * @return string
   *   The `N/M` cell, or `-` when the skill declares no rubric.
   */
  public function judgeCell(): string {
    return $this->hasRubric ? sprintf('%d/%d', $this->judgePassing, $this->trials) : '-';
  }

  /**
   * The verdict word for the grid.
   *
   * @return string
   *   `pass` when the model supports the skill, otherwise `fail`.
   */
  public function verdict(): string {
    return $this->passed ? 'pass' : 'fail';
  }

  /**
   * The average cost of a single run on this model.
   *
   * @return float
   *   The per-run cost in USD; zero when the model ran no trials.
   */
  public function perRunCost(): float {
    return $this->trials === 0 ? 0.0 : round($this->cost / $this->trials, 4);
  }

  /**
   * Whether a trial's contract (non-judge) checks all passed.
   *
   * @param \AlexSkrypnyk\SkillTest\Live\TrialResult $trial
   *   The trial.
   *
   * @return bool
   *   TRUE when every check that is not a judge check passed.
   */
  protected static function contractPassed(TrialResult $trial): bool {
    foreach ($trial->checks as $check) {
      if (!self::isJudge($check) && !$check->pass) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Whether the judge affirmed a trial's rubric.
   *
   * A trial the judge scored with no blocking verdict passes the judge column;
   * a trial the judge never reached (a broken or incomplete run leaves no
   * criteria) did not demonstrate a passing rubric and so does not count.
   *
   * @param \AlexSkrypnyk\SkillTest\Live\TrialResult $trial
   *   The trial.
   *
   * @return bool
   *   TRUE when the judge scored the trial and folded in no failing judge
   *   check.
   */
  protected static function judgePassed(TrialResult $trial): bool {
    if ($trial->criteria === []) {
      return FALSE;
    }

    foreach ($trial->checks as $check) {
      if (self::isJudge($check) && !$check->pass) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Whether a check is one of the judge's folded-in checks.
   *
   * @param \AlexSkrypnyk\SkillTest\Contract\CheckResult $check
   *   The check.
   *
   * @return bool
   *   TRUE when the check id is the judge verdict or judge rubric id.
   */
  protected static function isJudge(CheckResult $check): bool {
    return $check->id === LlmSuite::CHECK_JUDGE || $check->id === LlmSuite::CHECK_JUDGE_RUBRIC;
  }

}
