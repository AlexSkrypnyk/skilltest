<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Config;

use AlexSkrypnyk\SkillTest\Config\Pcre;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class PcreTest.
 *
 * Unit test for the delimiter-less pattern compiler.
 */
#[CoversClass(Pcre::class)]
final class PcreTest extends TestCase {

  #[DataProvider('dataProviderCompiles')]
  public function testCompiles(string $pattern, bool $expected): void {
    $this->assertSame($expected, Pcre::compiles($pattern));
  }

  public static function dataProviderCompiles(): \Iterator {
    yield 'word boundary' => ['\bgit\s+commit\b', TRUE];
    yield 'alternation' => ['(start|next|status)', TRUE];
    yield 'unbalanced paren' => ['(', FALSE];
    yield 'unterminated class' => ['[unterminated', FALSE];
    yield 'contains hash delimiter' => ['a#b', TRUE];
    yield 'contains every candidate delimiter' => ['#~%@!;,|', TRUE];
  }

  #[DataProvider('dataProviderDelimit')]
  public function testDelimit(string $pattern, string $expected): void {
    $this->assertSame($expected, Pcre::delimit($pattern));
  }

  public static function dataProviderDelimit(): \Iterator {
    yield 'default hash' => ['foo', '#foo#'];
    yield 'falls back to tilde when hash present' => ['a#b', '~a#b~'];
    yield 'escapes hash when every delimiter present' => ['#~%@!;,|', '#\#~%@!;,|#'];
  }

}
