<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Judge;

use AlexSkrypnyk\SkillTest\Judge\Judge;
use AlexSkrypnyk\SkillTest\Judge\JudgeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class JudgeTest.
 *
 * Unit test for the judge orchestrator: evidence assembly, the pinned model,
 * and the judge-failure paths, all through an injected process seam so no token
 * is spent.
 */
#[CoversClass(Judge::class)]
final class JudgeTest extends TestCase {

  /**
   * The command the last judge invocation was given.
   */
  protected string $command = '';

  /**
   * The working directory the last judge invocation was given.
   */
  protected string $cwd = '';

  public function testScoresAndPinsTheModelWithFullEvidence(): void {
    $transcript = '{"type":"tool_use","name":"Bash","input":{"command":"git diff"}}' . "\n\n" . '{"type":"result","result":"Wrote the description.","num_turns":2}' . "\n";
    $judge = new Judge('claude', $this->runner([0, '{"criteria":[{"id":1,"pass":true},{"id":2,"pass":true}],"reasoning":"ok"}']));

    $verdict = $judge->evaluate(['names the issue', 'lists changes'], 'Write the PR description.', $transcript, 'haiku-judge', '/repo');

    $this->assertSame(2, $verdict->total());
    $this->assertSame(2, $verdict->passedCount());
    $this->assertSame('/repo', $this->cwd);
    $this->assertStringContainsString("--model 'haiku-judge'", $this->command);
    $this->assertStringContainsString('names the issue', $this->command);
    $this->assertStringContainsString('Write the PR description.', $this->command);
    $this->assertStringContainsString('git diff', $this->command);
    $this->assertStringContainsString('Wrote the description.', $this->command);
  }

  public function testEmptyTranscriptRendersNoneEvidence(): void {
    $judge = new Judge('claude', $this->runner([0, '{"criteria":[{"id":1,"pass":true}]}']));

    $judge->evaluate(['crit'], 'task', '{"type":"system","subtype":"init"}' . "\n", 'haiku', '/repo');

    $this->assertStringContainsString('TOOL CALLS:', $this->command);
    $this->assertStringContainsString('FINAL OUTPUT:', $this->command);
    $this->assertStringContainsString('(none)', $this->command);
  }

  public function testNonZeroExitIsAJudgeFailure(): void {
    $judge = new Judge('claude', $this->runner([1, 'stderr leaked to stdout']));

    $this->expectException(JudgeException::class);
    $this->expectExceptionMessage('exited with code 1');

    $judge->evaluate(['crit'], 'task', '{}', 'haiku', '/repo');
  }

  public function testUnparseableVerdictIsAJudgeFailure(): void {
    $judge = new Judge('claude', $this->runner([0, 'I cannot tell from the transcript.']));

    $this->expectException(JudgeException::class);

    $judge->evaluate(['crit'], 'task', '{}', 'haiku', '/repo');
  }

  /**
   * Builds a runner that records its command and returns a queued outcome.
   *
   * @param array{int, string} $outcome
   *   The `[exit, stdout]` the runner returns.
   *
   * @return \Closure
   *   The runner closure.
   */
  protected function runner(array $outcome): \Closure {
    return function (string $command, string $cwd) use ($outcome): array {
      $this->command = $command;
      $this->cwd = $cwd;

      return $outcome;
    };
  }

}
