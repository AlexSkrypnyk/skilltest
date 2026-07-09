<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

/**
 * Runs many commands concurrently under a bounded worker pool and a timeout.
 *
 * Live trials are independent and slow (each is a real agent run), so running
 * them one at a time wastes wall-clock; this drives up to `concurrency` child
 * processes at once and starts the next queued command the moment a slot frees.
 * Each command carries its own working directory (its trial workspace) and its
 * stdout is captured verbatim as that trial's transcript; stderr is discarded so
 * a chatty agent cannot deadlock on an unread pipe. Every process is bounded by
 * the same wall-clock timeout - a hang is terminated (escalating to an
 * untrappable SIGKILL) and reported with the timeout exit code rather than
 * stalling the whole pool. Commands run through `exec` so the tracked process is
 * the agent itself, not a wrapping shell that would outlive a kill. Results are
 * returned keyed and ordered exactly as the commands were given, so a caller
 * gets the same outcome whether concurrency is 1 or 16.
 */
final readonly class ProcessPool {

  /**
   * The exit code reported when a command exceeds its timeout.
   */
  public const int TIMEOUT_EXIT = 124;

  /**
   * Seconds to wait for a terminated command to exit before force-killing it.
   */
  public const float TERMINATE_GRACE = 1.0;

  /**
   * Constructs a ProcessPool.
   *
   * @param int $concurrency
   *   The maximum number of processes to run at once; clamped to at least one.
   * @param float $timeout
   *   The per-process wall-clock budget, in seconds, before termination.
   */
  public function __construct(
    protected int $concurrency = 1,
    protected float $timeout = 300.0,
  ) {}

  /**
   * Runs the given commands, returning each one's exit, stdout, and duration.
   *
   * @param array<array-key, array{0: string, 1: string}> $commands
   *   The commands to run, keyed; each is a `[command, working directory]`
   *   pair.
   *
   * @return array<array-key, array{0: int, 1: string, 2: int}>
   *   Each command's `[exit code, captured stdout, duration in ms]`, in the
   *   same keys and order as the input.
   */
  public function run(array $commands): array {
    $limit = max(1, $this->concurrency);
    $queue = array_keys($commands);
    $running = [];
    $results = [];

    while ($queue !== [] || $running !== []) {
      while (count($running) < $limit && $queue !== []) {
        $key = array_shift($queue);
        $this->launch($key, $commands[$key], $running, $results);
      }

      $progressed = FALSE;

      foreach (array_keys($running) as $key) {
        if ($this->poll($key, $running, $results)) {
          $progressed = TRUE;
        }
      }

      if (!$progressed) {
        usleep(1000);
      }
    }

    $ordered = [];
    foreach (array_keys($commands) as $key) {
      $ordered[$key] = $results[$key];
    }

    return $ordered;
  }

  /**
   * Launches one command, recording it as running or as an immediate failure.
   *
   * @param array-key $key
   *   The command key.
   * @param array{0: string, 1: string} $command
   *   The command and its working directory.
   * @param array<array-key, array{proc: resource, pipe: resource, stdout: string, start: float, deadline: float}> $running
   *   The in-flight processes, appended to in place.
   * @param array<array-key, array{0: int, 1: string, 2: int}> $results
   *   The completed results, appended to in place on a spawn failure.
   */
  protected function launch(mixed $key, array $command, array &$running, array &$results): void {
    [$line, $cwd] = $command;

    $descriptors = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['file', '/dev/null', 'w'],
    ];

    $process = proc_open('exec ' . $line, $descriptors, $pipes, $cwd);

    // @codeCoverageIgnoreStart
    if (!is_resource($process)) {
      $results[$key] = [1, '', 0];

      return;
    }
    // @codeCoverageIgnoreEnd
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], FALSE);

    $now = microtime(TRUE);
    $running[$key] = [
      'proc' => $process,
      'pipe' => $pipes[1],
      'stdout' => '',
      'start' => $now,
      'deadline' => $now + $this->timeout,
    ];
  }

  /**
   * Polls one running process for output, completion, or a timeout.
   *
   * @param array-key $key
   *   The command key.
   * @param array<array-key, array{proc: resource, pipe: resource, stdout: string, start: float, deadline: float}> $running
   *   The in-flight processes, mutated in place.
   * @param array<array-key, array{0: int, 1: string, 2: int}> $results
   *   The completed results, appended to in place when the process settles.
   *
   * @return bool
   *   TRUE when the poll made progress (read output or the process settled).
   */
  protected function poll(mixed $key, array &$running, array &$results): bool {
    if (microtime(TRUE) >= $running[$key]['deadline']) {
      self::terminate($running[$key]['proc']);
      $this->finish($key, self::TIMEOUT_EXIT, $running, $results);

      return TRUE;
    }

    $chunk = fread($running[$key]['pipe'], 8192);

    if ($chunk !== FALSE && $chunk !== '') {
      $running[$key]['stdout'] .= $chunk;

      return TRUE;
    }

    $status = proc_get_status($running[$key]['proc']);

    // The process is only finished once fread has returned empty, so the pipe
    // is already fully drained and its exit code and output can be recorded.
    if (!$status['running']) {
      $this->finish($key, $status['exitcode'], $running, $results);

      return TRUE;
    }

    return FALSE;
  }

  /**
   * Closes a settled process and records its result.
   *
   * @param array-key $key
   *   The command key.
   * @param int $exit_code
   *   The exit code to record.
   * @param array<array-key, array{proc: resource, pipe: resource, stdout: string, start: float, deadline: float}> $running
   *   The in-flight processes; the entry is removed.
   * @param array<array-key, array{0: int, 1: string, 2: int}> $results
   *   The completed results, appended to in place.
   */
  protected function finish(mixed $key, int $exit_code, array &$running, array &$results): void {
    $duration_ms = (int) round((microtime(TRUE) - $running[$key]['start']) * 1000);

    fclose($running[$key]['pipe']);
    proc_close($running[$key]['proc']);

    $results[$key] = [$exit_code, $running[$key]['stdout'], $duration_ms];
    unset($running[$key]);
  }

  /**
   * Terminates a process, escalating to SIGKILL if it ignores the first signal.
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
