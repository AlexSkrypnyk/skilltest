<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Config;

/**
 * Matches a name against shell-style `*` and `?` globs.
 *
 * The one place a selection glob is turned into a match, so skill and task
 * selection share a single wildcard dialect: `*` matches any run of
 * characters, `?` matches a single character, and everything else is
 * literal. A name matches when any glob in the set matches it. An empty
 * glob set matches nothing, so a caller that means "select all" tests for
 * an empty set instead of passing one.
 */
final readonly class Glob {

  /**
   * Whether a name matches any of the globs.
   *
   * @param string $name
   *   The name to test.
   * @param string[] $globs
   *   The globs; an empty set matches nothing.
   *
   * @return bool
   *   TRUE when at least one glob matches the name.
   */
  public static function matches(string $name, array $globs): bool {
    foreach ($globs as $glob) {
      $regex = '#^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($glob, '#')) . '$#';

      if (preg_match($regex, $name) === 1) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
