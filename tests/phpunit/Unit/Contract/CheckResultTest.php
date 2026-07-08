<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Contract;

use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class CheckResultTest.
 *
 * Unit test for the check result value object.
 */
#[CoversClass(CheckResult::class)]
final class CheckResultTest extends TestCase {

  public function testPassFactoryHoldsFields(): void {
    $result = CheckResult::pass('contract.commands.required', 'drives the workflow', 'harness workflow start', 'matched.');

    $this->assertTrue($result->pass);
    $this->assertSame('contract.commands.required', $result->id);
    $this->assertSame('drives the workflow', $result->label);
    $this->assertSame('harness workflow start', $result->evidence);
    $this->assertSame('matched.', $result->message);
  }

  public function testFailFactoryHoldsFields(): void {
    $result = CheckResult::fail('contract.tools.forbidden', 'Bash', 'rm -rf /', 'forbidden tool used.');

    $this->assertFalse($result->pass);
    $this->assertSame('contract.tools.forbidden', $result->id);
    $this->assertSame('Bash', $result->label);
    $this->assertSame('rm -rf /', $result->evidence);
    $this->assertSame('forbidden tool used.', $result->message);
  }

  public function testToArrayIsStable(): void {
    $result = CheckResult::pass('check.custom', 'custom', 'evidence', 'ok.');

    $this->assertSame([
      'id' => 'check.custom',
      'label' => 'custom',
      'pass' => TRUE,
      'evidence' => 'evidence',
      'message' => 'ok.',
    ], $result->toArray());
  }

}
