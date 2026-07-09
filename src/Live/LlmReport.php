<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

/**
 * The whole llm run's outcome, ready for rendering, totals, and persistence.
 *
 * Aggregates every skill's live outcome and owns the arithmetic the reporters
 * share: how many task-on-model verdicts were evaluated, how many failed, and
 * the summed trials, tokens, and cost that make the price of a run a number
 * rather than a surprise. It also collects the per-trial transcripts as
 * artifacts keyed by the relative path the document references them by, so a
 * `--output-dir` write lands each transcript beside the results file without
 * the document ever inlining one.
 */
final readonly class LlmReport {

  /**
   * Constructs an LlmReport.
   *
   * @param \AlexSkrypnyk\SkillTest\Live\SkillOutcome[] $skills
   *   The per-skill outcomes, in discovery order.
   */
  public function __construct(
    public array $skills,
  ) {}

  /**
   * The number of task-on-model verdicts evaluated.
   *
   * @return int
   *   The verdict count.
   */
  public function gates(): int {
    $count = 0;

    foreach ($this->skills as $skill) {
      foreach ($skill->tasks as $task) {
        $count += count($task->models);
      }
    }

    return $count;
  }

  /**
   * The number of task-on-model verdicts that failed their threshold.
   *
   * @return int
   *   The failed-verdict count.
   */
  public function failures(): int {
    $count = 0;

    foreach ($this->skills as $skill) {
      foreach ($skill->tasks as $task) {
        $count += count(array_filter($task->models, static fn(ModelOutcome $model): bool => !$model->passed()));
      }
    }

    return $count;
  }

  /**
   * Whether any task-on-model verdict failed.
   *
   * @return bool
   *   TRUE when at least one verdict failed its threshold.
   */
  public function failed(): bool {
    return $this->failures() > 0;
  }

  /**
   * The total number of trials run across every skill, task, and model.
   *
   * @return int
   *   The trial count.
   */
  public function trials(): int {
    return count($this->eachTrial());
  }

  /**
   * The summed input and output token counts across every trial.
   *
   * @return array{in: int, out: int}
   *   The token totals.
   */
  public function tokens(): array {
    $in = 0;
    $out = 0;

    foreach ($this->eachTrial() as $trial) {
      $in += $trial->tokensIn;
      $out += $trial->tokensOut;
    }

    return ['in' => $in, 'out' => $out];
  }

  /**
   * The summed cost across every trial.
   *
   * @return float
   *   The total cost in USD, rounded to four decimals.
   */
  public function cost(): float {
    $cost = 0.0;

    foreach ($this->eachTrial() as $trial) {
      $cost += $trial->cost;
    }

    return round($cost, 4);
  }

  /**
   * The per-trial transcripts and mock logs, keyed by document-relative path.
   *
   * @return array<string, string>
   *   The artifact contents keyed by relative path.
   */
  public function artifacts(): array {
    $artifacts = [];

    foreach ($this->eachTrial() as $trial) {
      $artifacts[$trial->transcriptPath] = $trial->transcript;

      foreach ($trial->mockLogs as $path => $content) {
        $artifacts[$path] = $content;
      }
    }

    return $artifacts;
  }

  /**
   * Builds the machine-readable results document.
   *
   * @param string $schema_version
   *   The results schema version.
   * @param array<string, string> $tool
   *   The tool block: name and version.
   * @param array<string, mixed> $run
   *   The run block: id, started, duration_ms, command, environment.
   *
   * @return array<string, mixed>
   *   The full results document.
   */
  public function toResults(string $schema_version, array $tool, array $run): array {
    return [
      'version' => $schema_version,
      'tool' => $tool,
      'run' => $run,
      'skills' => array_map(static fn(SkillOutcome $skill): array => $skill->toArray(), $this->skills),
      'hooks' => [],
      'coverage' => ['violations' => []],
      'totals' => [
        'checks' => $this->gates(),
        'failures' => $this->failures(),
        'trials' => $this->trials(),
        'tokens' => $this->tokens(),
        'cost_usd' => $this->cost(),
      ],
    ];
  }

  /**
   * Every trial across every skill, task, and model.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\TrialResult[]
   *   The flattened trials.
   */
  protected function eachTrial(): array {
    $trials = [];

    foreach ($this->skills as $skill) {
      foreach ($skill->tasks as $task) {
        foreach ($task->models as $model) {
          foreach ($model->trials as $trial) {
            $trials[] = $trial;
          }
        }
      }
    }

    return $trials;
  }

}
