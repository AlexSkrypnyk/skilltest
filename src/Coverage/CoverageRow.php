<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Coverage;

/**
 * One row of the coverage grid: how well a single discovered skill is covered.
 *
 * Captures the three facts the grid reports - whether an `eval.yaml` was found,
 * whether a transcript fixture exists, and how many llm tasks are declared -
 * plus the exclusion state that decides whether a missing eval fails the gate.
 */
final readonly class CoverageRow {

  /**
   * The skill has an `eval.yaml`.
   */
  public const string STATUS_COVERED = 'covered';

  /**
   * The skill has no `eval.yaml` but is exempted with a reason.
   */
  public const string STATUS_EXCLUDED = 'excluded';

  /**
   * The skill has no `eval.yaml` and is not exempted: a gate violation.
   */
  public const string STATUS_UNCOVERED = 'uncovered';

  /**
   * Constructs a CoverageRow.
   *
   * @param string $skill
   *   The skill name.
   * @param string $path
   *   The skill directory, relative to the repository root.
   * @param bool $eval
   *   Whether an `eval.yaml` was loaded for the skill.
   * @param bool $transcript
   *   Whether a declared transcript fixture exists on disk.
   * @param int $tasks
   *   The number of declared llm tasks.
   * @param bool $excluded
   *   Whether the skill is named in `paths.exclude`.
   * @param string|null $reason
   *   The exclusion reason, when excluded.
   */
  public function __construct(
    public string $skill,
    public string $path,
    public bool $eval,
    public bool $transcript,
    public int $tasks,
    public bool $excluded,
    public ?string $reason,
  ) {}

  /**
   * The coverage status: covered, excluded, or uncovered.
   *
   * A loaded `eval.yaml` always reads as covered, so a redundant exclusion of
   * an already-covered skill never hides that it is in fact covered.
   *
   * @return string
   *   One of the STATUS_* constants.
   */
  public function status(): string {
    if ($this->eval) {
      return self::STATUS_COVERED;
    }

    if ($this->excluded) {
      return self::STATUS_EXCLUDED;
    }

    return self::STATUS_UNCOVERED;
  }

  /**
   * Whether this row fails the coverage gate.
   *
   * @return bool
   *   TRUE when the skill has no eval and is not excluded.
   */
  public function isViolation(): bool {
    return !$this->eval && !$this->excluded;
  }

  /**
   * Returns the row as a plain array for machine output.
   *
   * @return array<string, mixed>
   *   The documented per-skill fields.
   */
  public function toArray(): array {
    return [
      'skill' => $this->skill,
      'path' => $this->path,
      'eval' => $this->eval,
      'transcript' => $this->transcript,
      'tasks' => $this->tasks,
      'excluded' => $this->excluded,
      'reason' => $this->reason,
    ];
  }

}
