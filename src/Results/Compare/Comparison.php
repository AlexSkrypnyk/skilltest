<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Results\Compare;

use AlexSkrypnyk\SkillTest\Results\Metrics;

/**
 * Two or more results documents lined up side by side, with the deltas.
 *
 * Every figure is read through `Metrics`, so a comparison and a gate see the
 * same arithmetic - this is diagnosis (what moved between these runs), the gate
 * is policy (whether that move is allowed). The first document is the baseline;
 * each metric carries the value from every document in order plus a single
 * delta of the last document against the baseline. A metric absent from either
 * the baseline or the last document has a null delta rather than a fabricated
 * zero, so a task that only exists in one run never reads as "unchanged".
 */
final readonly class Comparison {

  /**
   * The aggregate metrics compared, in display order.
   */
  public const array AGGREGATE_METRICS = ['pass_rate', 'checks', 'failures', 'trials', 'tokens_in', 'tokens_out', 'cost_usd', 'duration_ms'];

  /**
   * The per-model metrics compared, in display order.
   */
  public const array MODEL_METRICS = ['pass_rate', 'cost_usd'];

  /**
   * Constructs a Comparison.
   *
   * @param string[] $labels
   *   The document labels, in document order.
   * @param array<string, array{values: array<int, int|float|null>, delta: int|float|null}> $aggregate
   *   The aggregate series keyed by metric.
   * @param array<string, array<string, array{values: array<int, int|float|null>, delta: int|float|null}>> $models
   *   The per-model series keyed by alias then metric.
   * @param array<string, array{values: array<int, int|float|null>, delta: int|float|null}> $tasks
   *   The per-task pass-rate series keyed by `skill::task::alias`.
   */
  public function __construct(
    public array $labels,
    public array $aggregate,
    public array $models,
    public array $tasks,
  ) {}

  /**
   * Builds a comparison from labelled documents, the first one the baseline.
   *
   * @param array<int, array{label: string, document: array<string, mixed>}> $files
   *   The labelled documents, in order.
   *
   * @return self
   *   The computed comparison.
   */
  public static function of(array $files): self {
    $labels = array_map(static fn(array $file): string => $file['label'], $files);
    $documents = array_map(static fn(array $file): array => $file['document'], $files);

    $aggregates = array_map(Metrics::aggregate(...), $documents);
    $per_model = array_map(Metrics::perModel(...), $documents);
    $per_task = array_map(Metrics::perTask(...), $documents);

    return new self(
      $labels,
      self::aggregateSeries($aggregates),
      self::modelSeries($per_model),
      self::taskSeries($per_task),
    );
  }

  /**
   * Builds the aggregate series for each compared metric.
   *
   * @param array<int, array<string, int|float>> $aggregates
   *   Each document's aggregate figures, in order.
   *
   * @return array<string, array{values: array<int, int|float|null>, delta: int|float|null}>
   *   The series keyed by metric.
   */
  protected static function aggregateSeries(array $aggregates): array {
    $series = [];

    foreach (self::AGGREGATE_METRICS as $metric) {
      $series[$metric] = self::series(array_map(static fn(array $aggregate): int|float => $aggregate[$metric], $aggregates));
    }

    return $series;
  }

  /**
   * Builds the per-model series across the union of aliases.
   *
   * @param array<int, array<string, array<string, int|float>>> $per_model
   *   Each document's per-model figures, in order.
   *
   * @return array<string, array<string, array{values: array<int, int|float|null>, delta: int|float|null}>>
   *   The series keyed by alias then metric.
   */
  protected static function modelSeries(array $per_model): array {
    $models = [];

    foreach (self::keys($per_model) as $alias) {
      foreach (self::MODEL_METRICS as $metric) {
        $models[$alias][$metric] = self::series(array_map(static fn(array $document): int|float|null => $document[$alias][$metric] ?? NULL, $per_model));
      }
    }

    return $models;
  }

  /**
   * Builds the per-task pass-rate series across the union of task keys.
   *
   * @param array<int, array<string, float>> $per_task
   *   Each document's per-task rates, in order.
   *
   * @return array<string, array{values: array<int, int|float|null>, delta: int|float|null}>
   *   The series keyed by `skill::task::alias`.
   */
  protected static function taskSeries(array $per_task): array {
    $tasks = [];

    foreach (self::keys($per_task) as $key) {
      $tasks[$key] = self::series(array_map(static fn(array $document): float|null => $document[$key] ?? NULL, $per_task));
    }

    return $tasks;
  }

  /**
   * The union of the keys across a list of maps, in first-seen order.
   *
   * @param array<int, array<string, mixed>> $maps
   *   The maps to union.
   *
   * @return string[]
   *   The ordered, de-duplicated keys.
   */
  protected static function keys(array $maps): array {
    $keys = [];

    foreach ($maps as $map) {
      foreach (array_keys($map) as $key) {
        $keys[$key] = $key;
      }
    }

    return array_values($keys);
  }

  /**
   * Wraps a list of values with the last-vs-first delta.
   *
   * @param array<int, int|float|null> $values
   *   The value from each document, in order.
   *
   * @return array{values: array<int, int|float|null>, delta: int|float|null}
   *   The series.
   */
  protected static function series(array $values): array {
    $first = $values[0] ?? NULL;
    $last = $values[count($values) - 1] ?? NULL;
    $delta = $first === NULL || $last === NULL ? NULL : $last - $first;

    return ['values' => $values, 'delta' => $delta];
  }

  /**
   * Renders the comparison as a machine-readable structure.
   *
   * @return array<string, mixed>
   *   The comparison document.
   */
  public function toArray(): array {
    return [
      'compare' => TRUE,
      'labels' => $this->labels,
      'aggregate' => $this->aggregate,
      'models' => $this->models,
      'tasks' => $this->tasks,
    ];
  }

}
