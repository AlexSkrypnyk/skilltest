<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Coverage;

use AlexSkrypnyk\SkillTest\Coverage\CoverageRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class CoverageRowTest.
 *
 * Unit test for a single coverage-grid row.
 */
#[CoversClass(CoverageRow::class)]
final class CoverageRowTest extends TestCase {

  #[DataProvider('dataProviderStatusAndViolation')]
  public function testStatusAndViolation(bool $eval, bool $excluded, string $expected_status, bool $expected_violation): void {
    $row = new CoverageRow('foo', 'skills/foo', $eval, FALSE, 0, $excluded, NULL);

    $this->assertSame($expected_status, $row->status());
    $this->assertSame($expected_violation, $row->isViolation());
  }

  /**
   * Data provider for testStatusAndViolation.
   *
   * @return \Iterator<string, array{bool, bool, string, bool}>
   *   Eval presence, exclusion, expected status, and expected violation.
   */
  public static function dataProviderStatusAndViolation(): \Iterator {
    yield 'eval present is covered' => [TRUE, FALSE, CoverageRow::STATUS_COVERED, FALSE];
    yield 'no eval but excluded' => [FALSE, TRUE, CoverageRow::STATUS_EXCLUDED, FALSE];
    yield 'no eval and not excluded' => [FALSE, FALSE, CoverageRow::STATUS_UNCOVERED, TRUE];
    yield 'eval wins over a redundant exclusion' => [TRUE, TRUE, CoverageRow::STATUS_COVERED, FALSE];
  }

  public function testToArray(): void {
    $row = new CoverageRow('foo', 'skills/foo', TRUE, TRUE, 3, FALSE, NULL);

    $this->assertSame([
      'skill' => 'foo',
      'path' => 'skills/foo',
      'eval' => TRUE,
      'transcript' => TRUE,
      'tasks' => 3,
      'excluded' => FALSE,
      'reason' => NULL,
    ], $row->toArray());
  }

}
