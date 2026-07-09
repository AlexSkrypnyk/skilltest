<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Judge;

/**
 * A parsed judge verdict over a binary-criteria rubric.
 *
 * Binary criteria are scored rather than a single holistic rating because N
 * independent yes/no judgements are far lower-variance than one integer. The
 * verdict passes only when no criterion blocks under the abstention policy, so
 * a trial is judged green only when every criterion is affirmed (with
 * abstentions counting for or against per the policy). The reasoning is carried
 * for the report; the per-criterion detail is what the results document
 * records.
 */
final readonly class JudgeVerdict {

  /**
   * Constructs a JudgeVerdict.
   *
   * @param \AlexSkrypnyk\SkillTest\Judge\JudgeCriterion[] $criteria
   *   The scored criteria, in rubric order.
   * @param string $reasoning
   *   The judge's free-text reasoning, or the empty string when it gave none.
   */
  public function __construct(
    public array $criteria,
    public string $reasoning,
  ) {}

  /**
   * Whether any criterion blocks the trial under a policy.
   *
   * @param \AlexSkrypnyk\SkillTest\Judge\UnknownPolicy $policy
   *   The abstention policy.
   *
   * @return bool
   *   TRUE when at least one criterion blocks.
   */
  public function blocks(UnknownPolicy $policy): bool {
    foreach ($this->criteria as $criterion) {
      if ($criterion->blocks($policy)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * The number of criteria the judge affirmed.
   *
   * @return int
   *   The count of passed criteria.
   */
  public function passedCount(): int {
    return count(array_filter($this->criteria, static fn(JudgeCriterion $criterion): bool => $criterion->pass));
  }

  /**
   * The number of criteria the judge abstained on.
   *
   * @return int
   *   The count of abstentions.
   */
  public function unknowns(): int {
    return count(array_filter($this->criteria, static fn(JudgeCriterion $criterion): bool => $criterion->unknown));
  }

  /**
   * The total number of scored criteria.
   *
   * @return int
   *   The criterion count.
   */
  public function total(): int {
    return count($this->criteria);
  }

  /**
   * Renders the criteria as results-document rows.
   *
   * @return array<int, array{criterion: int, pass: bool, unknown: bool}>
   *   The per-criterion rows.
   */
  public function toArray(): array {
    return array_map(static fn(JudgeCriterion $criterion): array => $criterion->toArray(), $this->criteria);
  }

}
