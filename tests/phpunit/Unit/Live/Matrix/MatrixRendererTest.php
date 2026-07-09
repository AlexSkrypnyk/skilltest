<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live\Matrix;

use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Live\Matrix\MatrixRenderer;
use AlexSkrypnyk\SkillTest\Live\Matrix\MatrixReport;
use AlexSkrypnyk\SkillTest\Live\LlmReport;
use AlexSkrypnyk\SkillTest\Live\ModelOutcome;
use AlexSkrypnyk\SkillTest\Live\SkillOutcome;
use AlexSkrypnyk\SkillTest\Live\TaskOutcome;
use AlexSkrypnyk\SkillTest\Live\TrialResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class MatrixRendererTest.
 *
 * Unit test for the terminal and markdown rendering of a matrix report.
 */
#[CoversClass(MatrixRenderer::class)]
final class MatrixRendererTest extends TestCase {

  public function testTerminalRendersGridVerdictFailureModesAndCost(): void {
    $lines = (new MatrixRenderer($this->twoSkillReport()))->render('text');

    $this->assertContains('run', $lines);
    $this->assertContains('  minimal model: sonnet (threshold 0.80, 1 trial (a 1-trial verdict is an estimate))', $lines);
    $this->assertContains('  haiku failure modes: contract: contract.commands.forbidden (1x)', $lines);
    $this->assertContains('  minimal sonnet $0.0200/run vs default sonnet $0.0200/run (saves $0.0000/run)', $lines);
    $this->assertContains('  minimal model: haiku (threshold 0.80, 1 trial (a 1-trial verdict is an estimate))', $lines);
    $this->assertContains('  minimal haiku $0.0100/run vs default sonnet $0.0200/run (saves $0.0100/run)', $lines);
    $this->assertContains('all skills', $lines);
    $this->assertContains('cost per model: haiku $0.0200, sonnet $0.0400, opus $0.0600. total $0.1200.', $lines);
  }

  public function testTerminalGridIsIndentedUnderTheSkill(): void {
    $out = implode("\n", (new MatrixRenderer($this->twoSkillReport()))->render('text'));

    $this->assertMatchesRegularExpression('/^  model +trials +contract +judge +pass rate +verdict$/m', $out);
    $this->assertMatchesRegularExpression('/  haiku +1 +\d\/\d +[-\d\/]+ +0\.00 +fail/', $out);
  }

  public function testMarkdownRendersHeadingsTablesBulletsAndBoldCost(): void {
    $lines = (new MatrixRenderer($this->twoSkillReport()))->render('markdown');
    $out = implode("\n", $lines);

    $this->assertContains('### run', $lines);
    $this->assertContains('### init', $lines);
    $this->assertContains('### all skills', $lines);
    $this->assertContains('| model | trials | contract | judge | pass rate | verdict |', $lines);
    $this->assertContains('| --- | --- | --- | --- | --- | --- |', $lines);
    $this->assertContains('- haiku failure modes: contract: contract.commands.forbidden (1x)', $lines);
    $this->assertContains('**cost per model: haiku $0.0200, sonnet $0.0400, opus $0.0600. total $0.1200.**', $lines);
    // Every markdown table row is a balanced pipe row.
    $this->assertStringNotContainsString('||', $out);
  }

  public function testSingleSkillOmitsTheRepoGrid(): void {
    $report = new LlmReport([$this->skill('solo', [$this->model('haiku', TRUE, 0.01)], 3)]);

    $lines = (new MatrixRenderer(MatrixReport::fromReport($report, 'haiku')))->render('text');

    $this->assertNotContains('all skills', $lines);
    $this->assertContains('  minimal model: haiku (threshold 0.80, 3 trials)', $lines);
  }

