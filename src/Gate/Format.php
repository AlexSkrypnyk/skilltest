<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Gate;

/**
 * Shared number formatting for gate findings and gate renderers.
 *
 * Both the engine (which bakes rates into a finding's sentence) and the
 * renderers (which print the rate line) format the same way, so the rule lives
 * once: at most one decimal place, with a trailing `.0` trimmed so a whole
 * number reads as `5`, not `5.0`.
 */
final class Format {

  /**
   * Formats a number with at most one decimal place, trimming a trailing zero.
   *
   * @param float $value
   *   The value to format.
   *
   * @return string
   *   The formatted number.
   */
  public static function number(float $value): string {
    return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
  }

}
