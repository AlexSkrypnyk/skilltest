<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live\Matrix;

use AlexSkrypnyk\SkillTest\Config\EffectiveConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedSkill;
use AlexSkrypnyk\SkillTest\Config\RepoConfig;
use AlexSkrypnyk\SkillTest\Live\Matrix\MatrixPlan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class MatrixPlanTest.
 *
 * Unit test for the `--estimate` plan: work counts and the rough price.
 */
#[CoversClass(MatrixPlan::class)]
final class MatrixPlanTest extends TestCase {

  public function testMultipliesTasksModelsAndTrials(): void {
    $config = self::config([self::skill('run', ['invoked', 'discovery'], 3)]);

    $plan = MatrixPlan::fromConfig($config, []);

    $this->assertCount(1, $plan->skills);
    $this->assertSame(['skill' => 'run', 'tasks' => 2, 'models' => 3, 'trials' => 3, 'total' => 18], $plan->skills[0]);
    $this->assertSame(18, $plan->totalTrials);
    $this->assertEqualsWithDelta(0.90, $plan->roughCost(), 1e-9);
  }

  public function testTaskGlobNarrowsTheCount(): void {
    $config = self::config([self::skill('run', ['invoked', 'discovery'], 3)]);

    $plan = MatrixPlan::fromConfig($config, ['invoked']);

    $this->assertSame(1, $plan->skills[0]['tasks']);
    $this->assertSame(9, $plan->totalTrials);
  }

  public function testSkillsWithNoMatchingTasksAreOmitted(): void {
    $config = self::config([self::skill('run', ['invoked'], 3), self::skill('empty', [], 3)]);

    $plan = MatrixPlan::fromConfig($config, []);

    $this->assertCount(1, $plan->skills);
    $this->assertSame('run', $plan->skills[0]['skill']);
  }

  public function testNamelessTasksAreNotCounted(): void {
    $eval = ['llm' => ['models' => 'ladder', 'trials' => 3, 'tasks' => [['name' => 'invoked', 'prompt' => 'go'], ['prompt' => 'no name here']]]];
    $skill = new LoadedSkill('skills/run/eval.yaml', $eval, EffectiveConfig::resolve(self::repo(), $eval, [], 'run', 'skills/run'));

    $plan = MatrixPlan::fromConfig(new LoadedConfig(self::repo(), [], '', [$skill]), []);

    $this->assertSame(1, $plan->skills[0]['tasks']);
  }

  public function testAGlobMatchingNothingYieldsAnEmptyPlan(): void {
    $config = self::config([self::skill('run', ['invoked'], 3)]);

    $plan = MatrixPlan::fromConfig($config, ['nope-*']);

    $this->assertSame([], $plan->skills);
    $this->assertSame(0, $plan->totalTrials);
    $this->assertSame(0.0, $plan->roughCost());
  }

  /**
   * Wraps loaded skills in a loaded config over a laddered repo.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill[] $skills
   *   The loaded skills.
   *
   * @return \AlexSkrypnyk\SkillTest\Config\LoadedConfig
   *   The loaded config.
   */
  protected static function config(array $skills): LoadedConfig {
    return new LoadedConfig(self::repo(), [], '', $skills);
  }

  /**
   * Builds a loaded skill whose eval declares the ladder and the given tasks.
   *
   * @param string $name
   *   The skill name.
   * @param string[] $tasks
   *   The task names to declare.
   * @param int $trials
   *   The trials per model.
   *
   * @return \AlexSkrypnyk\SkillTest\Config\LoadedSkill
   *   The loaded skill.
   */
  protected static function skill(string $name, array $tasks, int $trials): LoadedSkill {
    $declared = array_map(static fn(string $task): array => ['name' => $task, 'prompt' => 'go'], $tasks);
    $eval = ['llm' => ['models' => 'ladder', 'trials' => $trials, 'tasks' => $declared]];
    $effective = EffectiveConfig::resolve(self::repo(), $eval, [], $name, 'skills/' . $name);

    return new LoadedSkill('skills/' . $name . '/eval.yaml', $eval, $effective);
  }

  /**
   * A repo config with a three-model ladder.
   *
   * @return \AlexSkrypnyk\SkillTest\Config\RepoConfig
   *   The repo config.
   */
  protected static function repo(): RepoConfig {
    return RepoConfig::fromArray(['models' => ['aliases' => ['haiku' => 'h', 'sonnet' => 's', 'opus' => 'o'], 'ladder' => ['haiku', 'sonnet', 'opus'], 'default' => 'sonnet']]);
  }

}
