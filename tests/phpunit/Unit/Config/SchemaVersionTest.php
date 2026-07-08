<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Config;

use AlexSkrypnyk\SkillTest\Config\SchemaVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class SchemaVersionTest.
 *
 * Unit test for the schema version parser.
 */
#[CoversClass(SchemaVersion::class)]
final class SchemaVersionTest extends TestCase {

  #[DataProvider('dataProviderParse')]
  public function testParse(string|int|float|null $raw, int $major, int $minor): void {
    $version = SchemaVersion::parse($raw);

    $this->assertSame($major, $version->major);
    $this->assertSame($minor, $version->minor);
  }

  public static function dataProviderParse(): \Iterator {
    yield 'null is current' => [NULL, SchemaVersion::CURRENT_MAJOR, SchemaVersion::CURRENT_MINOR];
    yield 'empty is current' => ['', SchemaVersion::CURRENT_MAJOR, SchemaVersion::CURRENT_MINOR];
    yield 'major only' => ['1', 1, 0];
    yield 'major and minor' => ['1.2', 1, 2];
    yield 'future major' => ['2', 2, 0];
    yield 'int' => [1, 1, 0];
    yield 'float coerces to major' => [1.0, 1, 0];
    yield 'whitespace trimmed' => [' 1.3 ', 1, 3];
  }

  public function testParseInvalid(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid schema version "abc"');

    SchemaVersion::parse('abc');
  }

  #[DataProvider('dataProviderIsCurrentMajor')]
  public function testIsCurrentMajor(int $major, bool $expected): void {
    $version = new SchemaVersion($major, 0);

    $this->assertSame($expected, $version->isCurrentMajor());
  }

  public static function dataProviderIsCurrentMajor(): \Iterator {
    yield 'current' => [SchemaVersion::CURRENT_MAJOR, TRUE];
    yield 'future' => [SchemaVersion::CURRENT_MAJOR + 1, FALSE];
    yield 'past' => [0, FALSE];
  }

  public function testToString(): void {
    $this->assertSame('1.2', (string) (new SchemaVersion(1, 2)));
  }

}
