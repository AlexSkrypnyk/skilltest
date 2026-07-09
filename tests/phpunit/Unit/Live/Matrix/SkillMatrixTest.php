<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live\Matrix;

use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Judge\JudgeCriterion;
use AlexSkrypnyk\SkillTest\Live\LlmSuite;
use AlexSkrypnyk\SkillTest\Live\Matrix\SkillMatrix;
use AlexSkrypnyk\SkillTest\Live\ModelOutcome;
use AlexSkrypnyk\SkillTest\Live\SkillOutcome;
use AlexSkrypnyk\SkillTest\Live\TaskOutcome;
use AlexSkrypnyk\SkillTest\Live\TrialResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class SkillMatrixTest.
 *
 * Unit test for one skill's matrix: grid rows, minimal verdict, failure modes.
 */
#[CoversClass(SkillMatrix::class)]
final class SkillMatrixTest extends TestCase {

  public function testBuildsRowsMinimalAndFailureModes(): void {
    $task = new TaskOutcome('invoked', [self::model('haiku', FALSE), self::model('sonnet', TRUE)]);
    $skill = new SkillOutcome('run', 'skills/run', [$task], 0.8, 1);

    $matrix = SkillMatrix::fromOutcome($skill);

    $this->assertSame(['haiku', 'sonnet'], array_map(static fn($row): string => $row->alias, $matrix->rows));
    $this->assertSame('sonnet', $matrix->minimal);
    $this->assertArrayHasKey('haiku', $matrix->failureModes);
    $this->assertArrayNotHasKey('sonnet', $matrix->failureModes);
    $this->assertSame('contract: contract.commands.forbidden (1x)', $matrix->failureModes['haiku']->describe());
    $this->assertNull($matrix->row('opus'));
    $this->assertSame('sonnet', $matrix->row('sonnet')?->alias);
  }

  public function testAggregatesModelPositionsAcrossTasks(): void {
    $tasks = [
      new TaskOutcome('one', [self::model('haiku', TRUE), self::model('sonnet', TRUE)]),
      new TaskOutcome('two', [self::model('haiku', FALSE), self::model('sonnet', TRUE)]),
    ];
    $skill = new SkillOutcome('run', 'skills/run', $tasks, 0.8, 1);

    $matrix = SkillMatrix::fromOutcome($skill);

    // Haiku holds on task one but not task two, so it does not support run.
    $this->assertSame('0.50', $matrix->row('haiku')?->rate());
    $this->assertFalse($matrix->row('haiku')?->passed);
    $this->assertSame('1.00', $matrix->row('sonnet')?->rate());
    $this->assertSame('sonnet', $matrix->minimal);
  }

  public function testThreadsRubricAndPolicyIntoFailureModes(): void {
    $trial = new TrialResult(1, FALSE, [self::ok(), CheckResult::fail(LlmSuite::CHECK_JUDGE_RUBRIC, 'judge rubric', '', '')], 0, 0, 0, 0.0, 0, '', 'artifacts/t.jsonl', [new JudgeCriterion(1, TRUE, FALSE), new JudgeCriterion(2, FALSE, FALSE)], 'opus');
    $skill = new SkillOutcome('run', 'skills/run', [new TaskOutcome('t', [new ModelOutcome('haiku', 'haiku', [$trial], 0.8)])], 0.8, 1, ['names the change', 'lists the files'], 'fail');

    $matrix = SkillMatrix::fromOutcome($skill);

    $this->assertSame('judge: lists the files (1x)', $matrix->failureModes['haiku']->describe());
  }

  public function testEmptyTasksYieldNoRowsAndNoMinimal(): void {
    $matrix = SkillMatrix::fromOutcome(new SkillOutcome('run', 'skills/run', [], 0.8, 3));

    $this->assertSame([], $matrix->rows);
    $this->assertNull($matrix->minimal);
  }

  /**
   * A passing contract check.
   *
   * @return \AlexSkrypnyk\SkillTest\Contract\CheckResult
   *   The check.
   */
  protected static function ok(): CheckResult {
    return CheckResult::pass('contract.tools.required', 'uses Bash', 'Bash', '');
  }

  /**
   * Builds a single-trial model outcome with a pass or fail verdict.
   *
   * @param string $alias
   *   The model alias.
   * @param bool $pass
   *   The trial verdict; a failing trial carries a forbidden-command check.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\ModelOutcome
   *   The model outcome.
   */
  protected static function model(string $alias, bool $pass): ModelOutcome {
    $check = $pass ? self::ok() : CheckResult::fail('contract.commands.forbidden', 'no push', 'git push', 'forbidden');

    return new ModelOutcome($alias, $alias, [new TrialResult(1, $pass, [$check], 0, 0, 0, 0.0, 0, '', 'artifacts/t.jsonl')], 0.8);
  }

}
