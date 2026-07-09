<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live\Mcp;

/**
 * Resolves the command that re-launches skilltest to serve one MCP mock.
 *
 * A mock runs as a child process the agent spawns, so the per-trial MCP config
 * must name a command that starts this same tool in its `mcp-serve` mode. That
 * command differs between a Composer checkout, where the entry point is the
 * `skilltest` script, and a packaged PHAR, where it is the archive itself; both
 * are launched through the running PHP binary. The resolution is split so the
 * pure part - given the binary, the optional PHAR path, and the entry script,
 * pick the entry - is unit-tested, while the thin wrapper that reads the
 * ambient PHP binary and PHAR state is the only environment-coupled code.
 */
final class SelfInvocation {

  /**
   * Picks the `[php, entry]` command pair for the current runtime shape.
   *
   * @param string $php
   *   The PHP binary that runs the child.
   * @param string|null $phar
   *   The running PHAR path, or NULL/empty in a Composer checkout.
   * @param string $script
   *   The entry script used when not running from a PHAR.
   *
   * @return array{0: string, 1: string}
   *   The PHP binary and the entry argument (the PHAR when packaged, else the
   *   script).
   */
  public static function command(string $php, ?string $phar, string $script): array {
    return [$php, $phar !== NULL && $phar !== '' ? $phar : $script];
  }

  /**
   * Resolves the command pair from the ambient runtime.
   *
   * @return array{0: string, 1: string}
   *   The PHP binary and the entry argument.
   */
  public static function resolve(): array {
    // @codeCoverageIgnoreStart
    $phar = \Phar::running(FALSE);
    $script = $_SERVER['SCRIPT_FILENAME'] ?? NULL;

    if (!is_string($script) || $script === '') {
      $argv = $_SERVER['argv'] ?? NULL;
      $script = is_array($argv) && is_string($argv[0] ?? NULL) ? $argv[0] : 'skilltest';
    }

    return self::command(PHP_BINARY, $phar === '' ? NULL : $phar, $script);
    // @codeCoverageIgnoreEnd
  }

}
