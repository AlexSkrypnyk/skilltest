<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Gate;

use AlexSkrypnyk\SkillTest\Gate\Gate;
use AlexSkrypnyk\SkillTest\Gate\GateFinding;
use AlexSkrypnyk\SkillTest\Gate\GateOptions;
use AlexSkrypnyk\SkillTest\Results\ResultsDocument;
use AlexSkrypnyk\SkillTest\Results\TaskView;
use AlexSkrypnyk\SkillTest\Tests\Traits\ResultsDocumentTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class GateTest.
 *
 * Unit test for the regression gate engine: aggregate regression, golden tasks,
 * the minimal-model climb, and task-set drift policy.
 */
#[CoversClass(Gate::class)]
final class GateTest extends TestCase {

  use ResultsDocumentTrait;

  public function testEqualRunsPass(): void {
    $doc = $this->ratedDoc('alpha', 2, 0);

    $report = (new Gate())->compare($doc, $doc, $this->options(), []);

    $this->assertFalse($report->failed());
    $this->assertSame([], $report->findings);
  }

  #[DataProvider('dataProviderAggregateRegression')]
  public function testAggregateRegression(float $max, bool $fails): void {
    $baseline = $this->ratedDoc('alpha', 2, 0);
    $current = $this->ratedDoc('alpha', 1, 1);

    $report = (new Gate())->compare($current, $baseline, $this->options($max), []);

    $this->assertSame($fails, $report->failed());

    if ($fails) {
      $this->assertSame('regression', $report->findings[0]->category);
      $this->assertStringContainsString('100% -> 50%', $report->findings[0]->message);
    }
  }

  public static function dataProviderAggregateRegression(): \Iterator {
    yield 'zero tolerance fails on any drop' => [0.0, TRUE];
    yield 'drop equal to tolerance passes' => [50.0, FALSE];
    yield 'drop beyond tolerance fails' => [49.0, TRUE];
  }

  public function testImprovementNeverRegresses(): void {
    $baseline = $this->ratedDoc('alpha', 1, 1);
    $current = $this->ratedDoc('alpha', 2, 0);

    $report = (new Gate())->compare($current, $baseline, $this->options(), []);

    $this->assertFalse($report->failed());
  }

  public function testGoldenFailureOutranksPassingAggregate(): void {
    // A golden task that fails as a task in an otherwise identical run: the
    // aggregate does not move, yet the gate must still fail.
    $doc = $this->llmDoc('alpha', 'critical', [$this->modelEntry('haiku', [$this->trial(1, TRUE), $this->trial(2, TRUE), $this->trial(3, FALSE)])], 'haiku');

    $report = (new Gate())->compare($doc, $doc, $this->options(), [TaskView::key('alpha', 'critical')]);

    $this->assertEqualsWithDelta(0.0, $report->drop(), PHP_FLOAT_EPSILON);
    $this->assertTrue($report->failed());
    $this->assertCount(1, $report->findings);
    $this->assertSame('golden', $report->findings[0]->category);
    $this->assertStringContainsString("golden task 'alpha / critical' did not pass", $report->findings[0]->message);
  }

  public function testAbsentGoldenTaskFails(): void {
    $doc = $this->ratedDoc('alpha', 2, 0);

    $report = (new Gate())->compare($doc, $doc, $this->options(), [TaskView::key('alpha', 'ghost')]);

    $this->assertTrue($report->failed());
    $this->assertStringContainsString("golden task 'alpha / ghost' is absent", $report->findings[0]->message);
  }

  public function testPassingGoldenTaskAddsNoFinding(): void {
    $doc = $this->llmDoc('alpha', 'critical', [$this->modelEntry('haiku', [$this->trial(1, TRUE), $this->trial(2, TRUE), $this->trial(3, TRUE)])], 'haiku');

    $report = (new Gate())->compare($doc, $doc, $this->options(), [TaskView::key('alpha', 'critical')]);

    $this->assertFalse($report->failed());
    $this->assertSame([], $report->findings);
  }