  public function testNoMinimalModelRendersNoVerdictAndNoDelta(): void {
    $report = new LlmReport([$this->skill('run', [$this->model('haiku', FALSE, 0.01), $this->model('sonnet', FALSE, 0.02)], 1)]);

    $lines = (new MatrixRenderer(MatrixReport::fromReport($report, 'sonnet')))->render('text');
    $out = implode("\n", $lines);

    $this->assertStringContainsString('no minimal model: no ladder model passed every task (threshold 0.80', $out);
    $this->assertStringNotContainsString('/run vs default', $out);
  }

  public function testDefaultNotInMatrixIsCalledOut(): void {
    $report = new LlmReport([$this->skill('run', [$this->model('haiku', TRUE, 0.01)], 1)]);

    $lines = (new MatrixRenderer(MatrixReport::fromReport($report, 'sonnet')))->render('text');
    $out = implode("\n", $lines);

    $this->assertStringContainsString('minimal haiku costs $0.0100/run (default sonnet was not run in this matrix)', $out);
  }

  public function testCostDeltaWhenTheMinimalCostsMoreThanTheDefault(): void {
    // Haiku is the minimal supporting model, pricier than the sonnet default.
    $report = new LlmReport([$this->skill('run', [$this->model('haiku', TRUE, 0.05), $this->model('sonnet', TRUE, 0.02)], 1)]);

    $out = implode("\n", (new MatrixRenderer(MatrixReport::fromReport($report, 'sonnet')))->render('text'));

    $this->assertStringContainsString('minimal haiku $0.0500/run vs default sonnet $0.0200/run (costs $0.0300/run more)', $out);
  }

  public function testNoDefaultConfiguredPricesTheMinimalAlone(): void {
    $report = new LlmReport([$this->skill('run', [$this->model('haiku', TRUE, 0.01)], 1)]);

    $out = implode("\n", (new MatrixRenderer(MatrixReport::fromReport($report, NULL)))->render('text'));

    $this->assertStringContainsString('minimal haiku costs $0.0100/run', $out);
  }

  /**
   * A two-skill report: run needs sonnet, init is haiku-safe.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\Matrix\MatrixReport
   *   The report with the repo default set to sonnet.
   */
  protected function twoSkillReport(): MatrixReport {
    $report = new LlmReport([
      $this->skill('run', [$this->model('haiku', FALSE, 0.01), $this->model('sonnet', TRUE, 0.02), $this->model('opus', TRUE, 0.03)], 1),
      $this->skill('init', [$this->model('haiku', TRUE, 0.01), $this->model('sonnet', TRUE, 0.02), $this->model('opus', TRUE, 0.03)], 1),
    ]);

    return MatrixReport::fromReport($report, 'sonnet');
  }

  /**
   * Builds a single-task skill outcome over a list of models.
   *
   * @param string $name
   *   The skill name.
   * @param \AlexSkrypnyk\SkillTest\Live\ModelOutcome[] $models
   *   The per-model outcomes.
   * @param int $trials
   *   The trials-per-model count carried in the verdict.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\SkillOutcome
   *   The skill outcome.
   */
  protected function skill(string $name, array $models, int $trials): SkillOutcome {
    return new SkillOutcome($name, 'skills/' . $name, [new TaskOutcome('t', $models)], 0.8, $trials);
  }

  /**
   * Builds a single-trial model outcome with a verdict and cost.
   *
   * @param string $alias
   *   The model alias.
   * @param bool $pass
   *   The trial verdict; a failing trial carries a forbidden-command check.
   * @param float $cost
   *   The trial cost.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\ModelOutcome
   *   The model outcome.
   */
  protected function model(string $alias, bool $pass, float $cost): ModelOutcome {
    $check = $pass ? CheckResult::pass('contract.x', 'x', 'e', '') : CheckResult::fail('contract.commands.forbidden', 'no push', 'git push', 'forbidden');

    return new ModelOutcome($alias, $alias, [new TrialResult(1, $pass, [$check], 0, 0, 0, $cost, 0, '', 'artifacts/t.jsonl')], 0.8);
  }

}
