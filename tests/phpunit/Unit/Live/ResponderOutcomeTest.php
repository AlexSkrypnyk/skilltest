<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Live\ResponderOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class ResponderOutcomeTest.
 *
 * Unit test for the trial-level responder outcome and its failure semantics.
 */
#[CoversClass(ResponderOutcome::class)]
final class ResponderOutcomeTest extends TestCase {

  #[DataProvider('dataProviderIsFailure')]
  public function testIsFailure(ResponderOutcome $outcome, string $value, bool $is_failure): void {
    $this->assertSame($value, $outcome->value);
    $this->assertSame($is_failure, $outcome->isFailure());
  }

  public static function dataProviderIsFailure(): \Iterator {
    yield 'completed grades the final state' => [ResponderOutcome::Completed, 'completed', FALSE];
    yield 'cap-exhausted grades the final state' => [ResponderOutcome::CapExhausted, 'cap-exhausted', FALSE];
    yield 'abstained fails the trial' => [ResponderOutcome::Abstained, 'abstained', TRUE];
    yield 'error fails the trial' => [ResponderOutcome::Error, 'error', TRUE];
  }

}
