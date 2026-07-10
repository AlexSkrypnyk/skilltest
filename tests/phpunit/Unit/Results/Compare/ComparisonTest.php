<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Results\Compare;

use AlexSkrypnyk\SkillTest\Results\Compare\Comparison;
use AlexSkrypnyk\SkillTest\Tests\Traits\ResultsDocumentTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class ComparisonTest.
 *
 * Unit test for the side-by-side arithmetic: aggregate, per-model, and per-task
 * series with a baseline-to-latest delta, and the null delta a figure missing
 * from one side produces.
 */
#[CoversClass(Comparison::class)]
#[Group('results')]
final class ComparisonTest extends TestCase {

  use ResultsDocumentTrait;

  public function testAggregateSeriesCarriesValuesAndDelta(): void {
    $before = $this->document([], [], [], ['checks' => 4, 'failures' => 2, 'cost_usd' => 0.10], ['duration_ms' => 1000]);
    $after = $this->document([], [], [], ['checks' => 4, 'failures' => 0, 'cost_usd' => 0.30], ['duration_ms' => 1600]);

    $comparison = Comparison::of([['label' => 'before', 'document' => $before], ['label' => 'after', 'document' => $after]]);

    $this->assertSame(['before', 'after'], $comparison->labels);
    $this->assertSame([0.5, 1.0], $comparison->aggregate['pass_rate']['values']);
    $this->assertEqualsWithDelta(0.5, $comparison->aggregate['pass_rate']['delta'], 0.0001);
    $this->assertSame([2, 0], $comparison->aggregate['failures']['values']);
    $this->assertSame(-2, $comparison->aggregate['failures']['delta']);
    $this->assertEqualsWithDelta(0.2, $comparison->aggregate['cost_usd']['delta'], 0.0001);
    $this->assertSame(600, $comparison->aggregate['duration_ms']['delta']);
  }

  public function testIdenticalDocumentsProduceZeroDeltas(): void {
    $document = $this->document([], [], [], ['checks' => 3, 'failures' => 1]);

    $comparison = Comparison::of([['label' => 'a', 'document' => $document], ['label' => 'b', 'document' => $document]]);

    $this->assertSame(0, $comparison->aggregate['failures']['delta']);
    $this->assertEqualsWithDelta(0.0, $comparison->aggregate['pass_rate']['delta'], 0.0001);
  }

  public function testPerModelUnionAndDelta(): void {
    $before = $this->document([$this->skill('alpha', [], [], [], $this->llm([$this->task('invoked', 'claude-haiku-4-5', 'haiku', [$this->trial(1, FALSE)], 0.0)]))]);
    $after = $this->document([$this->skill('alpha', [], [], [], $this->llm([$this->task('invoked', 'claude-haiku-4-5', 'haiku', [$this->trial(1, TRUE)], 1.0)]))]);

    $comparison = Comparison::of([['label' => 'before', 'document' => $before], ['label' => 'after', 'document' => $after]]);

    $this->assertArrayHasKey('haiku', $comparison->models);
    $this->assertSame([0.0, 1.0], $comparison->models['haiku']['pass_rate']['values']);
    $this->assertEqualsWithDelta(1.0, $comparison->models['haiku']['pass_rate']['delta'], 0.0001);
  }

  public function testMissingModelInOneRunGivesNullDelta(): void {
    $before = $this->document([$this->skill('alpha', [], [], [], $this->llm([$this->task('invoked', 'claude-haiku-4-5', 'haiku', [$this->trial(1, TRUE)], 1.0)]))]);
    $after = $this->document([$this->skill('alpha', [], [], [], $this->llm([$this->task('invoked', 'claude-sonnet-5', 'sonnet', [$this->trial(1, TRUE)], 1.0)]))]);

    $comparison = Comparison::of([['label' => 'before', 'document' => $before], ['label' => 'after', 'document' => $after]]);

    $this->assertNull($comparison->models['haiku']['pass_rate']['delta']);
    $this->assertNull($comparison->models['haiku']['pass_rate']['values'][1]);
    $this->assertNull($comparison->models['sonnet']['pass_rate']['delta']);
  }

  public function testPerTaskSeriesKeyedBySkillTaskModel(): void {
    $before = $this->document([$this->skill('alpha', [], [], [], $this->llm([$this->task('invoked', 'claude-haiku-4-5', 'haiku', [], 0.33)]))]);
    $after = $this->document([$this->skill('alpha', [], [], [], $this->llm([$this->task('invoked', 'claude-haiku-4-5', 'haiku', [], 1.0)]))]);

    $comparison = Comparison::of([['label' => 'before', 'document' => $before], ['label' => 'after', 'document' => $after]]);

    $this->assertArrayHasKey('alpha::invoked::haiku', $comparison->tasks);
    $this->assertEqualsWithDelta(0.67, $comparison->tasks['alpha::invoked::haiku']['delta'], 0.0001);
  }

  public function testThreeFileDeltaIsLastVersusFirst(): void {
    $one = $this->document([], [], [], ['checks' => 4, 'failures' => 4]);
    $two = $this->document([], [], [], ['checks' => 4, 'failures' => 2]);
    $three = $this->document([], [], [], ['checks' => 4, 'failures' => 1]);

    $comparison = Comparison::of([['label' => 'one', 'document' => $one], ['label' => 'two', 'document' => $two], ['label' => 'three', 'document' => $three]]);

    $this->assertSame([4, 2, 1], $comparison->aggregate['failures']['values']);
    $this->assertSame(-3, $comparison->aggregate['failures']['delta']);
  }

  public function testToArrayCarriesEverySection(): void {
    $document = $this->document([], [], [], ['checks' => 1, 'failures' => 0]);

    $array = Comparison::of([['label' => 'a', 'document' => $document], ['label' => 'b', 'document' => $document]])->toArray();

    $this->assertTrue($array['compare']);
    $this->assertSame(['a', 'b'], $array['labels']);
    $this->assertArrayHasKey('aggregate', $array);
    $this->assertArrayHasKey('models', $array);
    $this->assertArrayHasKey('tasks', $array);
  }

}
