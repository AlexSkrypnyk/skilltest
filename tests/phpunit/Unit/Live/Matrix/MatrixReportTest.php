<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live\Matrix;

use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Live\Matrix\MatrixReport;
use AlexSkrypnyk\SkillTest\Live\LlmReport;
use AlexSkrypnyk\SkillTest\Live\ModelOutcome;
use AlexSkrypnyk\SkillTest\Live\SkillOutcome;
use AlexSkrypnyk\SkillTest\Live\TaskOutcome;
use AlexSkrypnyk\SkillTest\Live\TrialResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class MatrixReportTest.
 *
 * Unit test for the repo-level matrix report: columns, grid, and costs.
 */
#[CoversClass(MatrixReport::class)]
final class MatrixReportTest extends TestCase {

  public function testRepoGridAndCostsAcrossSkills(): void {
    $report = new LlmReport([
      self::skill('run', [self::model('haiku', FALSE, 0.01), self::model('sonnet', TRUE, 0.02), self::model('opus', TRUE, 0.03)]),
      self::skill('init', [self::model('haiku', TRUE, 0.01), self::model('sonnet', TRUE, 0.02), self::model('opus', TRUE, 0.03)]),
    ]);

    $matrix = MatrixReport::fromReport($report, 'sonnet');

    $this->assertSame('sonnet', $matrix->defaultModel);
    $this->assertSame(['haiku', 'sonnet', 'opus'], $matrix->columns());

    $grid = $matrix->repoGrid();
    $this->assertSame(['run', '0.00', '1.00', '1.00', 'sonnet'], $grid[0]);
    $this->assertSame(['init', '1.00', '1.00', '1.00', 'haiku'], $grid[1]);

    $costs = $matrix->costPerModel();
    $this->assertEqualsWithDelta(0.02, $costs['haiku'], 1e-9);
    $this->assertEqualsWithDelta(0.04, $costs['sonnet'], 1e-9);
    $this->assertEqualsWithDelta(0.06, $costs['opus'], 1e-9);
    $this->assertEqualsWithDelta(0.12, $matrix->totalCost(), 1e-9);
  }

  public function testRaggedColumnsWhenSkillsRanDifferentModels(): void {
    $report = new LlmReport([
      self::skill('early', [self::model('haiku', FALSE), self::model('sonnet', TRUE)]),
      self::skill('full', [self::model('haiku', FALSE), self::model('sonnet', FALSE), self::model('opus', TRUE)]),
    ]);

    $matrix = MatrixReport::fromReport($report, NULL);

    $this->assertSame(['haiku', 'sonnet', 'opus'], $matrix->columns());
    $grid = $matrix->repoGrid();
    // The early skill stopped before opus, so its opus cell is blank.
    $this->assertSame(['early', '0.00', '1.00', '-', 'sonnet'], $grid[0]);
    $this->assertSame(['full', '0.00', '0.00', '1.00', 'opus'], $grid[1]);
    $this->assertNull($matrix->defaultModel);
  }

  public function testNoSupportingModelLeavesMinimalBlank(): void {
    $report = new LlmReport([self::skill('none', [self::model('haiku', FALSE), self::model('sonnet', FALSE)])]);

    $grid = MatrixReport::fromReport($report, NULL)->repoGrid();

    $this->assertSame(['none', '0.00', '0.00', '-'], $grid[0]);
  }

  /**
   * Builds a single-task skill outcome over a list of models.
   *
   * @param string $name
   *   The skill name.
   * @param \AlexSkrypnyk\SkillTest\Live\ModelOutcome[] $models
   *   The per-model outcomes.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\SkillOutcome
   *   The skill outcome.
   */
  protected static function skill(string $name, array $models): SkillOutcome {
    return new SkillOutcome($name, 'skills/' . $name, [new TaskOutcome('t', $models)], 0.8, 1);
  }

  /**
   * Builds a single-trial model outcome with a verdict and cost.
   *
   * @param string $alias
   *   The model alias.
   * @param bool $pass
   *   The trial verdict.
   * @param float $cost
   *   The trial cost.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\ModelOutcome
   *   The model outcome.
   */
  protected static function model(string $alias, bool $pass, float $cost = 0.0): ModelOutcome {
    $check = $pass ? CheckResult::pass('contract.x', 'x', 'e', '') : CheckResult::fail('contract.commands.forbidden', 'no push', 'git push', '');

    return new ModelOutcome($alias, $alias, [new TrialResult(1, $pass, [$check], 0, 0, 0, $cost, 0, '', 'artifacts/t.jsonl')], 0.8);
  }

}
