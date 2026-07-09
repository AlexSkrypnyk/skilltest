<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Judge;

use AlexSkrypnyk\SkillTest\Judge\JudgeCriterion;
use AlexSkrypnyk\SkillTest\Judge\UnknownPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class JudgeCriterionTest.
 *
 * Unit test for one criterion's outcome and its policy-dependent gating.
 */
#[CoversClass(JudgeCriterion::class)]
final class JudgeCriterionTest extends TestCase {

  #[DataProvider('dataProviderBlocks')]
  public function testBlocks(bool $pass, bool $unknown, UnknownPolicy $policy, bool $expected): void {
    $this->assertSame($expected, (new JudgeCriterion(1, $pass, $unknown))->blocks($policy));
  }

  public static function dataProviderBlocks(): \Iterator {
    yield 'passed never blocks under fail' => [TRUE, FALSE, UnknownPolicy::Fail, FALSE];
    yield 'passed never blocks under ignore' => [TRUE, FALSE, UnknownPolicy::Ignore, FALSE];
    yield 'failed blocks under fail' => [FALSE, FALSE, UnknownPolicy::Fail, TRUE];
    yield 'failed blocks under ignore' => [FALSE, FALSE, UnknownPolicy::Ignore, TRUE];
    yield 'unknown blocks under fail' => [FALSE, TRUE, UnknownPolicy::Fail, TRUE];
    yield 'unknown does not block under ignore' => [FALSE, TRUE, UnknownPolicy::Ignore, FALSE];
  }

  public function testToArray(): void {
    $this->assertSame(['criterion' => 2, 'pass' => FALSE, 'unknown' => TRUE], (new JudgeCriterion(2, FALSE, TRUE))->toArray());
  }

}
