<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Grade;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Config\EffectiveConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedSkill;
use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Contract\TranscriptGrader;
use AlexSkrypnyk\SkillTest\Judge\Judge;
use AlexSkrypnyk\SkillTest\Judge\JudgeCriterion;
use AlexSkrypnyk\SkillTest\Judge\JudgeException;
use AlexSkrypnyk\SkillTest\Judge\JudgeVerdict;
use AlexSkrypnyk\SkillTest\Judge\UnknownPolicy;
use AlexSkrypnyk\SkillTest\Live\LlmSuite;
use AlexSkrypnyk\SkillTest\Results\ResultsDocument;

/**
 * Re-scores a saved llm run against the current contract, offline.
 *
 * The engine behind `skilltest grade --results`. It walks every trial in a
 * saved document and re-asserts the current contract and custom checks against
 * the trial's own transcript artifact - so a tightened contract turns a run that
 * passed into the failures it would now record, without executing a single
 * agent. The runtime-only failures a transcript cannot reproduce (a non-zero
 * agent exit, a mock miss, a responder abstention) are preserved from the saved
 * verdict, so re-grading never resurrects a trial that failed for a reason the
 * offline evidence does not carry. The judge dimension is reused from the saved
 * verdict unless a judge is supplied, in which case each trial is re-judged
 * against the current rubric. After re-scoring, per-model pass rates, the
 * minimal-model verdict, and the failure total are all rebuilt so the document
 * stays internally consistent.
 */
