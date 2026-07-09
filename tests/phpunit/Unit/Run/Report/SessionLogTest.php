<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Run\Report;

use AlexSkrypnyk\SkillTest\Run\Report\SessionLog;
use AlexSkrypnyk\SkillTest\Tests\Traits\ResultsDocumentTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class SessionLogTest.
 *
 * Unit test for the ordered NDJSON event stream built from a run.
 */
#[CoversClass(SessionLog::class)]
final class SessionLogTest extends TestCase {

  use ResultsDocumentTrait;

  public function testEmitsOrderedRunLifecycleEvents(): void {
    $document = $this->document(
      [$this->skill('alpha', [$this->check('structure.frontmatter', TRUE)], [], [$this->check('contract.commands.forbidden', FALSE, 'no pushes', 'git push origin main')])],
      [$this->check('hooks.guard', TRUE, 'blocks push', '{}')],
      [$this->check('coverage.eval-exists', FALSE)],
      ['checks' => 4, 'failures' => 2],
    );

    $events = (new SessionLog())->events($document);

    $names = array_map(static fn(array $event): mixed => $event['event'], $events);
    $this->assertSame(['run.started', 'check.finished', 'check.finished', 'hook.executed', 'grading.finished', 'run.finished'], $names);

    $this->assertSame(range(1, count($events)), array_map(static fn(array $event): mixed => $event['seq'], $events));
    $this->assertSame('2026-07-09T06:11:19+00:00', $events[0]['ts']);
    $this->assertSame('st-1', $events[0]['run']);

    $this->assertSame('alpha', $events[1]['skill']);
    $this->assertSame('structure', $events[1]['group']);
    $this->assertTrue($events[1]['pass']);
  }

  public function testCheckEventCarriesEvidenceOnlyWhenPresent(): void {
    $document = $this->document([$this->skill('alpha', [$this->check('structure.frontmatter', TRUE)], [], [$this->check('contract.forbidden', FALSE, 'no pushes', 'git push origin main')])]);

    $events = (new SessionLog())->events($document);

    $this->assertArrayNotHasKey('evidence', $events[1]);
    $this->assertSame('git push origin main', $events[2]['evidence']);
  }

  public function testHookEventCarriesEvidenceOnlyWhenPresent(): void {
    $document = $this->document([], [$this->check('hooks.a', TRUE), $this->check('hooks.b', FALSE, 'lbl', '{"tool":"Bash"}')]);

    $events = (new SessionLog())->events($document);
    $hooks = array_values(array_filter($events, static fn(array $event): bool => $event['event'] === 'hook.executed'));

    $this->assertArrayNotHasKey('evidence', $hooks[0]);
    $this->assertSame('{"tool":"Bash"}', $hooks[1]['evidence']);
  }

  public function testLlmTaskAndTrialEventsAreStreamed(): void {
    $llm = $this->llm([$this->task('invoked', 'claude-haiku-4-5', 'haiku', [$this->trial(1, TRUE), $this->trial(2, FALSE)])]);
    $document = $this->document([$this->skill('alpha', [], [], [], $llm)]);

    $events = (new SessionLog())->events($document);
    $streamed = array_values(array_filter($events, static fn(array $event): bool => in_array($event['event'], ['task.started', 'trial.finished'], TRUE)));

    $this->assertSame('task.started', $streamed[0]['event']);
    $this->assertSame('invoked', $streamed[0]['task']);
    $this->assertSame('trial.finished', $streamed[1]['event']);
    $this->assertSame('claude-haiku-4-5', $streamed[1]['model']);
    $this->assertSame(1, $streamed[1]['trial']);
    $this->assertTrue($streamed[1]['pass']);
    $this->assertFalse($streamed[2]['pass']);
  }

  public function testGradingEventCountsViolationsAndRunFinishedCarriesTotals(): void {
    $document = $this->document([], [], [$this->check('coverage.a', FALSE), $this->check('coverage.b', FALSE)], ['checks' => 2, 'failures' => 2]);

    $events = (new SessionLog())->events($document);
    $grading = $events[count($events) - 2];
    $finished = $events[count($events) - 1];

    $this->assertSame('grading.finished', $grading['event']);
    $this->assertSame(2, $grading['violations']);
    $this->assertSame('run.finished', $finished['event']);
    $this->assertSame(2, $finished['checks']);
    $this->assertSame(2, $finished['failures']);
  }

  public function testRunFinishedTimestampAddsTheDuration(): void {
    $document = $this->document([], [], [], [], ['started' => '2026-07-09T06:11:19+00:00', 'duration_ms' => 1500]);

    $events = (new SessionLog())->events($document);
    $finished = $events[count($events) - 1];

    $this->assertSame('2026-07-09T06:11:20+00:00', $finished['ts']);
  }

  public function testMalformedStartTimestampFallsBackToTheStart(): void {
    $document = $this->document([], [], [], [], ['started' => 'bogus', 'duration_ms' => 1500]);

    $events = (new SessionLog())->events($document);
    $finished = $events[count($events) - 1];

    $this->assertSame('bogus', $finished['ts']);
  }

  public function testEmptyStartTimestampYieldsAnEmptyEndTimestamp(): void {
    $document = $this->document([], [], [], [], ['started' => '', 'duration_ms' => 1500]);

    $events = (new SessionLog())->events($document);
    $finished = $events[count($events) - 1];

    $this->assertSame('', $finished['ts']);
  }

  public function testNdjsonSerialisesOneJsonEventPerLine(): void {
    $document = $this->document([$this->skill('alpha', [$this->check('structure.frontmatter', TRUE)])]);

    $ndjson = (new SessionLog())->ndjson($document);
    $lines = explode("\n", rtrim($ndjson, "\n"));

    $this->assertStringEndsWith("\n", $ndjson);
    $this->assertCount(4, $lines);

    $first = json_decode($lines[0], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertIsArray($first);
    $this->assertSame(1, $first['seq']);
    $this->assertSame('run.started', $first['event']);
  }

}
