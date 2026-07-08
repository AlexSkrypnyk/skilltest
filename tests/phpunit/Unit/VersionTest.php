<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit;

use AlexSkrypnyk\SkillTest\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class VersionTest.
 *
 * Unit test for the Version class.
 */
#[CoversClass(Version::class)]
final class VersionTest extends TestCase {

  #[DataProvider('dataProviderId')]
  public function testId(?string $version, string $expected): void {
    $this->assertSame($expected, Version::id($version));
  }

  public static function dataProviderId(): \Iterator {
    yield 'unreplaced placeholder' => ['@skilltest-version@', 'development'];
    yield 'replaced version' => ['1.2.3', '1.2.3'];
    yield 'compiled-in value from source' => [NULL, 'development'];
  }

  public function testSchemaVersions(): void {
    $this->assertSame('1', Version::CONFIG_SCHEMA_VERSION);
    $this->assertSame('1', Version::RESULTS_SCHEMA_VERSION);
  }

  public function testRuntime(): void {
    $this->assertSame('source', Version::runtime());
  }

}
