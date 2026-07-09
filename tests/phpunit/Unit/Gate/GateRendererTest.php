<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Gate;

use AlexSkrypnyk\SkillTest\Gate\GateFinding;
use AlexSkrypnyk\SkillTest\Gate\GateRenderer;
use AlexSkrypnyk\SkillTest\Gate\GateReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class GateRendererTest.
 *
 * Unit test for rendering a gate report in each of the four output formats.
 */
#[CoversClass(GateRenderer::class)]
final class GateRendererTest extends TestCase {

  public function testHumanPassing(): void {
    $out = (new GateRenderer())->render(new GateReport(1.0, 1.0, 0.0, []), 'human');

    $this->assertStringContainsString('gate: PASS', $out);
    $this->assertStringContainsString('pass rate: 100% -> 100% (unchanged; 0 allowed).', $out);
    $this->assertStringContainsString('no regressions.', $out);
  }

  public function testHumanFailingListsFindings(): void {
    $report = new GateReport(1.0, 0.95, 0.0, [GateFinding::fail('regression', 'dropped 5 points')]);

    $out = (new GateRenderer())->render($report, 'human');

    $this->assertStringContainsString('gate: FAIL', $out);
    $this->assertStringContainsString('down 5 points', $out);
    $this->assertStringContainsString('findings:', $out);
    $this->assertStringContainsString('FAIL [regression] dropped 5 points', $out);
  }

  public function testUnknownFormatFallsBackToHuman(): void {
    $out = (new GateRenderer())->render(new GateReport(1.0, 1.0, 0.0, []), 'nonsense');

    $this->assertStringContainsString('gate: PASS', $out);
  }

  public function testJson(): void {
    $report = new GateReport(1.0, 0.9, 5.0, [GateFinding::fail('regression', 'dropped')]);

    $decoded = json_decode((new GateRenderer())->render($report, 'json'), TRUE);

    $this->assertSame('fail', $decoded['gate']);
    $this->assertSame(1.0, $decoded['baseline_pass_rate']);
    $this->assertSame(0.9, $decoded['current_pass_rate']);
    $this->assertSame(5.0, $decoded['max_regression']);
    $this->assertEqualsWithDelta(10.0, $decoded['drop'], 0.0001);
    $this->assertSame([['severity' => 'fail', 'category' => 'regression', 'message' => 'dropped']], $decoded['findings']);
  }

  public function testJsonPassingVerdict(): void {
    $decoded = json_decode((new GateRenderer())->render(new GateReport(1.0, 1.0, 0.0, []), 'json'), TRUE);

    $this->assertSame('pass', $decoded['gate']);
  }

  public function testMarkdownPassing(): void {
    $out = (new GateRenderer())->render(new GateReport(1.0, 1.0, 0.0, []), 'markdown');

    $this->assertStringContainsString('### skilltest gate: PASS', $out);
    $this->assertStringContainsString('No regressions.', $out);
  }

  public function testMarkdownFailingRendersTable(): void {
    $report = new GateReport(1.0, 0.9, 0.0, [GateFinding::fail('golden', 'golden gone'), GateFinding::warn('new-task', 'new one')]);

    $out = (new GateRenderer())->render($report, 'markdown');

    $this->assertStringContainsString('| Severity | Category | Finding |', $out);
    $this->assertStringContainsString('| FAIL | golden | golden gone |', $out);
    $this->assertStringContainsString('| WARN | new-task | new one |', $out);
  }

  public function testGithubActionsAnnotations(): void {
    $report = new GateReport(1.0, 0.9, 0.0, [GateFinding::fail('regression', 'rate 100% -> 90%'), GateFinding::warn('new-task', 'new one')]);

    $out = (new GateRenderer())->render($report, 'github-actions');

    $this->assertStringContainsString('::error title=skilltest gate::rate 100%25 -> 90%25', $out);
    $this->assertStringContainsString('::warning title=skilltest gate::new one', $out);
    $this->assertStringContainsString('::notice title=skilltest gate::gate failed', $out);
  }

  public function testGithubActionsPassingNotice(): void {
    $out = (new GateRenderer())->render(new GateReport(1.0, 1.0, 0.0, []), 'github-actions');

    $this->assertStringContainsString('::notice title=skilltest gate::gate passed', $out);
  }

  #[DataProvider('dataProviderDelta')]
  public function testDeltaPhrase(float $baseline, float $current, string $needle): void {
    $out = (new GateRenderer())->render(new GateReport($baseline, $current, 0.0, []), 'human');

    $this->assertStringContainsString($needle, $out);
  }

  public static function dataProviderDelta(): \Iterator {
    yield 'unchanged' => [1.0, 1.0, 'unchanged'];
    yield 'down' => [1.0, 0.9, 'down 10 points'];
    yield 'up' => [0.9, 1.0, 'up 10 points'];
  }

}
