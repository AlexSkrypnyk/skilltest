<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Results;

use AlexSkrypnyk\SkillTest\Config\Data;

/**
 * The one place a saved results document is turned into numbers.
 *
 * Compare (deltas), report (rendering), and interpret (the top failure) all
 * read a `results.json` the same way, so the arithmetic lives here once rather
 * than each consumer re-deriving a pass rate or re-walking the skill tree. The
 * aggregate figures come straight from the document's own `totals` and `run`
 * blocks - the renderers never recompute a truth the run already recorded - and
 * the per-model and per-task figures are folded from the raw trials so a
 * consumer sees the same rate the run measured.
 *
 * Failure ordering is the tool's opinion about what a skill author fixes first:
 * a dangerous pattern before a malformed skill, a broken contract before a
 * soft llm verdict, so `interpret` can name a single "top failure"
 * deterministically.
 */
final class Metrics {

  /**
   * The finding kinds, most-blocking first, deciding the "top failure".
   */
  public const array KIND_ORDER = ['security', 'structure', 'transcript', 'hooks', 'coverage', 'llm'];

  /**
   * The deterministic per-skill groups, in scan order.
   */
  protected const array DETERMINISTIC_GROUPS = ['security', 'structure', 'transcript'];

  /**
   * The default threshold used when a document records no verdict threshold.
   */
  protected const float DEFAULT_THRESHOLD = 0.8;

  /**
   * The document's headline figures, taken from its `totals` and `run` blocks.
   *
   * @param array<string, mixed> $document
   *   The results document.
   *
   * @return array<string, int|float>
   *   The aggregate figures: checks, failures, passed, pass_rate, trials,
   *   tokens_in, tokens_out, cost_usd, duration_ms.
   */
  public static function aggregate(array $document): array {
    $checks = Data::toIntOrNull(Data::get($document, 'totals', 'checks')) ?? 0;
    $failures = Data::toIntOrNull(Data::get($document, 'totals', 'failures')) ?? 0;
    $passed = max(0, $checks - $failures);

    return [
      'checks' => $checks,
      'failures' => $failures,
      'passed' => $passed,
      'pass_rate' => $checks > 0 ? (float) $passed / $checks : 0.0,
      'trials' => Data::toIntOrNull(Data::get($document, 'totals', 'trials')) ?? 0,
      'tokens_in' => Data::toIntOrNull(Data::get($document, 'totals', 'tokens', 'in')) ?? 0,
      'tokens_out' => Data::toIntOrNull(Data::get($document, 'totals', 'tokens', 'out')) ?? 0,
      'cost_usd' => Data::toFloatOrNull(Data::get($document, 'totals', 'cost_usd')) ?? 0.0,
      'duration_ms' => Data::toIntOrNull(Data::get($document, 'run', 'duration_ms')) ?? 0,
    ];
  }

  /**
   * The per-model figures, folded from every trial that ran on each model.
   *
   * Keyed by the model alias (falling back to the model id) so two runs of the
   * same ladder line up column for column. The rate is recomputed from the raw
   * trials rather than averaging the stored per-task rates, so it stays exact.
   *
   * @param array<string, mixed> $document
   *   The results document.
   *
   * @return array<string, array<string, int|float>>
   *   Per-model figures keyed by alias: trials, passed, pass_rate, tokens_in,
   *   tokens_out, cost_usd, duration_ms.
   */
  public static function perModel(array $document): array {
    $models = [];

    foreach (self::eachModel($document) as [, , $alias, $model]) {
      $bucket = $models[$alias] ?? ['trials' => 0, 'passed' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'cost_usd' => 0.0, 'duration_ms' => 0];

      foreach (Data::toArrayList(Data::get($model, 'trials')) as $trial) {
        $bucket['trials']++;
        $bucket['passed'] += (Data::toBoolOrNull(Data::get($trial, 'pass')) ?? FALSE) ? 1 : 0;
        $bucket['tokens_in'] += Data::toIntOrNull(Data::get($trial, 'tokens', 'in')) ?? 0;
        $bucket['tokens_out'] += Data::toIntOrNull(Data::get($trial, 'tokens', 'out')) ?? 0;
        $bucket['cost_usd'] += Data::toFloatOrNull(Data::get($trial, 'cost_usd')) ?? 0.0;
        $bucket['duration_ms'] += Data::toIntOrNull(Data::get($trial, 'duration_ms')) ?? 0;
      }

      $models[$alias] = $bucket;
    }

    foreach ($models as $alias => $bucket) {
      $models[$alias]['pass_rate'] = $bucket['trials'] > 0 ? (float) $bucket['passed'] / $bucket['trials'] : 0.0;
      $models[$alias]['cost_usd'] = round($bucket['cost_usd'], 4);
    }

    return $models;
  }

