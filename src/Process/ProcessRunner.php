<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Process;

/**
 * Runs a command as a child process under a wall-clock timeout.
 *
 * The single seam every part of the tool uses to shell out: contract checks,
 * the llm runner, `init --ai` drafting, and the structure group's command-
 * reference resolution all need the same guarantees, so the mechanics live
 * once here. Stdout is the one pipe read; stderr is discarded to `/dev/null`
 * so a chatty process cannot fill an unread pipe buffer and deadlock. The
 * single pipe is drained without blocking while the process runs, and a
 * process that outlives its timeout is terminated - escalating to an
 * untrappable SIGKILL when it traps or ignores the first signal - so a hang
 * cannot block the caller indefinitely.
 */
final readonly class ProcessRunner {

  /**
   * The default wall-clock budget, in seconds, for one command.
   */
  public const float DEFAULT_TIMEOUT = 60.0;

  /**
   * The exit code reported when a command exceeds its timeout.
   */
  public const int TIMEOUT_EXIT = 124;

  /**
   * Seconds to wait for a terminated command to exit before force-killing it.
   */
  public const float TERMINATE_GRACE = 1.0;

  /**
   * Constructs a ProcessRunner.
   *
   * @param float $timeout
   *   The wall-clock budget, in seconds, before a running command is
   *   terminated.
   */
  public function __construct(protected float $timeout = self::DEFAULT_TIMEOUT) {}

  /**
   * Runs a command through `proc_open`, capturing its exit code and stdout.
   *
   * @param string $command
   *   The command to run through the shell.
   * @param string $cwd
   *   The working directory.
   *
   * @return array{0: int, 1: string}
   *   The exit code (or the timeout code when terminated) and captured stdout.
   */
  public function run(string $command, string $cwd): array {
    $descriptors = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['file', '/dev/null', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, $cwd);

    // @codeCoverageIgnoreStart
    if (!is_resource($process)) {
      return [1, ''];
    }
    // @codeCoverageIgnoreEnd
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], FALSE);

    $stdout = '';
    $exit_code = self::TIMEOUT_EXIT;
    $deadline = microtime(TRUE) + $this->timeout;

    while (TRUE) {
      // Enforce the deadline on every iteration, before reading: a command that
      // streams stdout without pause must not keep the loop reading past its
      // budget just because a chunk is always available.
      if (microtime(TRUE) >= $deadline) {
        self::terminate($process);

        break;
      }

      $chunk = fread($pipes[1], 8192);

      if ($chunk !== FALSE && $chunk !== '') {
        $stdout .= $chunk;

        continue;
      }

      $status = proc_get_status($process);

      if (!$status['running']) {
        $exit_code = $status['exitcode'];

        break;
      }

      usleep(1000);
    }

    fclose($pipes[1]);
    proc_close($process);

    return [$exit_code, $stdout];
  }

  /**
   * Terminates a process, escalating to SIGKILL when it ignores the first signal.
   *
   * proc_close() waits for the process to exit, so a command that traps or
   * ignores the termination signal would block it forever. A brief grace period
   * followed by an untrappable SIGKILL guarantees the wait cannot hang.
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
