<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live\Matrix;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Config\Glob;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;

/**
 * The plan a `--estimate` prints instead of running: the shape and rough price.
 *
 * The matrix multiplies out to skills x tasks x trials x models trials, and a
 * full matrix over a real ladder can be expensive, so `--estimate` answers "how
 * much will this cost" before a token is spent. It counts the selected work per
 * skill and totals the trials, and puts a rough dollar figure on it from a
 * nominal per-trial price - a planning heuristic, not a quote; the real cost is
 * the agent-reported total the report prints after an actual run.
 */
final readonly class MatrixPlan {

  /**
   * The nominal per-trial cost, in USD, the rough estimate multiplies out.
   *
   * A deliberately round planning figure, not a measured price: no pre-run
   * token count exists, so the estimate scales this by the trial count to size
   * the run.
   */
  public const float NOMINAL_COST_PER_TRIAL = 0.05;

  /**
   * Constructs a MatrixPlan.
   *
   * @param array<int, array{skill: string, tasks: int, models: int, trials: int, total: int}> $skills
   *   One entry per selected skill: its matching task count, model count,
   *   trials per model, and the product of the three.
   * @param int $totalTrials
   *   The total number of trials the full plan would run.
   */
  public function __construct(
    public array $skills,
    public int $totalTrials,
  ) {}

  /**
   * Builds the plan from the selected configuration and task selection.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $config
   *   The loaded configuration, already narrowed to the selected skills.
   * @param string[] $task_globs
   *   The task-name globs; empty selects every declared task.
   *
   * @return self
   *   The estimate plan.
   */
  public static function fromConfig(LoadedConfig $config, array $task_globs): self {
    $skills = [];
    $total = 0;

    foreach ($config->skills as $skill) {
      $effective = $skill->effective;
      $tasks = self::countTasks($effective->tasks, $task_globs);

      if ($tasks === 0) {
        continue;
      }

      $models = count($effective->models);
      $trials = max(1, $effective->trials);
      $skill_total = $tasks * $models * $trials;

      $skills[] = ['skill' => $effective->skill, 'tasks' => $tasks, 'models' => $models, 'trials' => $trials, 'total' => $skill_total];
      $total += $skill_total;
    }

    return new self($skills, $total);
  }

  /**
   * The rough full-matrix price from the nominal per-trial cost.
   *
   * @return float
   *   The estimated cost in USD, rounded to cents.
   */
  public function roughCost(): float {
    return round($this->totalTrials * self::NOMINAL_COST_PER_TRIAL, 2);
  }

  /**
   * Counts the declared tasks whose name matches the selection globs.
   *
   * @param array<int, array<mixed>> $tasks
   *   The declared llm tasks.
   * @param string[] $globs
   *   The task-name globs; empty counts every named task.
   *
   * @return int
   *   The number of matching, named tasks.
   */
  protected static function countTasks(array $tasks, array $globs): int {
    $count = 0;

    foreach ($tasks as $task) {
      $name = Data::toStringOrNull(Data::get($task, 'name'));
      if ($name === NULL) {
        continue;
      }
      if ($name === '') {
        continue;
      }

      if ($globs !== [] && !Glob::matches($name, $globs)) {
        continue;
      }

      $count++;
    }

    return $count;
  }

}