  /**
   * The per-task-and-model pass rate, keyed `skill::task::alias`.
   *
   * The stored rate is used verbatim so the figure a reader compares matches
   * the one the report showed. Absent rates fall back to the trial fold.
   *
   * @param array<string, mixed> $document
   *   The results document.
   *
   * @return array<string, float>
   *   Pass rate keyed by `skill::task::alias`.
   */
  public static function perTask(array $document): array {
    $rates = [];

    foreach (self::eachModel($document) as [$skill, $task, $alias, $model]) {
      $stored = Data::toFloatOrNull(Data::get($model, 'pass_rate'));
      $rates[$skill . '::' . $task . '::' . $alias] = $stored ?? self::trialRate($model);
    }

    return $rates;
  }

  /**
   * Every failure in the document as an ordered list of structured findings.
   *
   * Deterministic, hook, and coverage failures are read from their explicit
   * pass verdicts; an llm model-on-task counts as a failure when its pass rate
   * falls short of the skill's verdict threshold, the only threshold the
   * document records. Findings are sorted most-blocking first.
   *
   * @param array<string, mixed> $document
   *   The results document.
   *
   * @return array<int, array<string, mixed>>
   *   The findings, each carrying kind, scope, id, label, evidence, message,
   *   and (for llm) task, model, pass_rate, and threshold.
   */
  public static function failures(array $document): array {
    $findings = [];

    foreach (Data::toArrayList(Data::get($document, 'skills')) as $skill) {
      $name = Data::toStringOrNull(Data::get($skill, 'skill')) ?? '';

      foreach (self::DETERMINISTIC_GROUPS as $group) {
        foreach (Data::toArrayList(Data::get($skill, 'deterministic', $group)) as $check) {
          self::collectCheck($findings, $check, $group, $name);
        }
      }

      self::collectLlm($findings, $skill, $name);
    }

    foreach (Data::toArrayList(Data::get($document, 'hooks')) as $hook) {
      self::collectCheck($findings, $hook, 'hooks', 'repo');
    }

    foreach (Data::toArrayList(Data::get($document, 'coverage', 'violations')) as $violation) {
      self::collectCheck($findings, $violation, 'coverage', 'repo');
    }

    usort($findings, static fn(array $a, array $b): int => self::rank($a['kind']) <=> self::rank($b['kind']));

    return $findings;
  }

  /**
   * Appends a finding for a check that did not pass.
   *
   * @param array<int, array<string, mixed>> $findings
   *   The accumulating findings, appended in place.
   * @param array<string, mixed> $check
   *   The check row.
   * @param string $kind
   *   The finding kind (a deterministic group, `hooks`, or `coverage`).
   * @param string $scope
   *   The skill name, or `repo` for repo-level checks.
   */
  protected static function collectCheck(array &$findings, array $check, string $kind, string $scope): void {
    if (Data::toBoolOrNull(Data::get($check, 'pass')) ?? FALSE) {
      return;
    }

    $findings[] = [
      'kind' => $kind,
      'scope' => $scope,
      'id' => Data::toStringOrNull(Data::get($check, 'check')) ?? '',
      'label' => Data::toStringOrNull(Data::get($check, 'label')) ?? '',
      'evidence' => Data::toStringOrNull(Data::get($check, 'evidence')) ?? '',
      'message' => Data::toStringOrNull(Data::get($check, 'message')) ?? '',
    ];
  }

