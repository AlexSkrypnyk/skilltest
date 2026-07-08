<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit;

use AlexSkrypnyk\SkillTest\ExitCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class ExitCodeTest.
 *
 * Unit test for the tool-wide exit code contract.
 */
#[CoversClass(ExitCode::class)]
final class ExitCodeTest extends TestCase {

  #[DataProvider('dataProviderConstants')]
  public function testConstants(int $actual, int $expected): void {
    $this->assertSame($expected, $actual);
  }

  public static function dataProviderConstants(): \Iterator {
    yield 'pass' => [ExitCode::PASS, 0];
    yield 'fail' => [ExitCode::FAIL, 1];
    yield 'config error' => [ExitCode::CONFIG_ERROR, 2];
  }

}
