<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Run\Report;

use AlexSkrypnyk\SkillTest\Run\Report\GithubCommentReporter;
use AlexSkrypnyk\SkillTest\Tests\Traits\ResultsDocumentTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class GithubCommentReporterTest.
 *
 * Unit test for rendering a results document as a GitHub PR comment block.
 */
#[CoversClass(GithubCommentReporter::class)]
final class GithubCommentReporterTest extends TestCase {

  use ResultsDocumentTrait;

  public function testPassingRunRendersStatusAndSummaryTable(): void {
    $document = $this->document([$this->skill('alpha', [$this->check('structure.frontmatter', TRUE)])], [], [], ['checks' => 3, 'failures' => 0]);

    $markdown = (new GithubCommentReporter())->render($document);

    $this->assertStringContainsString('### skilltest results', $markdown);
    $this->assertStringContainsString('✅ All 3 checks passed - run `st-1` (run, host)', $markdown);
    $this->assertStringContainsString('| Metric | Value |', $markdown);
    $this->assertStringContainsString('| --- | --- |', $markdown);
    $this->assertStringContainsString('| Checks | 3 |', $markdown);
    $this->assertStringContainsString('| Passed | 3 |', $markdown);
    $this->assertStringContainsString('| Failed | 0 |', $markdown);
    $this->assertStringNotContainsString('#### Failures', $markdown);
    $this->assertStringEndsWith("\n", $markdown);
  }

  public function testFailuresAreExpandedWithScopeDetailAndEvidence(): void {
    $document = $this->document(
      [$this->skill('alpha', [], [], [$this->check('contract.commands.forbidden', FALSE, 'no git pushes', 'git push origin main', 'forbidden behaviour matched')])],
      [$this->check('hooks.guard', FALSE, 'blocks push', '{}', 'allowed but must block')],
      [$this->check('coverage.eval-exists', FALSE, '', '', "skill 'gamma' has no eval.yaml")],
      ['checks' => 3, 'failures' => 3],
    );

    $markdown = (new GithubCommentReporter())->render($document);

    $this->assertStringContainsString('❌ 3 of 3 checks failed', $markdown);
    $this->assertStringContainsString('#### Failures', $markdown);
    $this->assertStringContainsString('- `contract.commands.forbidden` (alpha.transcript) - forbidden behaviour matched  `git push origin main`', $markdown);
    $this->assertStringContainsString('- `hooks.guard` (hooks) - allowed but must block', $markdown);
    $this->assertStringContainsString('- `coverage.eval-exists` (coverage)', $markdown);
  }

  public function testFailureDetailFallsBackToLabelWhenNoMessage(): void {
    $document = $this->document([$this->skill('alpha', [$this->check('structure.name', FALSE, 'name matches dir')])], [], [], ['checks' => 1, 'failures' => 1]);

    $markdown = (new GithubCommentReporter())->render($document);

    $this->assertStringContainsString('- `structure.name` (alpha.structure) - name matches dir', $markdown);
  }

  public function testTrialTokenAndCostRowsAppearWhenPresent(): void {
    $document = $this->document([], [], [], ['checks' => 1, 'failures' => 0, 'trials' => 6, 'tokens' => ['in' => 120, 'out' => 340], 'cost_usd' => 0.42]);

    $markdown = (new GithubCommentReporter())->render($document);

    $this->assertStringContainsString('| Trials | 6 |', $markdown);
    $this->assertStringContainsString('| Tokens | 120 in / 340 out |', $markdown);
    $this->assertStringContainsString('| Cost | $0.42 |', $markdown);
  }

