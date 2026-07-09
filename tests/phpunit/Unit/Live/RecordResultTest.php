<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Live\RecordResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class RecordResultTest.
 *
 * Unit test for the raw record-run value object.
 */
#[CoversClass(RecordResult::class)]
final class RecordResultTest extends TestCase {

  public function testCarriesTheRawRunOutcome(): void {
    $result = new RecordResult('{"type":"result"}' . "\n", 0, 4211);

    $this->assertSame('{"type":"result"}' . "\n", $result->transcript);
    $this->assertSame(0, $result->exitCode);
    $this->assertSame(4211, $result->durationMs);
  }

  public function testCarriesNonZeroExit(): void {
    $result = new RecordResult('', 137, 90);

    $this->assertSame('', $result->transcript);
    $this->assertSame(137, $result->exitCode);
    $this->assertSame(90, $result->durationMs);
  }

}
