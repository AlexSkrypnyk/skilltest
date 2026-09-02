<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Process;

/**
 * Shared wall-clock termination for the tool's child-process runners.
 *
 * The single-command runner and the concurrent pool enforce a timeout the same
 * way, so the exit code reported on timeout, the grace period before
 * escalation, and the SIGTERM-then-SIGKILL routine are defined here once.
 */
trait ProcessTerminationTrait {

  /**
   * The exit code reported when a command exceeds its timeout.
   */
  public const int TIMEOUT_EXIT = 124;

  /**
   * Seconds to wait for a terminated command to exit before force-killing it.
   */
  public const float TERMINATE_GRACE = 1.0;

  /**
   * Terminates a process, escalating to SIGKILL if it ignores the first signal.
   *
   * A command that traps or ignores the termination signal would make
   * proc_close() block forever, so a brief grace period followed by an
   * untrappable SIGKILL guarantees the wait cannot hang.
   *
   * @param resource $process
   *   The process handle returned by proc_open().
   */
  protected static function terminate($process): void {
    proc_terminate($process);

    $deadline = microtime(TRUE) + self::TERMINATE_GRACE;

    while (microtime(TRUE) < $deadline) {
      if (!proc_get_status($process)['running']) {
        return;
      }

      usleep(1000);
    }

    proc_terminate($process, 9);
  }

}
