<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Live\ModelOutcome;
use AlexSkrypnyk\SkillTest\Live\TaskOutcome;
use AlexSkrypnyk\SkillTest\Live\TrialResult;
use AlexSkrypnyk\SkillTest\Tests\Traits\ArrayPathTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class TaskOutcomeTest.
 *
 * Unit test for the per-task outcome across models.
 */
#[CoversClass(TaskOutcome::class)]
final class TaskOutcomeTest extends TestCase {

  use ArrayPathTrait;

  public function testPassesOnlyWhenEveryModelPasses(): void {
    $task = new TaskOutcome('invoked', [self::model('haiku', TRUE), self::model('sonnet', TRUE)]);

    $this->assertTrue($task->passed());
  }

  public function testFailsWhenAnyModelFails(): void {
    $task = new TaskOutcome('invoked', [self::model('haiku', FALSE), self::model('sonnet', TRUE)]);

    $this->assertFalse($task->passed());
  }

  public function testEmptyModelsFail(): void {
    $task = new TaskOutcome('invoked', []);

    $this->assertFalse($task->passed());
  }

  public function testToArrayRendersTaskAndModels(): void {
    $task = new TaskOutcome('invoked', [self::model('haiku', TRUE)]);

    $row = $task->toArray();

    $this->assertSame('invoked', $row['task']);
    $this->assertSame('haiku', $this->path($row, 'models', 0, 'alias'));
  }

  /**
   * Builds a model outcome with a single passing or failing trial.
   *
   * @param string $alias
   *   The model alias.
   * @param bool $pass
   *   The trial verdict.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\ModelOutcome
   *   The model outcome, gated at a threshold of 1.0.
   */
  protected static function model(string $alias, bool $pass): ModelOutcome {
    return new ModelOutcome($alias, $alias, [new TrialResult(1, $pass, [], 0, 0, 0, 0.0, 0, '', 'artifacts/t.jsonl')], 1.0);
  }

}
