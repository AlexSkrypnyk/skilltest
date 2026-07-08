<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Contract;

use AlexSkrypnyk\SkillTest\Config\Pcre;

/**
 * Normalises command invocation forms to their canonical name before matching.
 *
 * Repo config `aliases:` maps a canonical name to a delimiter-less pattern;
 * each occurrence of the pattern in a command is rewritten to the canonical
 * name so that every invocation form collapses to one. This is what makes
 * `php bin/harness workflow start`, `./bin/harness workflow start`, and
 * `harness workflow start` all satisfy a single `harness workflow start`
 * contract pattern.
 */
final class Aliases {

  /**
   * Rewrites every aliased invocation form in a command to its canonical name.
   *
   * @param string $command
   *   The command to normalise.
   * @param array<string, string> $aliases
   *   Canonical name keyed to the delimiter-less pattern that identifies it.
   *
   * @return string
   *   The command with each alias pattern replaced by its canonical name.
   */
  public static function normalise(string $command, array $aliases): string {
    foreach ($aliases as $canonical => $pattern) {
      $replaced = preg_replace(Pcre::delimit($pattern), $canonical, $command);

      // A malformed pattern yields NULL. Repo aliases are validated to compile
      // before the engine runs, so this guards a case a validated config cannot
      // reach; it leaves the command untouched rather than dropping evidence.
      // @codeCoverageIgnoreStart
      if ($replaced === NULL) {
        continue;
      }
      // @codeCoverageIgnoreEnd
      $command = $replaced;
    }

    return $command;
  }

  /**
   * Normalises a list of commands.
   *
   * @param string[] $commands
   *   The commands to normalise.
   * @param array<string, string> $aliases
   *   Canonical name keyed to the delimiter-less pattern that identifies it.
   *
   * @return list<string>
   *   The normalised commands, in order.
   */
  public static function normaliseAll(array $commands, array $aliases): array {
    return array_values(array_map(static fn(string $command): string => self::normalise($command, $aliases), $commands));
  }

}
