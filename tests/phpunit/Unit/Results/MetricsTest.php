<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Results;

use AlexSkrypnyk\SkillTest\Results\Metrics;
use AlexSkrypnyk\SkillTest\Tests\Traits\ResultsDocumentTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class MetricsTest.
 *
 * Unit test for the shared document arithmetic: the aggregate headline figures,
 * the per-model and per-task folds, and the ordered failure enumeration.
 */
#[CoversClass(Metrics::class)]
#[Group('results')]
final class MetricsTest extends TestCase {

  use ResultsDocumentTrait;

  public function testAggregateReadsTotalsAndRunDuration(): void {
    $document = $this->document([], [], [], ['checks' => 10, 'failures' => 3, 'trials' => 12, 'tokens' => ['in' => 500, 'out' => 200], 'cost_usd' => 0.42], ['duration_ms' => 8400]);

    $aggregate = Metrics::aggregate($document);

    $this->assertSame(10, $aggregate['checks']);
    $this->assertSame(3, $aggregate['failures']);
    $this->assertSame(7, $aggregate['passed']);
    $this->assertEqualsWithDelta(0.7, $aggregate['pass_rate'], 0.0001);
    $this->assertSame(12, $aggregate['trials']);
    $this->assertSame(500, $aggregate['tokens_in']);
    $this->assertSame(200, $aggregate['tokens_out']);
    $this->assertEqualsWithDelta(0.42, $aggregate['cost_usd'], 0.0001);
    $this->assertSame(8400, $aggregate['duration_ms']);
  }

  public function testAggregatePassRateIsZeroWhenNoChecksRan(): void {
    $aggregate = Metrics::aggregate($this->document());

    $this->assertSame(0, $aggregate['checks']);
    $this->assertEqualsWithDelta(0.0, $aggregate['pass_rate'], PHP_FLOAT_EPSILON);
  }

  public function testPerModelFoldsTrialsAcrossTasks(): void {
    $document = $this->document([
      $this->skill('alpha', [], [], [], $this->llm([
        ['task' => 'invoked', 'models' => [$this->modelRow('haiku', [$this->trial(1, TRUE, $this->cost(100, 40, 0.01, 500)), $this->trial(2, FALSE, $this->cost(120, 50, 0.02, 700))])]],
        ['task' => 'refined', 'models' => [$this->modelRow('haiku', [$this->trial(1, TRUE, $this->cost(80, 30, 0.015, 400))])]],
      ])),
    ]);

    $models = Metrics::perModel($document);

    $this->assertArrayHasKey('haiku', $models);
    $this->assertSame(3, $models['haiku']['trials']);
    $this->assertSame(2, $models['haiku']['passed']);
    $this->assertEqualsWithDelta(2 / 3, $models['haiku']['pass_rate'], 0.0001);
    $this->assertSame(300, $models['haiku']['tokens_in']);
    $this->assertSame(120, $models['haiku']['tokens_out']);
    $this->assertEqualsWithDelta(0.045, $models['haiku']['cost_usd'], 0.0001);
    $this->assertSame(1600, $models['haiku']['duration_ms']);
  }

  public function testPerModelIsZeroRateWithNoTrials(): void {
    $document = $this->document([
      $this->skill('alpha', [], [], [], $this->llm([['task' => 'invoked', 'models' => [$this->modelRow('haiku', [])]]])),
    ]);

    $this->assertEqualsWithDelta(0.0, Metrics::perModel($document)['haiku']['pass_rate'], PHP_FLOAT_EPSILON);
  }

  public function testPerTaskKeysBySkillTaskAndModelAlias(): void {
    $document = $this->document([
      $this->skill('alpha', [], [], [], $this->llm([
        ['task' => 'invoked', 'models' => [$this->modelRow('haiku', [], 0.33), $this->modelRow('sonnet', [], 1.0)]],
      ])),
    ]);

    $rates = Metrics::perTask($document);

    $this->assertEqualsWithDelta(0.33, $rates['alpha::invoked::haiku'], 0.0001);
    $this->assertEqualsWithDelta(1.0, $rates['alpha::invoked::sonnet'], 0.0001);
  }

  public function testPerTaskIsZeroWhenNoStoredRateAndNoTrials(): void {
    $document = $this->document([
      $this->skill('alpha', [], [], [], $this->llm([
        ['task' => 'invoked', 'models' => [['model' => 'claude-haiku-4-5', 'alias' => 'haiku', 'trials' => []]]],
      ])),
    ]);

    $this->assertEqualsWithDelta(0.0, Metrics::perTask($document)['alpha::invoked::haiku'], PHP_FLOAT_EPSILON);
  }

  public function testPerTaskFallsBackToTrialRateWhenNoStoredRate(): void {
    $document = $this->document([
      $this->skill('alpha', [], [], [], $this->llm([
        ['task' => 'invoked', 'models' => [['model' => 'claude-haiku-4-5', 'alias' => 'haiku', 'trials' => [$this->trial(1, TRUE), $this->trial(2, FALSE)]]]],
      ])),
    ]);

    $this->assertEqualsWithDelta(0.5, Metrics::perTask($document)['alpha::invoked::haiku'], 0.0001);
  }

