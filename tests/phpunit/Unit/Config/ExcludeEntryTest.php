<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Config;

use AlexSkrypnyk\SkillTest\Config\ExcludeEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class ExcludeEntryTest.
 *
 * Unit test for the coverage-gate exclusion value object.
 */
#[CoversClass(ExcludeEntry::class)]
final class ExcludeEntryTest extends TestCase {

  #[DataProvider('dataProviderFromValue')]
  public function testFromValue(mixed $value, string $expected_skill, ?string $expected_reason): void {
    $entry = ExcludeEntry::fromValue($value);

    $this->assertSame($expected_skill, $entry->skill);
    $this->assertSame($expected_reason, $entry->reason);
  }

  /**
   * Data provider for testFromValue.
   *
   * @return \Iterator<string, array{mixed, string, (string | null)}>
   *   Raw value, expected skill, and expected reason.
   */
  public static function dataProviderFromValue(): \Iterator {
    yield 'bare string is a skill with no reason' => ['foo', 'foo', NULL];
    yield 'mapping with skill and reason' => [['skill' => 'foo', 'reason' => 'legacy'], 'foo', 'legacy'];
    yield 'mapping without a reason' => [['skill' => 'foo'], 'foo', NULL];
    yield 'mapping with a blank reason' => [['skill' => 'foo', 'reason' => '  '], 'foo', NULL];
    yield 'mapping without a skill' => [['reason' => 'orphan'], '', 'orphan'];
    yield 'empty mapping' => [[], '', NULL];
    yield 'null value' => [NULL, '', NULL];
    yield 'numeric skill is coerced' => [42, '42', NULL];
  }

}
