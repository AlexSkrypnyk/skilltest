<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Config;

use AlexSkrypnyk\SkillTest\Config\Packs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class PacksTest.
 *
 * Unit test for the pack catalog.
 */
#[CoversClass(Packs::class)]
final class PacksTest extends TestCase {

  #[DataProvider('dataProviderReference')]
  public function testReference(string $value, ?string $expected): void {
    $this->assertSame($expected, Packs::reference($value));
  }

  public static function dataProviderReference(): \Iterator {
    yield 'pack reference' => ['pack:git-mutations', 'git-mutations'];
    yield 'empty reference' => ['pack:', ''];
    yield 'plain pattern' => ['\bgit\b', NULL];
  }

  #[DataProvider('dataProviderIsPatternPack')]
  public function testIsPatternPack(string $name, bool $expected): void {
    $this->assertSame($expected, Packs::isPatternPack($name));
  }

  public static function dataProviderIsPatternPack(): \Iterator {
    yield 'known' => ['gh-mutations', TRUE];
    yield 'baseline is not a pattern pack' => ['baseline', FALSE];
    yield 'unknown' => ['nope', FALSE];
  }

  #[DataProvider('dataProviderIsSecurityPack')]
  public function testIsSecurityPack(string $name, bool $expected): void {
    $this->assertSame($expected, Packs::isSecurityPack($name));
  }

  public static function dataProviderIsSecurityPack(): \Iterator {
    yield 'baseline' => ['baseline', TRUE];
    yield 'pattern pack is not a security pack' => ['git-mutations', FALSE];
    yield 'unknown' => ['nope', FALSE];
  }

}
