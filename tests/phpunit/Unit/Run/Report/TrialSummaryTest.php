<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Run\Report;

use AlexSkrypnyk\SkillTest\Run\Report\TrialSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class TrialSummaryTest.
 *
 * Unit test for summarising why an llm trial failed.
 */
#[CoversClass(TrialSummary::class)]
final class TrialSummaryTest extends TestCase {

  #[DataProvider('dataProviderLine')]
  public function testLine(array $trial, string $expected): void {
    $this->assertSame($expected, TrialSummary::line($trial));
  }

  /**
   * Data provider for testLine.
   *
   * @return array<string, array{array<string, mixed>, string}>
   *   The trial and its expected summary line.
   */
  public static function dataProviderLine(): array {
    return [
      'nothing failed' => [
        ['trial' => 1, 'pass' => TRUE, 'contract' => [['check' => 'contract.tools.required', 'pass' => TRUE]], 'judge' => [['criterion' => 1, 'pass' => TRUE]]],
        '',
      ],
      'empty trial' => [
        [],
        '',
      ],
      'contract failure only' => [
        ['contract' => [['check' => 'contract.tools.required', 'pass' => FALSE], ['check' => 'contract.commands.forbidden', 'pass' => FALSE]]],
        'failed: contract contract.tools.required; contract contract.commands.forbidden',
      ],
      'judge failure only' => [
        ['judge' => [['criterion' => 1, 'pass' => TRUE], ['criterion' => 2, 'pass' => FALSE], ['criterion' => 3, 'pass' => FALSE]]],
        'failed: judge criteria 2, 3',
      ],
      'both contract and judge failures' => [
        ['contract' => [['check' => 'contract.tools.required', 'pass' => FALSE]], 'judge' => [['criterion' => 2, 'pass' => FALSE]]],
        'failed: contract contract.tools.required; judge criteria 2',
      ],
    ];
  }

}
