<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Judge;

/**
 * One binary rubric criterion's outcome, as the judge reported it.
 *
 * The pair (`pass`, `unknown`) is the judge's literal answer: a normally scored
 * criterion is `pass` true/false with `unknown` false; an abstention is
 * `unknown` true with `pass` false, because the judge did not affirm it.
 * Whether the criterion blocks a trial is a separate, policy-dependent question
 * ({@see self::blocks()}); the reported pair stays faithful to the judge so the
 * per-criterion row in the results document is the same whatever the policy.
 */
final readonly class JudgeCriterion {

  /**
   * Constructs a JudgeCriterion.
   *
   * @param int $id
   *   The 1-based criterion id, matching its position in the rubric.
   * @param bool $pass
   *   TRUE when the judge affirmed the criterion; always FALSE when abstaining.
   * @param bool $unknown
   *   TRUE when the judge abstained because the evidence did not show it.
   */
  public function __construct(
    public int $id,
    public bool $pass,
    public bool $unknown,
  ) {}

  /**
   * Whether this criterion blocks the trial under a policy.
   *
   * @param \AlexSkrypnyk\SkillTest\Judge\UnknownPolicy $policy
   *   The abstention policy.
   *
   * @return bool
   *   TRUE when the criterion should fail the trial: a failed criterion always
   *   blocks, and an abstention blocks only under the strict policy.
   */
  public function blocks(UnknownPolicy $policy): bool {
    if ($this->unknown) {
      return $policy === UnknownPolicy::FAIL;
    }

    return !$this->pass;
  }

  /**
   * Renders the criterion as a results-document row.
   *
   * @return array{criterion: int, pass: bool, unknown: bool}
   *   The criterion row matching the results schema.
   */
  public function toArray(): array {
    return [
      'criterion' => $this->id,
      'pass' => $this->pass,
      'unknown' => $this->unknown,
    ];
  }

}
