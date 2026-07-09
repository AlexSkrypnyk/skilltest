<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Live\TrialResult;
use AlexSkrypnyk\SkillTest\Tests\Traits\ArrayPathTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class TrialResultTest.
 *
 * Unit test for the per-trial value object.
 */
#[CoversClass(TrialResult::class)]
final class TrialResultTest extends TestCase {

  use ArrayPathTrait;

  public function testFailuresSelectsOnlyFailedChecks(): void {
    $checks = [
      CheckResult::pass('contract.tools.required', 'Bash', 'Bash', 'ok'),
      CheckResult::fail('contract.commands.forbidden', 'no push', 'git push', 'forbidden'),
    ];
    $trial = new TrialResult(1, FALSE, $checks, 10, 5, 3, 0.01, 1200, 'jsonl', 'artifacts/t.jsonl');

    $failures = $trial->failures();

    $this->assertCount(1, $failures);
    $this->assertSame('contract.commands.forbidden', $failures[0]->id);
  }

  public function testToArrayRendersTheSchemaRow(): void {
    $checks = [CheckResult::pass('contract.tools.required', 'Bash', 'Bash', 'ok')];
    $trial = new TrialResult(2, TRUE, $checks, 4211, 883, 6, 0.0132, 18422, 'the-transcript', 'artifacts/haiku-2.jsonl');

    $row = $trial->toArray();

    $this->assertSame(2, $row['trial']);
    $this->assertTrue($row['pass']);
    $this->assertSame([], $row['judge']);
    $this->assertSame(0, $row['unknowns']);
    $this->assertSame(18422, $row['duration_ms']);
    $this->assertSame(6, $row['turns']);
    $this->assertSame(['in' => 4211, 'out' => 883], $row['tokens']);
    $this->assertEqualsWithDelta(0.0132, $row['cost_usd'], PHP_FLOAT_EPSILON);
    $this->assertSame('artifacts/haiku-2.jsonl', $row['transcript']);
    $this->assertSame('contract.tools.required', $this->path($row, 'contract', 0, 'check'));
    $this->assertTrue($this->path($row, 'contract', 0, 'pass'));
  }

}
