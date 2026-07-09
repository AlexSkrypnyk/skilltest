<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Grade;

use AlexSkrypnyk\SkillTest\Grade\RescoreResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class RescoreResultTest.
 *
 * Unit test for the re-score result value object.
 */
#[CoversClass(RescoreResult::class)]
final class RescoreResultTest extends TestCase {

  public function testCarriesDocumentAndCounts(): void {
    $result = new RescoreResult(['version' => '1'], 5, 2, 1, ['a note']);

    $this->assertSame(['version' => '1'], $result->document);
    $this->assertSame(5, $result->trialsRescored);
    $this->assertSame(2, $result->newlyFailing);
    $this->assertSame(1, $result->newlyPassing);
    $this->assertSame(['a note'], $result->notes);
  }

  #[DataProvider('dataProviderChanged')]
  public function testChanged(int $failing, int $passing, bool $changed): void {
    $this->assertSame($changed, (new RescoreResult([], 3, $failing, $passing, []))->changed());
  }

  public static function dataProviderChanged(): \Iterator {
    yield 'nothing moved' => [0, 0, FALSE];
    yield 'newly failing' => [1, 0, TRUE];
    yield 'newly passing' => [0, 1, TRUE];
    yield 'both directions' => [2, 3, TRUE];
  }

}