  /**
   * Appends a finding for each llm model that fell short of the threshold.
   *
   * @param array<int, array<string, mixed>> $findings
   *   The accumulating findings, appended in place.
   * @param array<string, mixed> $skill
   *   The skill entry.
   * @param string $name
   *   The skill name.
   */
  protected static function collectLlm(array &$findings, array $skill, string $name): void {
    $threshold = Data::toFloatOrNull(Data::get($skill, 'llm', 'verdict', 'threshold')) ?? self::DEFAULT_THRESHOLD;

    foreach (Data::toArrayList(Data::get($skill, 'llm', 'tasks')) as $task) {
      $task_name = Data::toStringOrNull(Data::get($task, 'task')) ?? '';

      foreach (Data::toArrayList(Data::get($task, 'models')) as $model) {
        $rate = Data::toFloatOrNull(Data::get($model, 'pass_rate')) ?? self::trialRate($model);

        if ($rate >= $threshold) {
          continue;
        }

        $findings[] = [
          'kind' => 'llm',
          'scope' => $name,
          'id' => $task_name,
          'label' => '',
          'evidence' => '',
          'message' => '',
          'task' => $task_name,
          'model' => Data::toStringOrNull(Data::get($model, 'alias')) ?? Data::toStringOrNull(Data::get($model, 'model')) ?? '',
          'pass_rate' => $rate,
          'threshold' => $threshold,
        ];
      }
    }
  }

  /**
   * Yields each model with its skill and task names for one pass over the tree.
   *
   * @param array<string, mixed> $document
   *   The results document.
   *
   * @return array<int, array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
   *   Tuples of skill name, task name, model alias, and the model row.
   */
  protected static function eachModel(array $document): array {
    $rows = [];

    foreach (Data::toArrayList(Data::get($document, 'skills')) as $skill) {
      $skill_name = Data::toStringOrNull(Data::get($skill, 'skill')) ?? '';

      foreach (Data::toArrayList(Data::get($skill, 'llm', 'tasks')) as $task) {
        $task_name = Data::toStringOrNull(Data::get($task, 'task')) ?? '';

        foreach (Data::toArrayList(Data::get($task, 'models')) as $model) {
          $alias = Data::toStringOrNull(Data::get($model, 'alias')) ?? Data::toStringOrNull(Data::get($model, 'model')) ?? '';
          $rows[] = [$skill_name, $task_name, $alias, $model];
        }
      }
    }

    return $rows;
  }

  /**
   * The pass rate folded from a model's own trials.
   *
   * @param array<string, mixed> $model
   *   The model row.
   *
   * @return float
   *   The fraction of trials that passed, zero when there are none.
   */
  protected static function trialRate(array $model): float {
    $trials = Data::toArrayList(Data::get($model, 'trials'));

    if ($trials === []) {
      return 0.0;
    }

    $passed = count(array_filter($trials, static fn(array $trial): bool => Data::toBoolOrNull(Data::get($trial, 'pass')) ?? FALSE));

    return (float) $passed / count($trials);
  }

  /**
   * The sort rank of a finding kind, lower sorting first.
   *
   * @param mixed $kind
   *   The finding kind.
   *
   * @return int
   *   The index in the kind order, or a large value for an unknown kind.
   */
  protected static function rank(mixed $kind): int {
    $index = array_search($kind, self::KIND_ORDER, TRUE);

    return $index === FALSE ? count(self::KIND_ORDER) : $index;
  }

}
