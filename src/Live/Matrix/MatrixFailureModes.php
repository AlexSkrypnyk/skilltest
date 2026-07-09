<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live\Matrix;

use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Judge\UnknownPolicy;
use AlexSkrypnyk\SkillTest\Live\LlmSuite;
use AlexSkrypnyk\SkillTest\Live\ModelOutcome;

/**
 * Why a model failed a skill: the failed check ids and judge criteria, counted.
 *
 * A pass rate says a model failed; a failure-mode report says why, which is
 * what a skill author acts on. Across a failing model's trials it tallies each
 * failed contract check by its id and each blocking judge criterion by its
 * rubric text, so "stops calling the broker after two steps (2x)" and "judge:
 * decided the step order itself (2x)" replace a bare "0.33". Contract and judge
 * stay separate, and ties break by name so the ranking is deterministic between
 * runs.
 */
final readonly class MatrixFailureModes {

  /**
   * Constructs a MatrixFailureModes.
   *
   * @param array<string, int> $contract
   *   Failed contract check ids, keyed by id, valued by occurrence count,
   *   ranked most frequent first.
   * @param array<string, int> $judge
   *   Blocking judge criteria, keyed by rubric text, valued by occurrence
   *   count, ranked most frequent first.
   */
  public function __construct(
    public array $contract,
    public array $judge,
  ) {}

  /**
   * Aggregates the failure modes across a skill's tasks on one failing model.
   *
   * @param \AlexSkrypnyk\SkillTest\Live\ModelOutcome[] $models
   *   The failing model's outcome in each of the skill's tasks.
   * @param string[] $rubric
   *   The judge rubric, in id order, naming each criterion.
   * @param \AlexSkrypnyk\SkillTest\Judge\UnknownPolicy $policy
   *   The abstention policy deciding whether an unknown criterion blocked.
   *
   * @return self
   *   The ranked failure modes.
   */
  public static function fromModels(array $models, array $rubric, UnknownPolicy $policy): self {
    $contract = [];
    $judge = [];

    foreach ($models as $model) {
      foreach ($model->trials as $trial) {
        foreach ($trial->checks as $check) {
          if ($check->pass || self::isJudge($check)) {
            continue;
          }

          $contract[$check->id] = ($contract[$check->id] ?? 0) + 1;
        }

        foreach ($trial->criteria as $criterion) {
          if (!$criterion->blocks($policy)) {
            continue;
          }

          $label = $rubric[$criterion->id - 1] ?? ('criterion ' . $criterion->id);
          $judge[$label] = ($judge[$label] ?? 0) + 1;
        }
      }
    }

    return new self(self::rank($contract), self::rank($judge));
  }

  /**
   * Whether no failure mode was recorded.
   *
   * @return bool
   *   TRUE when neither a contract check nor a judge criterion failed.
   */
  public function isEmpty(): bool {
    return $this->contract === [] && $this->judge === [];
  }

  /**
   * Renders the failure modes as a single, human-readable line.
   *
   * @return string
   *   The `contract: ...; judge: ...` summary, or an empty string when empty.
   */
  public function describe(): string {
    $parts = [];

    if ($this->contract !== []) {
      $parts[] = 'contract: ' . self::phrases($this->contract);
    }

    if ($this->judge !== []) {
      $parts[] = 'judge: ' . self::phrases($this->judge);
    }

    return implode('; ', $parts);
  }

  /**
   * Joins a ranked count map into a `label (Nx)` phrase list.
   *
   * @param array<string, int> $counts
   *   The ranked counts.
   *
   * @return string
   *   The comma-separated phrases.
   */
  protected static function phrases(array $counts): string {
    $phrases = [];

    foreach ($counts as $label => $count) {
      $phrases[] = sprintf('%s (%dx)', (string) $label, $count);
    }

    return implode(', ', $phrases);
  }

  /**
   * Ranks a count map by count descending, breaking ties by key ascending.
   *
   * @param array<string, int> $counts
   *   The raw counts.
   *
   * @return array<string, int>
   *   The deterministically ranked counts.
   */
  protected static function rank(array $counts): array {
    $keys = array_keys($counts);

    usort($keys, static function (int|string $a, int|string $b) use ($counts): int {
      $by_count = $counts[$b] <=> $counts[$a];

      return $by_count !== 0 ? $by_count : ((string) $a <=> (string) $b);
    });

    $ranked = [];

    foreach ($keys as $key) {
      $ranked[(string) $key] = $counts[$key];
    }

    return $ranked;
  }

  /**
   * Whether a check is one of the judge's folded-in checks.
   *
   * @param \AlexSkrypnyk\SkillTest\Contract\CheckResult $check
   *   The check.
   *
   * @return bool
   *   TRUE when the check is the judge verdict or judge rubric check.
   */
  protected static function isJudge(CheckResult $check): bool {
    return $check->id === LlmSuite::CHECK_JUDGE || $check->id === LlmSuite::CHECK_JUDGE_RUBRIC;
  }

}
