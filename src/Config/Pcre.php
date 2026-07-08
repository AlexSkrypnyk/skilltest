<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Config;

/**
 * Compiles the delimiter-less regular expressions used in contract patterns.
 *
 * Contract patterns are authored without delimiters so they read cleanly in
 * YAML; this helper wraps a pattern in a delimiter that does not occur in it
 * and reports whether it compiles, swallowing the warning PCRE emits on a bad
 * pattern so it never leaks into test output.
 */
final class Pcre {

  /**
   * Candidate delimiters tried in order until one is absent from the pattern.
   */
  public const array DELIMITERS = ['#', '~', '%', '@', '!', ';', ',', '|'];

  /**
   * Whether a delimiter-less pattern compiles as a valid regular expression.
   *
   * @param string $pattern
   *   The delimiter-less pattern.
   *
   * @return bool
   *   TRUE when the pattern compiles.
   */
  public static function compiles(string $pattern): bool {
    $delimited = self::delimit($pattern);

    set_error_handler(static fn(): bool => TRUE);

    try {
      $matched = preg_match($delimited, '');
    }
    finally {
      restore_error_handler();
    }

    return $matched !== FALSE;
  }

  /**
   * Wraps a delimiter-less pattern in a delimiter absent from the pattern.
   *
   * @param string $pattern
   *   The delimiter-less pattern.
   *
   * @return string
   *   The delimited pattern, ready for preg_* functions.
   */
  public static function delimit(string $pattern): string {
    foreach (self::DELIMITERS as $delimiter) {
      if (!str_contains($pattern, $delimiter)) {
        return $delimiter . $pattern . $delimiter;
      }
    }

    return '#' . str_replace('#', '\\#', $pattern) . '#';
  }

}
