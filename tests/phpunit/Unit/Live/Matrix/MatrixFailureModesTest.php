<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live\Matrix;

use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Judge\JudgeCriterion;
use AlexSkrypnyk\SkillTest\Judge\UnknownPolicy;
use AlexSkrypnyk\SkillTest\Live\LlmSuite;
use AlexSkrypnyk\SkillTest\Live\Matrix\MatrixFailureModes;
use AlexSkrypnyk\SkillTest\Live\ModelOutcome;
use AlexSkrypnyk\SkillTest\Live\TrialResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class MatrixFailureModesTest.
 *
 * Unit test for the per-model failure-mode tally.
 */
#[CoversClass(MatrixFailureModes::class)]
final class MatrixFailureModesTest extends TestCase {

  public function testCountsFailedContractChecksByIdRankedByFrequency(): void {
    $trials = [
      self::trial([CheckResult::fail('contract.commands.forbidden', 'no push', '', 'a'), CheckResult::fail('live.mcp', 'mcp', '', 'b')]),
      self::trial([CheckResult::fail('contract.commands.forbidden', 'no push', '', 'a')]),
    ];

    $modes = MatrixFailureModes::fromModels([self::model($trials)], [], UnknownPolicy::Fail);

    $this->assertSame(['contract.commands.forbidden' => 2, 'live.mcp' => 1], $modes->contract);
    $this->assertSame('contract: contract.commands.forbidden (2x), live.mcp (1x)', $modes->describe());
  }

  public function testCountsBlockingJudgeCriteriaByRubricText(): void {
    $rubric = ['names the change', 'lists the files'];
    $trials = [
      self::trial([], [new JudgeCriterion(1, TRUE, FALSE), new JudgeCriterion(2, FALSE, FALSE)]),
      self::trial([], [new JudgeCriterion(1, TRUE, FALSE), new JudgeCriterion(2, FALSE, FALSE)]),
    ];

    $modes = MatrixFailureModes::fromModels([self::model($trials)], $rubric, UnknownPolicy::Fail);

    $this->assertSame(['lists the files' => 2], $modes->judge);
    $this->assertSame('judge: lists the files (2x)', $modes->describe());
  }

  public function testUnknownCriterionBlocksUnderFailButNotUnderIgnore(): void {
    $rubric = ['names the change'];
    $trials = [self::trial([], [new JudgeCriterion(1, FALSE, TRUE)])];

    $strict = MatrixFailureModes::fromModels([self::model($trials)], $rubric, UnknownPolicy::Fail);
    $lenient = MatrixFailureModes::fromModels([self::model($trials)], $rubric, UnknownPolicy::Ignore);

    $this->assertSame(['names the change' => 1], $strict->judge);
    $this->assertSame([], $lenient->judge);
    $this->assertTrue($lenient->isEmpty());
  }

  public function testTiesBreakByNameForADeterministicRanking(): void {
    $trials = [self::trial([CheckResult::fail('contract.zeta', 'z', '', ''), CheckResult::fail('contract.alpha', 'a', '', '')])];

    $modes = MatrixFailureModes::fromModels([self::model($trials)], [], UnknownPolicy::Fail);

    $this->assertSame(['contract.alpha', 'contract.zeta'], array_keys($modes->contract));
  }

  public function testJudgeChecksAreNotCountedAsContractFailures(): void {
    $trials = [self::trial([CheckResult::fail(LlmSuite::CHECK_JUDGE_RUBRIC, 'judge rubric', '', ''), CheckResult::fail(LlmSuite::CHECK_JUDGE, 'judge verdict', '', '')])];

    $modes = MatrixFailureModes::fromModels([self::model($trials)], [], UnknownPolicy::Fail);

    $this->assertTrue($modes->isEmpty());
  }

  public function testFallsBackToCriterionIdWhenRubricTextIsMissing(): void {
    $trials = [self::trial([], [new JudgeCriterion(3, FALSE, FALSE)])];

    $modes = MatrixFailureModes::fromModels([self::model($trials)], ['only one'], UnknownPolicy::Fail);

    $this->assertSame(['criterion 3' => 1], $modes->judge);
  }

  public function testEmptyWhenEverythingPassed(): void {
    $modes = MatrixFailureModes::fromModels([self::model([self::trial([CheckResult::pass('contract.x', 'x', 'e', '')])])], [], UnknownPolicy::Fail);

    $this->assertTrue($modes->isEmpty());
    $this->assertSame('', $modes->describe());
  }

  /**
   * Wraps trials in a single failing model outcome.
   *
   * @param \AlexSkrypnyk\SkillTest\Live\TrialResult[] $trials
   *   The trials.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\ModelOutcome
   *   The model outcome.
   */
  protected static function model(array $trials): ModelOutcome {
    return new ModelOutcome('claude-haiku-4-5', 'haiku', $trials, 0.8);
  }

  /**
   * Builds a failing trial with the given checks and criteria.
   *
   * @param \AlexSkrypnyk\SkillTest\Contract\CheckResult[] $checks
   *   The graded checks.
   * @param \AlexSkrypnyk\SkillTest\Judge\JudgeCriterion[] $criteria
   *   The judge criteria.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\TrialResult
   *   The trial.
   */
  protected static function trial(array $checks, array $criteria = []): TrialResult {
    return new TrialResult(1, FALSE, $checks, 0, 0, 0, 0.0, 0, '', 'artifacts/t.jsonl', $criteria, $criteria === [] ? NULL : 'opus');
  }

}
