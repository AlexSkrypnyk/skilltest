<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Live\ModelOutcome;
use AlexSkrypnyk\SkillTest\Live\TrialResult;
use AlexSkrypnyk\SkillTest\Tests\Traits\ArrayPathTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class ModelOutcomeTest.
 *
 * Unit test for the per-model pass-rate verdict.
 */
#[CoversClass(ModelOutcome::class)]
final class ModelOutcomeTest extends TestCase {

  use ArrayPathTrait;

  public function testPassRateAndVerdict(): void {
    $model = new ModelOutcome('claude-haiku-4-5', 'haiku', [self::trial(1, TRUE), self::trial(2, FALSE), self::trial(3, FALSE)], 0.8);

    $this->assertEqualsWithDelta(1 / 3, $model->passRate(), 0.0001);
    $this->assertFalse($model->passed());
  }

  public function testMeetsThresholdExactly(): void {
    $model = new ModelOutcome('m', 'm', [self::trial(1, TRUE), self::trial(2, FALSE)], 0.5);

    $this->assertEqualsWithDelta(0.5, $model->passRate(), PHP_FLOAT_EPSILON);
    $this->assertTrue($model->passed());
  }

  public function testEmptyTrialsAreZeroAndFail(): void {
    $model = new ModelOutcome('m', 'm', [], 0.8);

    $this->assertEqualsWithDelta(0.0, $model->passRate(), PHP_FLOAT_EPSILON);
    $this->assertFalse($model->passed());
  }

  public function testZeroThresholdAlwaysPasses(): void {
    $model = new ModelOutcome('m', 'm', [], 0.0);

    $this->assertTrue($model->passed());
  }

  public function testToArrayRoundsPassRate(): void {
    $model = new ModelOutcome('claude-haiku-4-5', 'haiku', [self::trial(1, TRUE), self::trial(2, FALSE), self::trial(3, FALSE)], 0.8);

    $row = $model->toArray();

    $this->assertSame('claude-haiku-4-5', $row['model']);
    $this->assertSame('haiku', $row['alias']);
    $this->assertEqualsWithDelta(0.33, $row['pass_rate'], PHP_FLOAT_EPSILON);
    $this->assertCount(3, $this->pathArray($row, 'trials'));
    $this->assertSame(1, $this->path($row, 'trials', 0, 'trial'));
  }

  /**
   * Builds a trial result with a given verdict.
   *
   * @param int $number
   *   The trial number.
   * @param bool $pass
   *   The verdict.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\TrialResult
   *   The trial.
   */
  protected static function trial(int $number, bool $pass): TrialResult {
    return new TrialResult($number, $pass, [], 0, 0, 0, 0.0, 0, '', sprintf('artifacts/t%d.jsonl', $number));
  }

}
