<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Gate;

use AlexSkrypnyk\SkillTest\Gate\Format;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class FormatTest.
 *
 * Unit test for the shared gate number formatter.
 */
#[CoversClass(Format::class)]
final class FormatTest extends TestCase {

  #[DataProvider('dataProviderNumber')]
  public function testNumber(float $value, string $expected): void {
    $this->assertSame($expected, Format::number($value));
  }

  public static function dataProviderNumber(): \Iterator {
    yield 'whole trims decimal' => [5.0, '5'];
    yield 'zero' => [0.0, '0'];
    yield 'one decimal kept' => [5.5, '5.5'];
    yield 'hundred' => [100.0, '100'];
    yield 'rounds to one place' => [12.34, '12.3'];
  }

}
