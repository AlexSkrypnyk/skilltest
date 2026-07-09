<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Judge;

/**
 * How a per-criterion abstention is treated when gating a trial.
 *
 * A judge may return `unknown` for a criterion when the evidence does not show
 * the answer. Strict runs (`fail`) treat that abstention as not-passed so an
 * unproven criterion blocks the trial; lenient runs (`ignore`) let it through
 * while the abstention is still reported. It is never silently a pass either
 * way - the difference is only whether an abstention blocks.
 */
enum UnknownPolicy: string {

  // An abstained criterion blocks the trial.
  case Fail = 'fail';

  // An abstained criterion does not block the trial, but is still reported.
  case Ignore = 'ignore';

  /**
   * Resolves a configured policy value, defaulting to the strict policy.
   *
   * @param string|null $value
   *   The configured `judge.unknown` value, or NULL when unset.
   *
   * @return self
   *   The matching policy, or FAIL when the value is unset or unrecognised.
   */
  public static function fromConfig(?string $value): self {
    return $value === NULL ? self::Fail : (self::tryFrom($value) ?? self::Fail);
  }

}
