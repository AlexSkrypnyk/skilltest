<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Results\Report;

use AlexSkrypnyk\SkillTest\Results\Report\HtmlReport;
use AlexSkrypnyk\SkillTest\Tests\Traits\ResultsDocumentTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class HtmlReportTest.
 *
 * Unit test for the self-contained HTML report: it is a complete document, its
 * per-skill drill-down and matrix grid render, its values are escaped, and it
 * references no external asset so a file:// open makes no network request.
 */
#[CoversClass(HtmlReport::class)]
#[Group('results')]
final class HtmlReportTest extends TestCase {

  use ResultsDocumentTrait;

  public function testRendersCompleteSelfContainedDocument(): void {
    $document = $this->document([$this->skill('alpha', [$this->check('structure.frontmatter', TRUE)])], [], [], ['checks' => 1, 'failures' => 0]);

    $html = (new HtmlReport())->render($document);

    $this->assertStringStartsWith('<!doctype html>', $html);
    $this->assertStringContainsString('<title>skilltest report st-1</title>', $html);
    $this->assertStringContainsString('<style>', $html);
    $this->assertStringContainsString('</html>', $html);
    $this->assertStringEndsWith("\n", $html);
  }

  public function testMakesNoExternalRequest(): void {
    $document = $this->document([$this->skill('alpha', [$this->check('structure.frontmatter', TRUE)])], [], [], ['checks' => 1, 'failures' => 0]);

    $html = (new HtmlReport())->render($document);

    $this->assertStringNotContainsString('http://', $html);
    $this->assertStringNotContainsString('https://', $html);
    $this->assertStringNotContainsString('<script', $html);
    $this->assertStringNotContainsString('<link', $html);
    $this->assertStringNotContainsString('src=', $html);
    $this->assertStringNotContainsString('href=', $html);
    $this->assertStringNotContainsString('@import', $html);
    $this->assertStringNotContainsString('url(', $html);
  }

  public function testSummaryTableShowsHeadlineFigures(): void {
    $document = $this->document([], [], [], ['checks' => 6, 'failures' => 2, 'trials' => 9, 'tokens' => ['in' => 500, 'out' => 200], 'cost_usd' => 0.42], ['duration_ms' => 8400]);

    $html = (new HtmlReport())->render($document);

    $this->assertStringContainsString('<span class="fail">FAIL</span> - 2 of 6 check(s) failed', $html);
    $this->assertStringContainsString('<th>Checks</th><td class="num">6</td>', $html);
    $this->assertStringContainsString('<th>Trials</th><td class="num">9</td>', $html);
    $this->assertStringContainsString('<th>Tokens</th><td class="num">500 in / 200 out</td>', $html);
    $this->assertStringContainsString('<th>Cost</th><td class="num">$0.4200</td>', $html);
    $this->assertStringContainsString('<th>Duration</th><td class="num">8400 ms</td>', $html);
  }

  public function testSkillDrillDownShowsCheckEvidenceAndOpensOnFailure(): void {
    $document = $this->document([
      $this->skill('alpha', [$this->check('structure.name', FALSE, 'name', 'skills/alpha', 'name mismatch')]),
      $this->skill('beta', [$this->check('structure.frontmatter', TRUE)]),
    ], [], [], ['checks' => 2, 'failures' => 1]);

    $html = (new HtmlReport())->render($document);

    $this->assertStringContainsString('<details open><summary>alpha', $html);
    $this->assertStringContainsString('<details><summary>beta', $html);
    $this->assertStringContainsString('<code>structure.name</code>', $html);
    $this->assertStringContainsString('name mismatch [skills/alpha]', $html);
  }

  public function testMatrixGridAndCostRenderWhenLlmPresent(): void {
    $document = $this->document([
      $this->skill('alpha', [], [], [], $this->llm([
        ['task' => 'invoked', 'models' => [['model' => 'claude-haiku-4-5', 'alias' => 'haiku', 'pass_rate' => 0.33, 'trials' => [$this->trial(1, FALSE, ['cost_usd' => 0.02])]], ['model' => 'claude-sonnet-5', 'alias' => 'sonnet', 'pass_rate' => 1.0, 'trials' => [$this->trial(1, TRUE, ['cost_usd' => 0.05])]]]],
      ], ['minimal_model' => 'sonnet', 'threshold' => 0.8, 'trials' => 3])),
    ], [], [], ['checks' => 2, 'failures' => 1, 'trials' => 2]);

    $html = (new HtmlReport())->render($document);

    $this->assertStringContainsString('<h2>Matrix</h2>', $html);
    $this->assertStringContainsString('<th>task</th><th>haiku</th><th>sonnet</th>', $html);
    $this->assertStringContainsString('minimal model: sonnet (threshold 0.80, 3 trial(s))', $html);
    $this->assertStringContainsString('<h2>Cost</h2>', $html);
    $this->assertStringContainsString('<th>total</th><td class="num">$0.0700</td>', $html);
  }

  public function testDeterministicRunHasNoMatrixOrCost(): void {
    $document = $this->document([$this->skill('alpha', [$this->check('structure.frontmatter', TRUE)])], [], [], ['checks' => 1, 'failures' => 0]);

    $html = (new HtmlReport())->render($document);

    $this->assertStringNotContainsString('<h2>Matrix</h2>', $html);
    $this->assertStringNotContainsString('<h2>Cost</h2>', $html);
  }

  public function testValuesAreEscaped(): void {
    $document = $this->document([$this->skill('alpha', [], [], [$this->check('contract.x', FALSE, '', '<script>alert("x")</script>', 'bad & wrong')])], [], [], ['checks' => 1, 'failures' => 1]);

    $html = (new HtmlReport())->render($document);

    $this->assertStringNotContainsString('<script>alert', $html);
    $this->assertStringContainsString('&lt;script&gt;', $html);
    $this->assertStringContainsString('bad &amp; wrong', $html);
  }

  public function testInterpretationIsEmbeddedWhenProvided(): void {
    $document = $this->document([], [], [], ['checks' => 1, 'failures' => 0]);

    $html = (new HtmlReport())->render($document, 'All good here.');

    $this->assertStringContainsString('<p class="interpret">All good here.</p>', $html);
  }

  public function testInterpretationOmittedWhenAbsent(): void {
    $document = $this->document([], [], [], ['checks' => 1, 'failures' => 0]);

    $html = (new HtmlReport())->render($document);

    $this->assertStringNotContainsString('class="interpret"', $html);
  }

  public function testSkillWithNoDeterministicChecksNotesIt(): void {
    $document = $this->document([$this->skill('alpha', [], [], [], $this->llm([$this->task('invoked', 'claude-sonnet-5', 'sonnet', [$this->trial(1, TRUE)], 1.0)]))], [], [], ['checks' => 1, 'failures' => 0, 'trials' => 1]);

    $html = (new HtmlReport())->render($document);

    $this->assertStringContainsString('No deterministic checks.', $html);
  }

}
