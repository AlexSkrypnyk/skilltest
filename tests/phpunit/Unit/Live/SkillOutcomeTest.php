<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Live\ModelOutcome;
use AlexSkrypnyk\SkillTest\Live\SkillOutcome;
use AlexSkrypnyk\SkillTest\Live\TaskOutcome;
use AlexSkrypnyk\SkillTest\Live\TrialResult;
use AlexSkrypnyk\SkillTest\Tests\Traits\ArrayPathTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class SkillOutcomeTest.
 *
 * Unit test for the per-skill outcome and minimal-model verdict.
 */
#[CoversClass(SkillOutcome::class)]
final class SkillOutcomeTest extends TestCase {

  use ArrayPathTrait;

  public function testMinimalModelIsTheWeakestThatPassesEveryTask(): void {
    $tasks = [
      new TaskOutcome('a', [self::model('haiku', FALSE), self::model('sonnet', TRUE)]),
      new TaskOutcome('b', [self::model('haiku', TRUE), self::model('sonnet', TRUE)]),
    ];
    $skill = new SkillOutcome('run', 'skills/run', $tasks, 0.8, 3);

    $this->assertSame('sonnet', $skill->minimalModel());
  }

  public function testMinimalModelIsNullWhenNoModelPassesEveryTask(): void {
    $tasks = [
      new TaskOutcome('a', [self::model('haiku', FALSE), self::model('sonnet', FALSE)]),
    ];
    $skill = new SkillOutcome('run', 'skills/run', $tasks, 0.8, 3);

    $this->assertNull($skill->minimalModel());
  }

  public function testMinimalModelIsNullWithoutTasks(): void {
    $skill = new SkillOutcome('run', 'skills/run', [], 0.8, 3);

    $this->assertNull($skill->minimalModel());
  }

  public function testWeakestPassingModelWins(): void {
    $tasks = [
      new TaskOutcome('a', [self::model('haiku', TRUE), self::model('sonnet', TRUE)]),
    ];
    $skill = new SkillOutcome('run', 'skills/run', $tasks, 0.8, 3);

    $this->assertSame('haiku', $skill->minimalModel());
  }

  public function testToArrayCarriesTasksAndVerdict(): void {
    $tasks = [new TaskOutcome('a', [self::model('sonnet', TRUE)])];
    $skill = new SkillOutcome('run', 'skills/run', $tasks, 0.8, 3);

    $row = $skill->toArray();

    $this->assertSame('run', $row['skill']);
    $this->assertSame('skills/run', $row['path']);
    $this->assertSame('a', $this->path($row, 'llm', 'tasks', 0, 'task'));
    $this->assertSame('sonnet', $this->path($row, 'llm', 'verdict', 'minimal_model'));
    $this->assertEqualsWithDelta(0.8, $this->path($row, 'llm', 'verdict', 'threshold'), PHP_FLOAT_EPSILON);
    $this->assertSame(3, $this->path($row, 'llm', 'verdict', 'trials'));
  }

  /**
   * Builds a model outcome whose single trial has the given verdict.
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