  public function testFailuresAreOrderedMostBlockingFirst(): void {
    $document = $this->document(
      [
        $this->skill('alpha',
          [$this->check('structure.frontmatter', FALSE)],
          [$this->check('security.danger', FALSE)],
          [$this->check('contract.commands.required', FALSE)],
          $this->llm([$this->task('invoked', 'claude-haiku-4-5', 'haiku', [], 0.2)], ['minimal_model' => NULL, 'threshold' => 0.8, 'trials' => 3]),
        ),
      ],
      [$this->check('hooks.guard', FALSE)],
      [$this->check('coverage.eval-exists', FALSE)],
    );

    $kinds = array_column(Metrics::failures($document), 'kind');

    $this->assertSame(['security', 'structure', 'transcript', 'hooks', 'coverage', 'llm'], $kinds);
  }

  public function testFailuresIgnorePassingChecks(): void {
    $document = $this->document([
      $this->skill('alpha', [$this->check('structure.frontmatter', TRUE)], [], [$this->check('contract.x', FALSE, 'label', 'ev', 'msg')]),
    ]);

    $failures = Metrics::failures($document);

    $this->assertCount(1, $failures);
    $this->assertSame('transcript', $failures[0]['kind']);
    $this->assertSame('contract.x', $failures[0]['id']);
    $this->assertSame('ev', $failures[0]['evidence']);
    $this->assertSame('msg', $failures[0]['message']);
    $this->assertSame('alpha', $failures[0]['scope']);
  }

  public function testLlmFailureCarriesTaskModelAndThreshold(): void {
    $document = $this->document([
      $this->skill('alpha', [], [], [], $this->llm([$this->task('invoked', 'claude-haiku-4-5', 'haiku', [], 0.33)], ['minimal_model' => NULL, 'threshold' => 0.8, 'trials' => 3])),
    ]);

    $failures = Metrics::failures($document);

    $this->assertCount(1, $failures);
    $this->assertSame('llm', $failures[0]['kind']);
    $this->assertSame('invoked', $failures[0]['task']);
    $this->assertSame('haiku', $failures[0]['model']);
    $this->assertEqualsWithDelta(0.33, $failures[0]['pass_rate'], 0.0001);
    $this->assertEqualsWithDelta(0.8, $failures[0]['threshold'], 0.0001);
  }

  public function testLlmModelMeetingThresholdIsNotFailure(): void {
    $document = $this->document([
      $this->skill('alpha', [], [], [], $this->llm([$this->task('invoked', 'claude-sonnet-5', 'sonnet', [], 1.0)], ['minimal_model' => 'sonnet', 'threshold' => 0.8, 'trials' => 3])),
    ]);

    $this->assertSame([], Metrics::failures($document));
  }

  public function testLlmUsesDefaultThresholdWhenVerdictAbsent(): void {
    $document = $this->document([
      $this->skill('alpha', [], [], [], ['tasks' => [$this->task('invoked', 'claude-haiku-4-5', 'haiku', [], 0.5)]]),
    ]);

    $failures = Metrics::failures($document);

    $this->assertCount(1, $failures);
    $this->assertEqualsWithDelta(0.8, $failures[0]['threshold'], 0.0001);
  }

  public function testEmptyDocumentHasNoModelsTasksOrFailures(): void {
    $document = $this->document();

    $this->assertSame([], Metrics::perModel($document));
    $this->assertSame([], Metrics::perTask($document));
    $this->assertSame([], Metrics::failures($document));
  }

  /**
   * Builds one model row with trials and an optional stored pass rate.
   *
   * @param string $alias
   *   The model alias, also used as the id suffix.
   * @param array<int, array<mixed>> $trials
   *   The trial rows.
   * @param float|null $pass_rate
   *   The stored pass rate, or NULL to omit it.
   *
   * @return array<string, mixed>
   *   The model row.
   */
  protected function modelRow(string $alias, array $trials, ?float $pass_rate = NULL): array {
    $row = ['model' => 'claude-' . $alias, 'alias' => $alias, 'trials' => $trials];

    if ($pass_rate !== NULL) {
      $row['pass_rate'] = $pass_rate;
    }

    return $row;
  }

  /**
   * Builds the trial cost keys: tokens, cost, and duration.
   *
   * @param int $in
   *   Input tokens.
   * @param int $out
   *   Output tokens.
   * @param float $cost
   *   Cost in USD.
   * @param int $duration
   *   Duration in milliseconds.
   *
   * @return array<string, mixed>
   *   The extra trial keys.
   */
  protected function cost(int $in, int $out, float $cost, int $duration): array {
    return ['tokens' => ['in' => $in, 'out' => $out], 'cost_usd' => $cost, 'duration_ms' => $duration];
  }

}
