<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Live\LlmReport;
use AlexSkrypnyk\SkillTest\Live\ModelOutcome;
use AlexSkrypnyk\SkillTest\Live\SkillOutcome;
use AlexSkrypnyk\SkillTest\Live\TaskOutcome;
use AlexSkrypnyk\SkillTest\Live\TrialResult;
use AlexSkrypnyk\SkillTest\Tests\Traits\ArrayPathTrait;
use AlexSkrypnyk\SkillTest\Tests\Traits\SchemaValidationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class LlmReportTest.
 *
 * Unit test for the aggregated llm run outcome and its results document.
 */
#[CoversClass(LlmReport::class)]
final class LlmReportTest extends TestCase {

  use ArrayPathTrait;
  use SchemaValidationTrait;

  public function testAggregatesGatesFailuresAndTotals(): void {
    $report = $this->report();

    $this->assertSame(2, $report->gates());
    $this->assertSame(1, $report->failures());
    $this->assertTrue($report->failed());
    $this->assertSame(3, $report->trials());
    $this->assertSame(['in' => 60, 'out' => 30], $report->tokens());
    $this->assertEqualsWithDelta(0.06, $report->cost(), PHP_FLOAT_EPSILON);
  }

  public function testPassingReportDoesNotFail(): void {
    $trials = [new TrialResult(1, TRUE, [], 10, 5, 1, 0.01, 100, 'a', 'artifacts/a.jsonl')];
    $model = new ModelOutcome('m', 'm', $trials, 0.8);
    $skill = new SkillOutcome('s', 'skills/s', [new TaskOutcome('t', [$model])], 0.8, 1);

    $report = new LlmReport([$skill]);

    $this->assertFalse($report->failed());
    $this->assertSame(0, $report->failures());
  }

  public function testArtifactsMapEveryTranscriptByPath(): void {
    $artifacts = $this->report()->artifacts();

    $this->assertSame('good-out', $artifacts['artifacts/pass-1.jsonl']);
    $this->assertSame('bad-out', $artifacts['artifacts/fail-1.jsonl']);
    $this->assertCount(3, $artifacts);
  }

  public function testToResultsMatchesSchema(): void {
    $document = $this->report()->toResults('1', ['name' => 'skilltest', 'version' => '1.0.0'], [
      'id' => 'st-20260709-1200',
      'started' => '2026-07-09T12:00:00+00:00',
      'duration_ms' => 4200,
      'command' => 'llm',
      'environment' => 'host',
    ]);

    $this->assertSame(2, $this->path($document, 'totals', 'checks'));
    $this->assertSame(1, $this->path($document, 'totals', 'failures'));
    $this->assertSame(3, $this->path($document, 'totals', 'trials'));
    $this->assertSame(['in' => 60, 'out' => 30], $this->path($document, 'totals', 'tokens'));
    $this->assertSame([], $document['hooks']);
    $this->assertSame([], $this->path($document, 'coverage', 'violations'));
    $this->assertMatchesResultsSchema((string) json_encode($document));
  }

  /**
   * Builds a report with one passing and one failing task-model verdict.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\LlmReport
   *   The report.
   */
  protected function report(): LlmReport {
    $passing = new ModelOutcome('claude-sonnet-5', 'sonnet', [
      new TrialResult(1, TRUE, [], 30, 15, 5, 0.03, 2000, 'good-out', 'artifacts/pass-1.jsonl'),
    ], 0.8);

    $failing = new ModelOutcome('claude-haiku-4-5', 'haiku', [
      new TrialResult(1, TRUE, [], 10, 5, 2, 0.01, 900, 'ok-out', 'artifacts/mix-1.jsonl'),
      new TrialResult(2, FALSE, [], 20, 10, 3, 0.02, 950, 'bad-out', 'artifacts/fail-1.jsonl'),
    ], 0.8);

    $skill = new SkillOutcome('run', 'skills/run', [
      new TaskOutcome('invoked', [$passing]),
      new TaskOutcome('discovery', [$failing]),
    ], 0.8, 1);

    return new LlmReport([$skill]);
  }

}
