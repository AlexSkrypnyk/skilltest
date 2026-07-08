<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Contract;

use AlexSkrypnyk\SkillTest\Config\Packs;
use AlexSkrypnyk\SkillTest\Config\Pcre;

/**
 * Matches executed commands against a contract pattern position.
 *
 * A pattern position is either a hand-written delimiter-less regex or a
 * `pack:<name>` reference that expands to the pre-baked pattern set. Matching a
 * pack means matching any of its regexes, so this class flattens both forms to
 * one question: does any command match, and if so which one is the evidence.
 */
final class Matcher {

  /**
   * Expands a pattern position to the concrete regexes it matches with.
   *
   * @param string $pattern
   *   A hand-written regex or a `pack:<name>` reference.
   *
   * @return list<string>
   *   The delimiter-less regexes: the pack's set, or the lone regex.
   */
  public static function expand(string $pattern): array {
    $pack = Packs::reference($pattern);

    if ($pack !== NULL) {
      return Packs::patterns($pack);
    }

    return [$pattern];
  }

  /**
   * Returns the first command that matches the pattern, or NULL.
   *
   * Commands are tested in execution order, so the returned evidence is the
   * first matching (or, for a forbidden position, the first offending) command.
   *
   * @param string[] $commands
   *   The commands to test, in order.
   * @param string $pattern
   *   A hand-written regex or a `pack:<name>` reference.
   *
   * @return string|null
   *   The first matching command, or NULL when none matches.
   */
  public static function firstMatch(array $commands, string $pattern): ?string {
    $regexes = self::expand($pattern);

    foreach ($commands as $command) {
      foreach ($regexes as $regex) {
        if (preg_match(Pcre::delimit($regex), $command) === 1) {
          return $command;
        }
      }
    }

    return NULL;
  }

}
