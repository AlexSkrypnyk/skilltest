<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Results;

use AlexSkrypnyk\SkillTest\Results\TaskView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class TaskViewTest.
 *
 * Unit test for the saved-task view: its composite key and pass verdict.
 */
#[CoversClass(TaskView::class)]
final class TaskViewTest extends TestCase {

  public function testKeyJoinsSkillAndTask(): void {
    $this->assertSame("alpha\tinvoked", TaskView::key('alpha', 'invoked'));
  }

  #[DataProvider('dataProviderPassed')]
  public function testPassed(array $model_passed, bool $expected): void {
    $this->assertSame($expected, (new TaskView('alpha', 'invoked', $model_passed))->passed());
  }

  public static function dataProviderPassed(): \Iterator {
    yield 'no models fails' => [[], FALSE];
    yield 'single passing model passes' => [['haiku' => TRUE], TRUE];
    yield 'all models passing passes' => [['haiku' => TRUE, 'sonnet' => TRUE], TRUE];
    yield 'one failing model fails' => [['haiku' => FALSE, 'sonnet' => TRUE], FALSE];
    yield 'all failing fails' => [['haiku' => FALSE], FALSE];
  }

}