final readonly class Grader {

  /**
   * Constructs a Grader.
   *
   * @param string $root
   *   The repository root, the working directory custom checks run under and the
   *   directory the judge is invoked from.
   * @param \AlexSkrypnyk\SkillTest\Judge\Judge|null $judge
   *   The judge to re-score rubrics with, or NULL to reuse the saved verdict.
   */
  public function __construct(
    protected string $root,
    protected ?Judge $judge = NULL,
  ) {}

  /**
   * Re-scores a saved results document against the current configuration.
   *
   * @param array<string, mixed> $document
   *   The saved results document.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $config
   *   The current configuration, supplying each skill's contract, checks,
   *   rubric, and thresholds.
   * @param string $base_dir
   *   The directory the trial transcript artifacts are resolved against.
   *
   * @return \AlexSkrypnyk\SkillTest\Grade\RescoreResult
   *   The re-scored document and the summary of what moved.
   */
  public function rescore(array $document, LoadedConfig $config, string $base_dir): RescoreResult {
    $skills = Data::toArrayList(Data::get($document, 'skills'));
    $state = ['rescored' => 0, 'newly_failing' => 0, 'newly_passing' => 0, 'notes' => []];

    foreach ($skills as $index => $skill_entry) {
      $name = Data::toStringOrNull(Data::get($skill_entry, 'skill')) ?? '';
      $loaded = $this->matchSkill($config, $name);

      if (!$loaded instanceof LoadedSkill) {
        if (Data::toArrayList(Data::get($skill_entry, 'llm', 'tasks')) !== []) {
          $state['notes'][] = sprintf("skill '%s' is not in the current config; left unchanged.", $name);
        }

        continue;
      }

      $skills[$index] = $this->rescoreSkill($skill_entry, $loaded, $config->repo->aliases, $base_dir, $state);
    }

    $document['skills'] = $skills;
    $document = $this->recomputeFailures($document);

    return new RescoreResult($document, $state['rescored'], $state['newly_failing'], $state['newly_passing'], $state['notes']);
  }

  /**
   * Re-scores one skill's tasks, then rebuilds its rates and verdict.
   *
   * @param array<mixed> $skill_entry
   *   The skill entry to re-score.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $loaded
   *   The matching configured skill.
   * @param array<string, string> $aliases
   *   The repo command aliases.
   * @param string $base_dir
   *   The transcript artifact base directory.
   * @param array{rescored: int, newly_failing: int, newly_passing: int, notes: string[]} $state
   *   The mutable re-grade accumulator.
   *
   * @return array<mixed>
   *   The re-scored skill entry.
   */
  protected function rescoreSkill(array $skill_entry, LoadedSkill $loaded, array $aliases, string $base_dir, array &$state): array {
    $tasks = Data::toArrayList(Data::get($skill_entry, 'llm', 'tasks'));

    if ($tasks === []) {
      return $skill_entry;
    }

    $effective = $loaded->effective;
    $threshold = Data::toFloatOrNull(Data::get($skill_entry, 'llm', 'verdict', 'threshold')) ?? $effective->threshold;

    foreach ($tasks as $t => $task_entry) {
      $prompt = $this->taskPrompt($effective, Data::toStringOrNull(Data::get($task_entry, 'task')) ?? '');
      $models = Data::toArrayList(Data::get($task_entry, 'models'));

      foreach ($models as $m => $model_entry) {
        $trials = Data::toArrayList(Data::get($model_entry, 'trials'));

        foreach ($trials as $i => $trial) {
          $trials[$i] = $this->rescoreTrial($trial, $loaded, $aliases, $base_dir, $prompt, $state);
        }

        $model_entry['trials'] = $trials;
        $model_entry['pass_rate'] = round($this->passRate($trials), 2);
        $models[$m] = $model_entry;
      }

      $task_entry['models'] = $models;
      $tasks[$t] = $task_entry;
    }

    $llm = Data::toArray(Data::get($skill_entry, 'llm'));
    $llm['tasks'] = $tasks;

    if (isset($llm['verdict']) && is_array($llm['verdict'])) {
      $verdict = $llm['verdict'];
      $verdict['minimal_model'] = $this->minimalModel($tasks, $threshold);
      $llm['verdict'] = $verdict;
    }

    $skill_entry['llm'] = $llm;

    return $skill_entry;
  }

  /**
   * Re-scores one trial's contract and judge dimensions from its transcript.
   *
   * @param array<mixed> $trial
   *   The saved trial row.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $loaded
   *   The matching configured skill.
   * @param array<string, string> $aliases
   *   The repo command aliases.
   * @param string $base_dir
   *   The transcript artifact base directory.
   * @param string|null $prompt
   *   The task prompt for re-judging, or NULL when the task left the config.
   * @param array{rescored: int, newly_failing: int, newly_passing: int, notes: string[]} $state
   *   The mutable re-grade accumulator.
   *
   * @return array<mixed>
   *   The re-scored trial row.
   */
  protected function rescoreTrial(array $trial, LoadedSkill $loaded, array $aliases, string $base_dir, ?string $prompt, array &$state): array {
    $reference = Data::toStringOrNull(Data::get($trial, 'transcript'));

    if ($reference === NULL) {
      $state['notes'][] = 'a trial carries no transcript artifact; left unchanged.';

      return $trial;
    }

    $was_pass = (bool) Data::get($trial, 'pass');
    $path = $this->resolvePath($base_dir, $reference);
    $effective = $loaded->effective;

    $graded = (new TranscriptGrader($this->root, $aliases))->grade($path, $effective->contract, $effective->checks, dirname($loaded->file));
    $rows = array_map(static fn(CheckResult $result): array => $result->toCheckRow(), $graded);
    $rows = array_merge($rows, $this->runtimeRows(Data::toArrayList(Data::get($trial, 'contract'))));

    [$criteria, $block] = $this->judgeDimension($trial, $effective, $prompt, $path, $state);

    if ($block !== NULL) {
      $rows[] = $block;
    }

    $pass = array_reduce($rows, static fn(bool $carry, array $row): bool => $carry && (bool) ($row['pass'] ?? FALSE), TRUE);

    $trial['contract'] = $rows;
    $trial['judge'] = array_map(static fn(JudgeCriterion $criterion): array => $criterion->toArray(), $criteria);
    $trial['unknowns'] = count(array_filter($criteria, static fn(JudgeCriterion $criterion): bool => $criterion->unknown));
    $trial['pass'] = $pass;

    $state['rescored']++;
    $state['newly_failing'] += $was_pass && !$pass ? 1 : 0;
    $state['newly_passing'] += !$was_pass && $pass ? 1 : 0;

    return $trial;
  }

  /**
   * Re-scores the judge dimension: the criteria and any blocking check.
   *
   * @param array<mixed> $trial
   *   The saved trial row, carrying the stored judge criteria.
   * @param \AlexSkrypnyk\SkillTest\Config\EffectiveConfig $effective
   *   The skill's effective configuration.
   * @param string|null $prompt
   *   The task prompt for re-judging.
   * @param string $path
   *   The trial transcript path, the evidence a re-judge scores.
   * @param array{rescored: int, newly_failing: int, newly_passing: int, notes: string[]} $state
   *   The mutable re-grade accumulator.
   *
   * @return array{0: \AlexSkrypnyk\SkillTest\Judge\JudgeCriterion[], 1: array<string, mixed>|null}
   *   The criteria and the blocking rubric check row, or NULL when none blocks.
   */
  protected function judgeDimension(array $trial, EffectiveConfig $effective, ?string $prompt, string $path, array &$state): array {
    $stored = $this->criteria(Data::toArrayList(Data::get($trial, 'judge')));

    if ($effective->rubric === []) {
      return [$stored, NULL];
    }

    $criteria = $this->judge instanceof Judge ? $this->rejudge($this->judge, $effective, $prompt, $path, $stored, $state) : $stored;
    $verdict = new JudgeVerdict($criteria, '');

    if (!$verdict->blocks(UnknownPolicy::fromConfig($effective->judgeUnknown))) {
      return [$criteria, NULL];
    }

    $message = sprintf('the judge passed %d of %d criteria (%d unknown).', $verdict->passedCount(), $verdict->total(), $verdict->unknowns());

    return [$criteria, CheckResult::fail(LlmSuite::CHECK_JUDGE_RUBRIC, 'judge rubric', '', $message)->toCheckRow()];
  }

  /**
   * Re-judges a trial's transcript, falling back to the stored verdict.
   *
   * @param \AlexSkrypnyk\SkillTest\Judge\Judge $judge
   *   The judge to re-score the rubric with.
   * @param \AlexSkrypnyk\SkillTest\Config\EffectiveConfig $effective
   *   The skill's effective configuration.
   * @param string|null $prompt
   *   The task prompt, or NULL when the task is no longer configured.
   * @param string $path
   *   The trial transcript path.
   * @param \AlexSkrypnyk\SkillTest\Judge\JudgeCriterion[] $stored
   *   The stored criteria, the fallback when re-judging cannot run.
   * @param array{rescored: int, newly_failing: int, newly_passing: int, notes: string[]} $state
   *   The mutable re-grade accumulator.
   *
   * @return \AlexSkrypnyk\SkillTest\Judge\JudgeCriterion[]
   *   The re-judged criteria, or the stored ones on any obstacle.
   */
  protected function rejudge(Judge $judge, EffectiveConfig $effective, ?string $prompt, string $path, array $stored, array &$state): array {
    if ($prompt === NULL || $effective->judgeModel === NULL) {
      $state['notes'][] = 'a judged trial could not be re-judged (task or judge model missing); kept the stored verdict.';

      return $stored;
    }

    $contents = is_file($path) ? (string) file_get_contents($path) : '';
    $model = $effective->modelAliases[$effective->judgeModel] ?? $effective->judgeModel;

    try {
      return $judge->evaluate($effective->rubric, $prompt, $contents, $model, $this->root)->criteria;
    }
    catch (JudgeException $judge_exception) {
      $state['notes'][] = 'the judge failed during re-judging; kept the stored verdict: ' . $judge_exception->getMessage();

      return $stored;
    }
  }

  /**
   * Reconstructs judge criteria from their stored rows.
   *
   * @param array<int, array<mixed>> $rows
   *   The stored criterion rows.
   *
   * @return \AlexSkrypnyk\SkillTest\Judge\JudgeCriterion[]
   *   The reconstructed criteria.
   */
  protected function criteria(array $rows): array {
    return array_map(static fn(array $row): JudgeCriterion => new JudgeCriterion(
      Data::toIntOrNull(Data::get($row, 'criterion')) ?? 0,
      (bool) Data::get($row, 'pass'),
      (bool) Data::get($row, 'unknown'),
    ), $rows);
  }

  /**
   * The failing runtime-only rows a transcript cannot reproduce offline.
   *
   * @param array<int, array<mixed>> $rows
   *   The saved contract rows.
   *
   * @return array<int, array<mixed>>
   *   The failing rows whose check id names a runtime signal (`live.*`).
   */
  protected function runtimeRows(array $rows): array {
    return array_values(array_filter($rows, static function (array $row): bool {
      $id = Data::toStringOrNull(Data::get($row, 'check')) ?? '';

      return !(bool) Data::get($row, 'pass') && str_starts_with($id, 'live.');
    }));
  }

  /**
   * The weakest model, in ladder order, that passes every task.
   *
   * @param array<int, array<mixed>> $tasks
   *   The re-scored task entries.
   * @param float $threshold
   *   The pass-rate threshold.
   *
   * @return string|null
   *   The minimal supporting model alias, or NULL when none supports the skill.
   */
  protected function minimalModel(array $tasks, float $threshold): ?string {
    // @codeCoverageIgnoreStart
    if ($tasks === []) {
      return NULL;
    }
    // @codeCoverageIgnoreEnd
    $first = Data::toArrayList(Data::get($tasks[0], 'models'));

    foreach ($first as $position => $model_entry) {
      if ($this->positionSupports($tasks, $position, $threshold)) {
        return ResultsDocument::modelAlias($model_entry);
      }
    }

    return NULL;
  }

  /**
   * Whether the model at a ladder position passed in every task.
   *
   * @param array<int, array<mixed>> $tasks
   *   The task entries.
   * @param int $position
   *   The model's position in each task's model list.
   * @param float $threshold
   *   The pass-rate threshold.
   *
   * @return bool
   *   TRUE when every task's model at that position met the threshold.
   */
  protected function positionSupports(array $tasks, int $position, float $threshold): bool {
    foreach ($tasks as $task) {
      $models = Data::toArrayList(Data::get($task, 'models'));
      $model = $models[$position] ?? NULL;

      if (!is_array($model) || !ResultsDocument::modelPasses($model, $threshold)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Rebuilds the failure total from the re-scored document.
   *
   * @param array<string, mixed> $document
   *   The re-scored document.
   *
   * @return array<string, mixed>
   *   The document with `totals.failures` recomputed.
   */
  protected function recomputeFailures(array $document): array {
    $totals = Data::toArray(Data::get($document, 'totals'));

    if ($totals === []) {
      return $document;
    }

    $read = new ResultsDocument($document);
    $failures = 0;

    foreach ($read->skillEntries() as $skill_entry) {
      foreach (['structure', 'security', 'transcript'] as $group) {
        foreach (Data::toArrayList(Data::get($skill_entry, 'deterministic', $group)) as $row) {
          $failures += (bool) Data::get($row, 'pass') ? 0 : 1;
        }
      }
    }

    foreach (Data::toArrayList(Data::get($document, 'hooks')) as $row) {
      $failures += (bool) Data::get($row, 'pass') ? 0 : 1;
    }

    foreach (Data::toArrayList(Data::get($document, 'coverage', 'violations')) as $row) {
      $failures += (bool) Data::get($row, 'pass') ? 0 : 1;
    }

    foreach ($read->tasks() as $view) {
      foreach ($view->modelPassed as $passed) {
        $failures += $passed ? 0 : 1;
      }
    }

    $totals['failures'] = $failures;
    $document['totals'] = $totals;

    return $document;
  }

  /**
   * Finds the configured skill matching a name.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $config
   *   The loaded configuration.
   * @param string $name
   *   The skill name from the results document.
   *
   * @return \AlexSkrypnyk\SkillTest\Config\LoadedSkill|null
   *   The matching skill, or NULL when the config declares none.
   */
  protected function matchSkill(LoadedConfig $config, string $name): ?LoadedSkill {
    foreach ($config->skills as $skill) {
      if ($skill->effective->skill === $name) {
        return $skill;
      }
    }

    return NULL;
  }

  /**
   * The prompt of a configured task by name, for re-judging.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\EffectiveConfig $effective
   *   The skill's effective configuration.
   * @param string $task_name
   *   The task name from the results document.
   *
   * @return string|null
   *   The task prompt, or NULL when the task is no longer configured.
   */
  protected function taskPrompt(EffectiveConfig $effective, string $task_name): ?string {
    foreach ($effective->tasks as $task) {
      if (Data::toStringOrNull(Data::get($task, 'name')) === $task_name) {
        return Data::toStringOrNull(Data::get($task, 'prompt'));
      }
    }

    return NULL;
  }

  /**
   * The fraction of a trial set that passed.
   *
   * @param array<int, array<mixed>> $trials
   *   The trial rows.
   *
   * @return float
   *   The pass rate; zero when there are no trials.
   */
  protected function passRate(array $trials): float {
    if ($trials === []) {
      return 0.0;
    }

    $passed = count(array_filter($trials, static fn(array $trial): bool => (bool) Data::get($trial, 'pass')));

    return $passed / count($trials);
  }

  /**
   * Resolves a transcript reference against the document's base directory.
   *
   * @param string $base_dir
   *   The directory the document was read from.
   * @param string $reference
   *   The transcript reference, relative or absolute.
   *
   * @return string
   *   The resolved path.
   */
  protected function resolvePath(string $base_dir, string $reference): string {
    return str_starts_with($reference, '/') ? $reference : rtrim($base_dir, '/') . '/' . $reference;
  }

}