  public function testMinimalModelClimbFails(): void {
    $baseline = $this->climbDoc('haiku');
    $current = $this->climbDoc('sonnet');

    $report = (new Gate())->compare($current, $baseline, $this->options(100.0), []);

    $climb = $this->findingOf($report->findings, 'minimal-model');
    $this->assertInstanceOf(GateFinding::class, $climb);
    $this->assertTrue($climb->failed());
    $this->assertStringContainsString("minimal model for 'alpha' climbed the ladder haiku -> sonnet", $climb->message);
  }

  #[DataProvider('dataProviderMinimalModelStableOrImprovedPasses')]
  public function testMinimalModelStableOrImprovedPasses(string $baseline_min, string $current_min): void {
    $baseline = $this->climbDoc($baseline_min);
    $current = $this->climbDoc($current_min);

    $report = (new Gate())->compare($current, $baseline, $this->options(100.0), []);

    $this->assertNotInstanceOf(GateFinding::class, $this->findingOf($report->findings, 'minimal-model'));
  }

  public static function dataProviderMinimalModelStableOrImprovedPasses(): \Iterator {
    yield 'unchanged' => ['sonnet', 'sonnet'];
    yield 'improved down the ladder' => ['sonnet', 'haiku'];
  }

  public function testMinimalModelToNoneFails(): void {
    $baseline = $this->climbDoc('haiku');
    $current = $this->climbDoc(NULL);

    $report = (new Gate())->compare($current, $baseline, $this->options(100.0), []);

    $climb = $this->findingOf($report->findings, 'minimal-model');
    $this->assertInstanceOf(GateFinding::class, $climb);
    $this->assertStringContainsString('haiku -> none', $climb->message);
  }

  public function testMinimalModelMergesBaselineOnlyLadderModel(): void {
    // The baseline ran a stronger model the current run dropped; the merged
    // ladder must carry it, and an unchanged minimal must not read as a climb.
    $baseline = $this->llmDoc('alpha', 'invoked', [$this->modelEntry('haiku', [$this->trial(1, TRUE)]), $this->modelEntry('sonnet', [$this->trial(1, TRUE)])], 'haiku');
    $current = $this->llmDoc('alpha', 'invoked', [$this->modelEntry('haiku', [$this->trial(1, TRUE)])], 'haiku');

    $report = (new Gate())->compare($current, $baseline, $this->options(100.0), []);

    $this->assertNotInstanceOf(GateFinding::class, $this->findingOf($report->findings, 'minimal-model'));
  }

  public function testMinimalModelUncomparableSkillIgnored(): void {
    // The skill only exists in the current run, so there is nothing to climb
    // from - it must not be reported.
    $baseline = $this->ratedDoc('alpha', 1, 0);
    $current = $this->climbDoc('sonnet');

    $report = (new Gate())->compare($current, $baseline, $this->options(100.0), []);

    $this->assertNotInstanceOf(GateFinding::class, $this->findingOf($report->findings, 'minimal-model'));
  }

  #[DataProvider('dataProviderTaskDrift')]
  public function testTaskDrift(string $policy, string $category, ?string $expected_severity): void {
    $with_task = $this->llmDoc('alpha', 'extra', [$this->modelEntry('haiku', [$this->trial(1, TRUE)])], 'haiku');
    $without_task = $this->llmDoc('alpha', 'base', [$this->modelEntry('haiku', [$this->trial(1, TRUE)])], 'haiku');

    // A new task is present in current but not baseline; a removed task is the
    // reverse - so the same pair drives both directions by swapping arguments.
    [$current, $baseline] = $category === 'new-task' ? [$with_task, $without_task] : [$without_task, $with_task];
    $options = $category === 'new-task' ? $this->options(100.0, $policy, 'allow') : $this->options(100.0, 'allow', $policy);

    $report = (new Gate())->compare($current, $baseline, $options, []);
    $finding = $this->findingOf($report->findings, $category);

    if ($expected_severity === NULL) {
      $this->assertNotInstanceOf(GateFinding::class, $finding);

      return;
    }

    $this->assertInstanceOf(GateFinding::class, $finding);
    $this->assertSame($expected_severity, $finding->severity);
  }

