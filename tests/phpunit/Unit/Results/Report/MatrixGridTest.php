<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Results\Report;

use AlexSkrypnyk\SkillTest\Results\Report\MatrixGrid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class MatrixGridTest.
 *
 * Unit test for the shared task-by-model grid arithmetic: the column ordering,
 * the pass-rate cells with a dash for a model a task skipped, and the
 * minimal-model verdict line.
 */
#[CoversClass(MatrixGrid::class)]
#[Group('results')]
final class MatrixGridTest extends TestCase {

  public function testColumnsAreFirstSeenAcrossTasks(): void {
    $tasks = [
      ['task' => 'invoked', 'models' => [['alias' => 'haiku'], ['alias' => 'sonnet']]],
      ['task' => 'refined', 'models' => [['alias' => 'sonnet'], ['alias' => 'opus']]],
    ];

    $this->assertSame(['haiku', 'sonnet', 'opus'], MatrixGrid::columns($tasks));
  }

  public function testColumnsFallBackToModelIdWhenNoAlias(): void {
    $tasks = [['task' => 'invoked', 'models' => [['model' => 'claude-haiku-4-5']]]];

    $this->assertSame(['claude-haiku-4-5'], MatrixGrid::columns($tasks));
  }

  public function testCellsAlignToColumnsWithDashForMissing(): void {
    $task = ['task' => 'invoked', 'models' => [['alias' => 'haiku', 'pass_rate' => 0.33], ['alias' => 'sonnet', 'pass_rate' => 1.0]]];

    $this->assertSame(['33%', '100%', '-'], MatrixGrid::cells($task, ['haiku', 'sonnet', 'opus']));
  }

  public function testCellsRenderDashForAbsentPassRate(): void {
    $task = ['task' => 'invoked', 'models' => [['alias' => 'haiku']]];

    $this->assertSame(['-'], MatrixGrid::cells($task, ['haiku']));
  }

  public function testVerdictLineNamesTheMinimalModel(): void {
    $skill = ['llm' => ['verdict' => ['minimal_model' => 'sonnet', 'threshold' => 0.8, 'trials' => 3]]];

    $this->assertSame('minimal model: sonnet (threshold 0.80, 3 trial(s))', MatrixGrid::verdictLine($skill));
  }

  public function testVerdictLineHandlesNoMinimalModel(): void {
    $skill = ['llm' => ['verdict' => ['minimal_model' => NULL, 'threshold' => 0.8, 'trials' => 3]]];

    $this->assertSame('no minimal model (threshold 0.80, 3 trial(s))', MatrixGrid::verdictLine($skill));
  }

}
