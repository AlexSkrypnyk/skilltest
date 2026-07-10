<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Results\Report;

use AlexSkrypnyk\SkillTest\Results\Report\ReportRenderer;
use AlexSkrypnyk\SkillTest\Tests\Traits\ResultsDocumentTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class ReportRendererTest.
 *
 * Unit test for the terminal report: the status line, the summary counts, the
 * ordered failures, and the matrix grid and cost totals shown only when the run
 * carried llm results.
 */
#[CoversClass(ReportRenderer::class)]
#[Group('results')]
final class ReportRendererTest extends TestCase {

  use ResultsDocumentTrait;

  public function testPassingDeterministicRunShowsStatusAndSummary(): void {
    $document = $this->document([$this->skill('alpha', [$this->check('structure.frontmatter', TRUE)])], [], [], ['checks' => 3, 'failures' => 0]);

    $text = implode("\n", (new ReportRenderer())->text($document));

    $this->assertStringContainsString('PASS - all 3 check(s) passed (run st-1, run, host)', $text);
    $this->assertStringContainsString('checks: 3', $text);
    $this->assertStringContainsString('passed: 3', $text);
    $this->assertStringContainsString('failed: 0', $text);
    $this->assertStringNotContainsString('matrix', $text);
  }

  public function testFailingRunListsOrderedFailures(): void {
    $document = $this->document(
      [$this->skill('alpha', [$this->check('structure.name', FALSE, 'name', '', 'name mismatch')], [], [$this->check('contract.x', FALSE, '', 'git push', 'forbidden')])],
      [],
      [],
      ['checks' => 2, 'failures' => 2],
    );

    $text = implode("\n", (new ReportRenderer())->text($document));

    $this->assertStringContainsString('FAIL - 2 of 2 check(s) failed', $text);
    $this->assertStringContainsString('failures', $text);
    $this->assertStringContainsString('structure.name (alpha) - name mismatch', $text);
    $this->assertStringContainsString('contract.x (alpha) - forbidden [git push]', $text);
  }

  public function testLlmRunShowsGridVerdictAndCost(): void {
    $document = $this->document([
      $this->skill('alpha', [], [], [], $this->llm([
        ['task' => 'invoked', 'models' => [['model' => 'claude-haiku-4-5', 'alias' => 'haiku', 'pass_rate' => 0.33, 'trials' => [$this->trial(1, FALSE, ['cost_usd' => 0.02])]], ['model' => 'claude-sonnet-5', 'alias' => 'sonnet', 'pass_rate' => 1.0, 'trials' => [$this->trial(1, TRUE, ['cost_usd' => 0.05])]]]],
      ], ['minimal_model' => 'sonnet', 'threshold' => 0.8, 'trials' => 3])),
    ], [], [], ['checks' => 2, 'failures' => 1, 'trials' => 2, 'tokens' => ['in' => 100, 'out' => 40], 'cost_usd' => 0.07]);

    $text = implode("\n", (new ReportRenderer())->text($document));

    $this->assertStringContainsString('trials: 2', $text);
    $this->assertStringContainsString('tokens: 100 in / 40 out', $text);
    $this->assertStringContainsString('matrix', $text);
    $this->assertMatchesRegularExpression('/task +haiku +sonnet/', $text);
    $this->assertMatchesRegularExpression('/invoked +33% +100%/', $text);
    $this->assertStringContainsString('minimal model: sonnet (threshold 0.80, 3 trial(s))', $text);
    $this->assertStringContainsString('cost per model: haiku $0.0200, sonnet $0.0500. total $0.0700.', $text);
  }

  public function testMatrixSkipsSkillsWithoutLlmTasks(): void {
    $document = $this->document([
      $this->skill('alpha', [$this->check('structure.frontmatter', TRUE)]),
      $this->skill('beta', [], [], [], $this->llm([$this->task('invoked', 'claude-sonnet-5', 'sonnet', [$this->trial(1, TRUE, ['cost_usd' => 0.05])], 1.0)], ['minimal_model' => 'sonnet', 'threshold' => 0.8, 'trials' => 3])),
    ], [], [], ['checks' => 2, 'failures' => 0, 'trials' => 1]);

    $text = implode("\n", (new ReportRenderer())->text($document));

    $this->assertStringContainsString('matrix', $text);
    $this->assertStringContainsString('beta', $text);
    $this->assertMatchesRegularExpression('/invoked +100%/', $text);
  }

  public function testLlmFailureLineNamesTaskModelAndThreshold(): void {
    $document = $this->document([
      $this->skill('alpha', [], [], [], $this->llm([$this->task('invoked', 'claude-haiku-4-5', 'haiku', [$this->trial(1, FALSE)], 0.33)], ['minimal_model' => NULL, 'threshold' => 0.8, 'trials' => 3])),
    ], [], [], ['checks' => 1, 'failures' => 1, 'trials' => 1]);

    $text = implode("\n", (new ReportRenderer())->text($document));

    $this->assertStringContainsString('invoked (alpha) llm on haiku: 33% < 80%', $text);
  }

}
