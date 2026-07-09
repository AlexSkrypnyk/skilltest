<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Init;

use AlexSkrypnyk\SkillTest\Init\LineDiff;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class LineDiffTest.
 *
 * Unit test for the line diff used in the merge-safe preview.
 */
#[CoversClass(LineDiff::class)]
final class LineDiffTest extends TestCase {

  #[DataProvider('dataProviderUnified')]
  public function testUnified(string $old, string $new, string $expected): void {
    $this->assertSame($expected, LineDiff::unified($old, $new));
  }

  public static function dataProviderUnified(): \Iterator {
    yield 'identical' => ["a\nb", "a\nb", " a\n b"];
    yield 'substitution in the middle' => ["a\nb\nc", "a\nx\nc", " a\n-b\n+x\n c"];
    yield 'trailing addition' => ["a", "a\nb", " a\n+b"];
    yield 'trailing removal' => ["a\nb", "a", " a\n-b"];
    yield 'addition at the front' => ["b", "a\nb", "+a\n b"];
    yield 'full replacement' => ["a", "b", "-a\n+b"];
    yield 'empty to content' => ["", "x", "-\n+x"];
  }

}
