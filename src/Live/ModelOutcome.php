<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

/**
 * One task's trials on one model, and the pass-rate verdict they produce.
 *
 * A model is graded honestly: its pass rate is the fraction of trials that
 * passed, with no retries to hide a flaky run, and it meets the bar only when
 * that rate reaches the task threshold. Both the raw rate (for gating, at full
 * precision) and a rounded rate (for the report) are derived from the same
 * trials, so the number a reader sees and the number the gate uses never
 * diverge.
 */
final readonly class ModelOutcome {

  /**
   * Constructs a ModelOutcome.
   *
   * @param string $model
   *   The resolved model id the trials ran on.
   * @param string $alias
   *   The alias the model was named by in configuration.
   * @param \AlexSkrypnyk\SkillTest\Live\TrialResult[] $trials
   *   The trial results, in trial order.
   * @param float $threshold
   *   The pass-rate threshold the model must meet.
   */
  public function __construct(
    public string $model,
    public string $alias,
    public array $trials,
    public float $threshold,
  ) {}

  /**
   * The fraction of trials that passed.
   *
   * @return float
   *   The pass rate in the range 0..1; zero when there are no trials.
   */
  public function passRate(): float {
    if ($this->trials === []) {
      return 0.0;
    }

    $passed = count(array_filter($this->trials, static fn(TrialResult $trial): bool => $trial->pass));

    return $passed / count($this->trials);
  }

  /**
   * Whether the model meets the threshold.
   *
   * @return bool
   *   TRUE when the pass rate reaches the threshold.
   */
  public function passed(): bool {
    return $this->passRate() >= $this->threshold;
  }

  /**
   * Renders the model as a results-document row.
   *
   * @return array<string, mixed>
   *   The model row matching the results schema.
   */
  public function toArray(): array {
    return [
      'model' => $this->model,
      'alias' => $this->alias,
      'trials' => array_map(static fn(TrialResult $trial): array => $trial->toArray(), $this->trials),
      'pass_rate' => round($this->passRate(), 2),
    ];
  }

}
