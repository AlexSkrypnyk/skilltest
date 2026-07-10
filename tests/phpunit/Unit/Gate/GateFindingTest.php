<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Gate;

use AlexSkrypnyk\SkillTest\Gate\GateFinding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class GateFindingTest.
 *
 * Unit test for the gate finding value object.
 */
#[CoversClass(GateFinding::class)]
final class GateFindingTest extends TestCase {

  public function testFailFactory(): void {
    $finding = GateFinding::fail('regression', 'pass rate dropped');

    $this->assertSame('fail', $finding->severity);
    $this->assertSame('regression', $finding->category);
    $this->assertSame('pass rate dropped', $finding->message);
    $this->assertTrue($finding->failed());
  }

  public function testWarnFactory(): void {
    $finding = GateFinding::warn('new-task', 'a task appeared');

    $this->assertSame('warn', $finding->severity);
    $this->assertFalse($finding->failed());
  }

  public function testToArray(): void {
    $this->assertSame(
      ['severity' => 'fail', 'category' => 'golden', 'message' => 'gone'],
      GateFinding::fail('golden', 'gone')->toArray(),
    );
  }

}
