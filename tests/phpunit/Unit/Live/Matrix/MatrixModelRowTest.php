<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live\Matrix;

use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Judge\JudgeCriterion;
use AlexSkrypnyk\SkillTest\Live\LlmSuite;
use AlexSkrypnyk\SkillTest\Live\Matrix\MatrixModelRow;
use AlexSkrypnyk\SkillTest\Live\ModelOutcome;
use AlexSkrypnyk\SkillTest\Live\TrialResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class MatrixModelRowTest.
 *
 * Unit test for the per-model grid row and its contract/judge split.
 */
#[CoversClass(MatrixModelRow::class)]
final class MatrixModelRowTest extends TestCase {

  public function testContractAndJudgeAreCountedSeparately(): void {
    $trials = [
      // Contract holds and the judge affirms the rubric.
      self::trial(TRUE, [self::ok()], [new JudgeCriterion(1, TRUE, FALSE)], 'opus'),
      // Contract holds but the judge rejects the rubric.
      self::trial(FALSE, [self::ok(), CheckResult::fail(LlmSuite::CHECK_JUDGE_RUBRIC, 'judge rubric', '', 'failed')], [new JudgeCriterion(1, FALSE, FALSE)], 'opus'),
      // The agent went off-contract, so the judge never ran.
      self::trial(FALSE, [CheckResult::fail(LlmSuite::CHECK_AGENT, 'agent run', '', 'exited with code 1')], [], 'opus'),
    ];

    $row = MatrixModelRow::fromModels([new ModelOutcome('claude-haiku-4-5', 'haiku', $trials, 0.8)]);

    $this->assertSame('haiku', $row->alias);
    $this->assertSame(3, $row->trials);
    $this->assertSame('2/3', $row->contractCell());
    $this->assertSame('1/3', $row->judgeCell());
    $this->assertSame('0.33', $row->rate());
    $this->assertSame('fail', $row->verdict());
    $this->assertTrue($row->hasRubric);
  }

  public function testNoRubricShowsDashForJudge(): void {
    $row = MatrixModelRow::fromModels([new ModelOutcome('m', 'm', [self::trial(TRUE, [self::ok()])], 0.8)]);

    $this->assertFalse($row->hasRubric);
    $this->assertSame('-', $row->judgeCell());
    $this->assertSame('pass', $row->verdict());
  }

  public function testAggregatesAcrossTheSkillsTasks(): void {
    $one = new ModelOutcome('m', 'm', [self::trial(TRUE, [self::ok()], [], NULL, 0.02)], 0.8);
    $two = new ModelOutcome('m', 'm', [self::trial(TRUE, [self::ok()], [], NULL, 0.04)], 0.8);

    $row = MatrixModelRow::fromModels([$one, $two]);

    $this->assertSame(2, $row->trials);
    $this->assertSame('1.00', $row->rate());
    $this->assertTrue($row->passed);
    $this->assertEqualsWithDelta(0.06, $row->cost, PHP_FLOAT_EPSILON);
    $this->assertEqualsWithDelta(0.03, $row->perRunCost(), PHP_FLOAT_EPSILON);
  }

  public function testVerdictFailsWhenAnyTaskFailsEvenIfAggregateIsHigh(): void {
    $passes = new ModelOutcome('m', 'm', [self::trial(TRUE, [self::ok()])], 0.8);
    $fails = new ModelOutcome('m', 'm', [self::trial(FALSE, [CheckResult::fail('contract.x', 'x', '', 'no')])], 0.8);

    $row = MatrixModelRow::fromModels([$passes, $fails]);

    $this->assertFalse($row->passed);
    $this->assertSame('0.50', $row->rate());
    $this->assertSame('fail', $row->verdict());
  }

  public function testPerRunCostIsZeroWithoutTrials(): void {
    $row = MatrixModelRow::fromModels([new ModelOutcome('m', 'm', [], 0.0)]);

    $this->assertEqualsWithDelta(0.0, $row->perRunCost(), PHP_FLOAT_EPSILON);
    $this->assertSame('0.00', $row->rate());
  }

  /**
   * A passing contract check.
   *
   * @return \AlexSkrypnyk\SkillTest\Contract\CheckResult
   *   The check.
   */
  protected static function ok(): CheckResult {
    return CheckResult::pass('contract.tools.required', 'uses Bash', 'Bash', '');
  }

  /**
   * Builds a trial result.
   *
   * @param bool $pass
   *   The overall verdict.
   * @param \AlexSkrypnyk\SkillTest\Contract\CheckResult[] $checks
   *   The graded checks.
   * @param \AlexSkrypnyk\SkillTest\Judge\JudgeCriterion[] $criteria
   *   The judge criteria.
   * @param string|null $judge_model
   *   The pinned judge model, or NULL when no rubric.
   * @param float $cost
   *   The trial cost.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\TrialResult
   *   The trial.
   */
  protected static function trial(bool $pass, array $checks, array $criteria = [], ?string $judge_model = NULL, float $cost = 0.0): TrialResult {
    return new TrialResult(1, $pass, $checks, 0, 0, 0, $cost, 0, '', 'artifacts/t.jsonl', $criteria, $judge_model);
  }

}