  public static function dataProviderTaskDrift(): \Iterator {
    yield 'new task allow is silent' => ['allow', 'new-task', NULL];
    yield 'new task warn surfaces' => ['warn', 'new-task', GateFinding::WARN];
    yield 'new task fail fails' => ['fail', 'new-task', GateFinding::FAIL];
    yield 'removed task allow is silent' => ['allow', 'removed-task', NULL];
    yield 'removed task warn surfaces' => ['warn', 'removed-task', GateFinding::WARN];
    yield 'removed task fail fails' => ['fail', 'removed-task', GateFinding::FAIL];
  }

  /**
   * A results document whose only skill carries rated deterministic checks.
   *
   * @param string $name
   *   The skill name.
   * @param int $pass
   *   The number of passing structure checks.
   * @param int $fail
   *   The number of failing structure checks.
   *
   * @return \AlexSkrypnyk\SkillTest\Results\ResultsDocument
   *   The document.
   */
  protected function ratedDoc(string $name, int $pass, int $fail): ResultsDocument {
    $structure = [];

    for ($i = 0; $i < $pass; $i++) {
      $structure[] = $this->check('structure.p' . $i, TRUE);
    }

    for ($i = 0; $i < $fail; $i++) {
      $structure[] = $this->check('structure.f' . $i, FALSE);
    }

    return new ResultsDocument($this->document(skills: [$this->skill($name, structure: $structure)]));
  }

  /**
   * A results document with one skill and one single-model llm task.
   *
   * @param string $skill
   *   The skill name.
   * @param string $task
   *   The task name.
   * @param array<int, array<mixed>> $models
   *   The task's model entries.
   * @param string|null $minimal
   *   The minimal-model verdict alias, or NULL.
   *
   * @return \AlexSkrypnyk\SkillTest\Results\ResultsDocument
   *   The document.
   */
  protected function llmDoc(string $skill, string $task, array $models, ?string $minimal): ResultsDocument {
    $llm = $this->llm([$this->multiModelTask($task, $models)], ['minimal_model' => $minimal, 'threshold' => 0.8, 'trials' => 3]);

    return new ResultsDocument($this->document(skills: [$this->skill($skill, llm: $llm)]));
  }

  /**
   * A results document over the haiku/sonnet ladder with a given minimal model.
   *
   * @param string|null $minimal
   *   The minimal-model verdict alias, or NULL for no supporting model.
   *
   * @return \AlexSkrypnyk\SkillTest\Results\ResultsDocument
   *   The document.
   */
  protected function climbDoc(?string $minimal): ResultsDocument {
    return $this->llmDoc('alpha', 'invoked', [
      $this->modelEntry('haiku', [$this->trial(1, TRUE)]),
      $this->modelEntry('sonnet', [$this->trial(1, TRUE)]),
    ], $minimal);
  }

  /**
   * Builds a gate options policy.
   *
   * @param float $max
   *   The regression tolerance in percentage points.
   * @param string $new
   *   The new-task drift policy.
   * @param string $removed
   *   The removed-task drift policy.
   *
   * @return \AlexSkrypnyk\SkillTest\Gate\GateOptions
   *   The policy.
   */
  protected function options(float $max = 0.0, string $new = 'warn', string $removed = 'warn'): GateOptions {
    return new GateOptions($max, $new, $removed);
  }

  /**
   * The first finding of a category, or NULL.
   *
   * @param \AlexSkrypnyk\SkillTest\Gate\GateFinding[] $findings
   *   The findings.
   * @param string $category
   *   The category to find.
   *
   * @return \AlexSkrypnyk\SkillTest\Gate\GateFinding|null
   *   The finding, or NULL.
   */
  protected function findingOf(array $findings, string $category): ?GateFinding {
    foreach ($findings as $finding) {
      if ($finding->category === $category) {
        return $finding;
      }
    }

    return NULL;
  }

}
