<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Live\LlmSuite;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class LlmSuiteTest.
 *
 * Unit test for the live llm suite's static helpers.
 */
#[CoversClass(LlmSuite::class)]
final class LlmSuiteTest extends TestCase {

  #[DataProvider('dataProviderIsJudgeCheck')]
  public function testIsJudgeCheck(string $id, bool $expected): void {
    $check = CheckResult::pass($id, 'label', '', 'message');

    $this->assertSame($expected, LlmSuite::isJudgeCheck($check));
  }

  public static function dataProviderIsJudgeCheck(): \Iterator {
    yield 'judge verdict id' => [LlmSuite::CHECK_JUDGE, TRUE];
    yield 'judge rubric id' => [LlmSuite::CHECK_JUDGE_RUBRIC, TRUE];
    yield 'other check id' => [LlmSuite::CHECK_AGENT, FALSE];
  }

}
