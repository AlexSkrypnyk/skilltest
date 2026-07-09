<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Judge\JudgeCriterion;
use AlexSkrypnyk\SkillTest\Live\ResponderOutcome;
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
    $this->assertSame([], $row['judge']);
    $this->assertNull($row['judge_model']);
    $this->assertSame([], $row['mocks']);
    $this->assertSame('contract.tools.required', $this->path($row, 'contract', 0, 'check'));
    $this->assertTrue($this->path($row, 'contract', 0, 'pass'));
  }

  public function testToArrayListsMockLogPaths(): void {
    $mock_logs = ['artifacts/alpha__invoked__haiku__t1__mock-github.jsonl' => '{"tool":"create_issue","matched":true}'];
    $trial = new TrialResult(1, TRUE, [], 1, 1, 1, 0.0, 10, 'jsonl', 'artifacts/t.jsonl', [], NULL, $mock_logs);

    $this->assertSame(['artifacts/alpha__invoked__haiku__t1__mock-github.jsonl'], $trial->toArray()['mocks']);
  }

  public function testToArrayRendersJudgeCriteriaAndModel(): void {
    $criteria = [new JudgeCriterion(1, TRUE, FALSE), new JudgeCriterion(2, FALSE, TRUE)];
    $trial = new TrialResult(1, FALSE, [], 10, 5, 3, 0.01, 1200, 'jsonl', 'artifacts/t.jsonl', $criteria, 'claude-haiku-4-5');

    $row = $trial->toArray();

    $this->assertSame([
      ['criterion' => 1, 'pass' => TRUE, 'unknown' => FALSE],
      ['criterion' => 2, 'pass' => FALSE, 'unknown' => TRUE],
    ], $row['judge']);
    $this->assertSame(1, $row['unknowns']);
    $this->assertSame('claude-haiku-4-5', $row['judge_model']);
  }

  public function testSingleShotTrialRendersNoResponderBlock(): void {
    $trial = new TrialResult(1, TRUE, [], 10, 5, 3, 0.01, 1200, 'jsonl', 'artifacts/t.jsonl');

    $this->assertArrayNotHasKey('responder', $trial->toArray());
  }

  public function testInteractiveTrialRendersTheResponderBlock(): void {
    $trial = new TrialResult(1, FALSE, [], 10, 5, 3, 0.01, 1200, 'jsonl', 'artifacts/t.jsonl', [], NULL, [], ResponderOutcome::CapExhausted, 4);

    $this->assertSame(['outcome' => 'cap-exhausted', 'followups' => 4], $trial->toArray()['responder']);
  }

  public function testToArrayReportsCachedFlag(): void {
    $live = new TrialResult(1, TRUE, [], 1, 1, 1, 0.0, 10, 'jsonl', 'artifacts/t.jsonl');
    $replayed = new TrialResult(1, TRUE, [], 1, 1, 1, 0.0, 10, 'jsonl', 'artifacts/t.jsonl', [], NULL, [], cached: TRUE);

    $this->assertFalse($live->toArray()['cached']);
    $this->assertTrue($replayed->toArray()['cached']);
  }

  public function testCacheRoundTripIsLosslessAndFlagsTheHit(): void {
    $checks = [
      CheckResult::pass('contract.tools.required', 'Bash', 'Bash', 'ok'),
      CheckResult::fail('contract.commands.forbidden', 'no push', 'git push', 'forbidden'),
    ];
    $criteria = [new JudgeCriterion(1, TRUE, FALSE), new JudgeCriterion(2, FALSE, TRUE)];
    $mock_logs = ['artifacts/mock-github.jsonl' => '{"tool":"create_issue"}'];
    $trial = new TrialResult(3, FALSE, $checks, 210, 84, 5, 0.0132, 4300, 'the-transcript', 'artifacts/haiku-3.jsonl', $criteria, 'claude-opus-4-8', $mock_logs, ResponderOutcome::CapExhausted, 4);

    $restored = TrialResult::fromCache($trial->toCache());

    $this->assertSame($trial->toCache(), $restored->toCache());
    $this->assertTrue($restored->cached);
    $this->assertFalse($trial->cached);
    $this->assertSame('contract.commands.forbidden', $restored->checks[1]->id);
    $this->assertInstanceOf(JudgeCriterion::class, $restored->criteria[0]);
    $this->assertTrue($restored->criteria[1]->unknown);
    $this->assertSame($mock_logs, $restored->mockLogs);
    $this->assertSame(ResponderOutcome::CapExhausted, $restored->responderOutcome);
    $this->assertSame(4, $restored->followups);
  }

}