  public function testMatrixGridRendersWhenLlmPresent(): void {
    $llm = [
      'tasks' => [
        ['task' => 'invoked', 'models' => [['model' => 'claude-haiku-4-5', 'alias' => 'haiku', 'pass_rate' => 0.33, 'trials' => []], ['model' => 'claude-sonnet-5', 'alias' => 'sonnet', 'pass_rate' => 1.0, 'trials' => []]]],
        ['task' => 'refined', 'models' => [['model' => 'claude-sonnet-5', 'alias' => 'sonnet', 'pass_rate' => 0.5, 'trials' => []]]],
      ],
      'verdict' => ['minimal_model' => 'sonnet', 'threshold' => 0.8, 'trials' => 3],
    ];
    $document = $this->document([$this->skill('alpha', [], [], [], $llm)], [], [], ['checks' => 0, 'failures' => 0, 'trials' => 3]);

    $markdown = (new GithubCommentReporter())->render($document);

    $this->assertStringContainsString('#### Matrix: alpha', $markdown);
    $this->assertStringContainsString('Minimal model: **sonnet** (threshold 0.8, 3 trials)', $markdown);
    $this->assertStringContainsString('| Task | haiku | sonnet |', $markdown);
    $this->assertStringContainsString('| invoked | 33% | 100% |', $markdown);
    $this->assertStringContainsString('| refined | - | 50% |', $markdown);
  }

  public function testMatrixVerdictHandlesNoMinimalModel(): void {
    $llm = $this->llm([$this->task('invoked', 'claude-haiku-4-5', 'haiku', [], 0.2)], ['minimal_model' => NULL, 'threshold' => 0.8, 'trials' => 3]);
    $document = $this->document([$this->skill('alpha', [], [], [], $llm)]);

    $markdown = (new GithubCommentReporter())->render($document);

    $this->assertStringContainsString('Minimal model: **none**', $markdown);
    $this->assertStringContainsString('| invoked | 20% |', $markdown);
  }

  public function testMissingPassRateRendersDash(): void {
    $llm = $this->llm([$this->task('invoked', 'claude-haiku-4-5', 'haiku', [])]);
    $document = $this->document([$this->skill('alpha', [], [], [], $llm)]);

    $markdown = (new GithubCommentReporter())->render($document);

    $this->assertStringContainsString('| invoked | - |', $markdown);
  }

  public function testTrialFailuresAppearInFailuresSection(): void {
    $llm = $this->llm([$this->task('invoked', 'claude-haiku-4-5', 'haiku', [$this->trial(1, TRUE), $this->trial(2, FALSE, ['judge' => [['criterion' => 2, 'pass' => FALSE]]])])]);
    $document = $this->document([$this->skill('alpha', [], [], [], $llm)], [], [], ['checks' => 0, 'failures' => 1]);

    $markdown = (new GithubCommentReporter())->render($document);

    $this->assertStringContainsString('- `invoked.haiku.trial-2` (alpha.llm) - failed: judge criteria 2  `claude-haiku-4-5`', $markdown);
    $this->assertStringNotContainsString('trial-1', $markdown);
  }

  public function testEvidenceBackticksAndNewlinesAreNeutralised(): void {
    $document = $this->document([$this->skill('alpha', [], [], [$this->check('contract.x', FALSE, '', "a `b`\nc", 'msg')])], [], [], ['checks' => 1, 'failures' => 1]);

    $markdown = (new GithubCommentReporter())->render($document);

    $this->assertStringContainsString("`a 'b' c`", $markdown);
    $this->assertStringNotContainsString("a `b`\nc", $markdown);
  }

  public function testOverflowIsTruncatedToTheCommentLimitWithNote(): void {
    $huge = str_repeat('a', GithubCommentReporter::LIMIT + 5000);
    $document = $this->document([$this->skill('alpha', [], [], [$this->check('contract.x', FALSE, '', '', $huge)])], [], [], ['checks' => 1, 'failures' => 1]);

    $markdown = (new GithubCommentReporter())->render($document);

    $this->assertSame(GithubCommentReporter::LIMIT, mb_strlen($markdown));
    $this->assertStringContainsString('truncated to fit', $markdown);
  }

  public function testWithinLimitIsNotTruncated(): void {
    $document = $this->document([$this->skill('alpha', [$this->check('structure.frontmatter', TRUE)])], [], [], ['checks' => 1, 'failures' => 0]);

    $markdown = (new GithubCommentReporter())->render($document);

    $this->assertLessThan(GithubCommentReporter::LIMIT, mb_strlen($markdown));
    $this->assertStringNotContainsString('truncated to fit', $markdown);
  }

}
