<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Judge;

use AlexSkrypnyk\SkillTest\Judge\JudgeCriterion;
use AlexSkrypnyk\SkillTest\Judge\JudgeVerdict;
use AlexSkrypnyk\SkillTest\Judge\UnknownPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class JudgeVerdictTest.
 *
 * Unit test for the parsed verdict's tallies and policy-dependent gating.
 */
#[CoversClass(JudgeVerdict::class)]
final class JudgeVerdictTest extends TestCase {

  public function testTalliesAndAllPassing(): void {
    $verdict = new JudgeVerdict([
      new JudgeCriterion(1, TRUE, FALSE),
      new JudgeCriterion(2, TRUE, FALSE),
    ], 'both hold');

    $this->assertSame(2, $verdict->total());
    $this->assertSame(2, $verdict->passedCount());
    $this->assertSame(0, $verdict->unknowns());
    $this->assertFalse($verdict->blocks(UnknownPolicy::Fail));
    $this->assertFalse($verdict->blocks(UnknownPolicy::Ignore));
    $this->assertSame('both hold', $verdict->reasoning);
  }

  public function testFailingCriterionBlocksUnderEveryPolicy(): void {
    $verdict = new JudgeVerdict([
      new JudgeCriterion(1, TRUE, FALSE),
      new JudgeCriterion(2, FALSE, FALSE),
    ], '');

    $this->assertSame(1, $verdict->passedCount());
    $this->assertTrue($verdict->blocks(UnknownPolicy::Fail));
    $this->assertTrue($verdict->blocks(UnknownPolicy::Ignore));
  }

  public function testAbstentionBlocksOnlyUnderFail(): void {
    $verdict = new JudgeVerdict([
      new JudgeCriterion(1, TRUE, FALSE),
      new JudgeCriterion(2, FALSE, TRUE),
    ], '');

    $this->assertSame(1, $verdict->unknowns());
    $this->assertTrue($verdict->blocks(UnknownPolicy::Fail));
    $this->assertFalse($verdict->blocks(UnknownPolicy::Ignore));
  }

  public function testToArrayRendersEveryCriterion(): void {
    $verdict = new JudgeVerdict([
      new JudgeCriterion(1, TRUE, FALSE),
      new JudgeCriterion(2, FALSE, TRUE),
    ], '');

    $this->assertSame([
      ['criterion' => 1, 'pass' => TRUE, 'unknown' => FALSE],
      ['criterion' => 2, 'pass' => FALSE, 'unknown' => TRUE],
    ], $verdict->toArray());
  }

}
