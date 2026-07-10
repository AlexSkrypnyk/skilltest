<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Results;

use AlexSkrypnyk\SkillTest\Results\ResultsDocument;
use AlexSkrypnyk\SkillTest\Results\ResultsException;
use AlexSkrypnyk\SkillTest\Results\TaskView;
use AlexSkrypnyk\SkillTest\Tests\Traits\ResultsDocumentTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class ResultsDocumentTest.
 *
 * Unit test for the shared results read model: parsing, version gating, the
 * aggregate pass rate, per-task verdicts, and the minimal-model ladder.
 */
#[CoversClass(ResultsDocument::class)]
final class ResultsDocumentTest extends TestCase {

  use ResultsDocumentTrait;

  /**
   * A temporary results file, removed on teardown.
   */
  protected string $file = '';

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->file !== '' && is_file($this->file)) {
      unlink($this->file);
      rmdir(dirname($this->file));
    }

    parent::tearDown();
  }

  public function testFromStringRejectsNonObject(): void {
    $this->expectException(ResultsException::class);
    $this->expectExceptionMessage('not a JSON object');

    ResultsDocument::fromString('"not an object"');
  }

  public function testFromStringRejectsMalformedJson(): void {
    $this->expectException(ResultsException::class);

    ResultsDocument::fromString('{not valid');
  }

  public function testFromStringRejectsJsonArray(): void {
    $this->expectException(ResultsException::class);
    $this->expectExceptionMessage('not a JSON object');

    ResultsDocument::fromString('[1, 2, 3]');
  }

  public function testFromFileRejectsMissingFile(): void {
    $this->expectException(ResultsException::class);
    $this->expectExceptionMessage('results file not found');

    ResultsDocument::fromFile('/does/not/exist.json');
  }

  public function testFromFileParsesDocument(): void {
    $dir = dirname(__DIR__, 3) . '/.artifacts/tmp/resultsdoc-' . getmypid() . '-' . uniqid();
    mkdir($dir, 0777, TRUE);
    $this->file = $dir . '/results.json';
    file_put_contents($this->file, json_encode($this->document()));

    $this->assertSame('1', ResultsDocument::fromFile($this->file)->version());
  }

  #[DataProvider('dataProviderVersionGating')]
  public function testVersionGating(string $version, bool $current): void {
    $document = $this->document();
    $document['version'] = $version;

    $this->assertSame($current, (new ResultsDocument($document))->isCurrentMajor());
  }

  public static function dataProviderVersionGating(): \Iterator {
    yield 'current major' => ['1', TRUE];
    yield 'current major minor' => ['1.4', TRUE];
    yield 'foreign major' => ['2', FALSE];
    yield 'malformed version' => ['banana', FALSE];
  }

  public function testMissingVersionIsTreatedAsCurrent(): void {
    $document = $this->document();
    unset($document['version']);

    $this->assertTrue((new ResultsDocument($document))->isCurrentMajor());
  }

  public function testEmptyDocumentRatesPerfect(): void {
    $this->assertEqualsWithDelta(1.0, (new ResultsDocument($this->document()))->passRate(), PHP_FLOAT_EPSILON);
  }

  public function testPassRateSpansChecksTrialsHooksAndCoverage(): void {
    $skill = $this->skill('alpha', structure: [$this->check('structure.frontmatter', TRUE)], llm: $this->llm([
      $this->multiModelTask('invoked', [
        $this->modelEntry('haiku', [$this->trial(1, TRUE), $this->trial(2, FALSE)]),
      ]),
    ]));

    $document = $this->document(
      skills: [$skill],
      hooks: [$this->check('hooks.guard', FALSE)],
      violations: [$this->check('coverage.eval-exists', FALSE)],
    );

    // 1 passing structure check + 1 passing trial out of: structure(1) +
    // trials(2) + hook(1) + coverage(1) = 2 passed of 5 units.
    $this->assertEqualsWithDelta(0.4, (new ResultsDocument($document))->passRate(), PHP_FLOAT_EPSILON);
  }

  public function testTasksRecomputeModelVerdictAgainstThreshold(): void {
    $skill = $this->skill('alpha', llm: $this->llm([
      $this->multiModelTask('invoked', [
        $this->modelEntry('haiku', [$this->trial(1, TRUE), $this->trial(2, TRUE), $this->trial(3, FALSE)]),
        $this->modelEntry('sonnet', [$this->trial(1, TRUE), $this->trial(2, TRUE), $this->trial(3, TRUE)]),
      ]),
    ], ['minimal_model' => 'sonnet', 'threshold' => 0.8, 'trials' => 3]));

    $tasks = (new ResultsDocument($this->document(skills: [$skill])))->tasks();
    $view = $tasks[TaskView::key('alpha', 'invoked')];

    // Haiku is 2/3 = 0.67 < 0.8; sonnet is 3/3 = 1.0.
    $this->assertFalse($view->modelPassed['haiku']);
    $this->assertTrue($view->modelPassed['sonnet']);
    $this->assertFalse($view->passed());
  }

  public function testEmptyTrialsFailUnlessThresholdIsZero(): void {
    $skill = $this->skill('alpha', llm: $this->llm([
      $this->multiModelTask('invoked', [$this->modelEntry('haiku', [])]),
    ], ['minimal_model' => NULL, 'threshold' => 0.0, 'trials' => 0]));

    $view = (new ResultsDocument($this->document(skills: [$skill])))->tasks()[TaskView::key('alpha', 'invoked')];

    $this->assertTrue($view->modelPassed['haiku']);
  }

  public function testMinimalModelsIncludeNullVerdicts(): void {
    $with = $this->skill('alpha', llm: $this->llm([$this->multiModelTask('t', [$this->modelEntry('haiku', [$this->trial(1, TRUE)])])], ['minimal_model' => 'haiku', 'threshold' => 0.8, 'trials' => 1]));
    $without = $this->skill('beta', llm: $this->llm([$this->multiModelTask('t', [$this->modelEntry('haiku', [$this->trial(1, FALSE)])])], ['minimal_model' => NULL, 'threshold' => 0.8, 'trials' => 1]));
    $novrd = $this->skill('gamma', llm: $this->llm([$this->multiModelTask('t', [$this->modelEntry('haiku', [$this->trial(1, TRUE)])])]));

    $minimals = (new ResultsDocument($this->document(skills: [$with, $without, $novrd])))->skillMinimalModels();

    $this->assertSame('haiku', $minimals['alpha']);
    $this->assertNull($minimals['beta']);
    $this->assertArrayNotHasKey('gamma', $minimals, 'A skill without a verdict is omitted.');
  }

  public function testLaddersPreserveModelOrderAndSkipDeterministicSkills(): void {
    $deterministic = $this->skill('chrome', structure: [$this->check('structure.frontmatter', TRUE)]);
    $skill = $this->skill('alpha', llm: $this->llm([
      $this->multiModelTask('invoked', [$this->modelEntry('haiku', [$this->trial(1, TRUE)]), $this->modelEntry('sonnet', [$this->trial(1, TRUE)])]),
    ]));

    $ladders = (new ResultsDocument($this->document(skills: [$deterministic, $skill])))->skillLadders();

    $this->assertSame(['haiku', 'sonnet'], $ladders['alpha']);
    $this->assertArrayNotHasKey('chrome', $ladders, 'A skill with no llm tasks has no ladder.');
  }

}
