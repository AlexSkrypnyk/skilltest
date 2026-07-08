<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Hooks;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;

/**
 * Proves the repository's enforcement hooks actually enforce.
 *
 * For each hook declared in `skilltest.yml`, the runner executes the real hook
 * script with each crafted case's tool input on stdin - the runtime's
 * PreToolUse protocol - and asserts the decision: `expect: block` requires the
 * blocking exit code, `expect: allow` requires success. This is what makes "the
 * harness is the enforcement boundary" testable without a model: the check
 * fails the moment a hook stops blocking what it must block.
 *
 * A declared script that is missing or not executable is a configuration error
 * that aborts the run, so a hook can never silently pass by not running. Process
 * execution and the filesystem probe are both funnelled through injectable
 * closures so the verdict logic is unit-testable without spawning a process.
 */
final class HookRunner {

  /**
   * The check id prefix hook results render under.
   */
  public const string ID_PREFIX = 'hooks.';

  /**
   * The exit code a hook returns to block the tool call.
   */
  public const int BLOCK_EXIT = 2;

  /**
   * The exit code a hook returns to allow the tool call.
   */
  public const int ALLOW_EXIT = 0;

  /**
   * The default per-case wall-clock budget, in seconds.
   */
  public const float DEFAULT_TIMEOUT = 10.0;

  /**
   * The exit code reported when a hook exceeds its timeout.
   */
  public const int TIMEOUT_EXIT = 124;

  /**
   * Runs a hook script with a payload on stdin, returning its exit and stderr.
   *
   * @var \Closure(string, string, string): array{0: int, 1: string}
   */
  protected \Closure $runner;

  /**
   * Decides whether a resolved script path is present and executable.
   *
   * @var \Closure(string): bool
   */
  protected \Closure $ready;

  /**
   * Constructs a HookRunner.
   *
   * @param string $root
   *   The repository root; relative script paths resolve against it and hook
   *   scripts execute with it as the working directory.
   * @param \Closure|null $runner
   *   A runner taking the script path, working directory, and stdin payload and
   *   returning `[exitCode, stderr]`. Defaults to a real `proc_open` run.
   * @param \Closure|null $ready
   *   A predicate taking a resolved absolute path and returning whether it is a
   *   runnable script. Defaults to `is_file() && is_executable()`.
   * @param float $timeout
   *   The per-case wall-clock budget, in seconds, before a hook is terminated.
   */
  public function __construct(
    protected string $root,
    ?\Closure $runner = NULL,
    ?\Closure $ready = NULL,
    protected float $timeout = self::DEFAULT_TIMEOUT,
  ) {
    $this->runner = $runner ?? $this->exec(...);
    $this->ready = $ready ?? static fn(string $path): bool => is_file($path) && is_executable($path);
  }

  /**
   * Runs every declared hook's cases and returns one result per case.
   *
   * @param array<int, array<mixed>> $hooks
   *   The hook declarations, each a `script` and a list of `cases`.
   *
   * @return list<\AlexSkrypnyk\SkillTest\Contract\CheckResult>
   *   One result per case, in declaration order.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When a hook names no script, its script is missing or not executable, or
   *   a case omits its tool or declares an `expect` other than block/allow.
   */
  public function run(array $hooks): array {
    $results = [];

    foreach ($hooks as $index => $hook) {
      $script = Data::toStringOrNull(Data::get($hook, 'script'));

      if ($script === NULL || $script === '') {
        throw new ConfigException('hook is missing a script.', '', sprintf('hooks.%d.script', $index));
      }

      $path = str_starts_with($script, '/') ? $script : $this->root . '/' . $script;

      if (!($this->ready)($path)) {
        throw new ConfigException(sprintf('hook script not found or not executable: %s', $script), '', sprintf('hooks.%d.script', $index));
      }

      foreach (Data::toArrayList(Data::get($hook, 'cases')) as $case_index => $raw_case) {
        $case = $this->buildCase($raw_case, $index, $case_index);
        $results[] = $this->runCase($path, $script, $case);
      }
    }

    return $results;
  }

  /**
   * Builds and validates a single case from its raw declaration.
   *
   * @param array<mixed> $raw
   *   The raw case: a `tool`, an `input` object, and an `expect`.
   * @param int $hook_index
   *   The declaring hook's index, for the error pointer.
   * @param int $case_index
   *   The case's index within the hook, for the error pointer.
   *
   * @return \AlexSkrypnyk\SkillTest\Hooks\HookCase
   *   The validated case.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the case omits its tool or its `expect` is not block/allow.
   */
  protected function buildCase(array $raw, int $hook_index, int $case_index): HookCase {
    $tool = Data::toStringOrNull(Data::get($raw, 'tool'));

    if ($tool === NULL || $tool === '') {
      throw new ConfigException('hook case is missing a tool.', '', sprintf('hooks.%d.cases.%d.tool', $hook_index, $case_index));
    }

    $expect = Data::toStringOrNull(Data::get($raw, 'expect'));

    if ($expect !== HookCase::EXPECT_BLOCK && $expect !== HookCase::EXPECT_ALLOW) {
      throw new ConfigException(sprintf("hook case expect must be '%s' or '%s'.", HookCase::EXPECT_BLOCK, HookCase::EXPECT_ALLOW), '', sprintf('hooks.%d.cases.%d.expect', $hook_index, $case_index));
    }

    return new HookCase($tool, Data::toArray(Data::get($raw, 'input')), $expect);
  }

  /**
   * Executes one case against its hook script and renders the verdict.
   *
   * @param string $path
   *   The resolved, runnable script path.
   * @param string $script
   *   The script as declared, used for the id, label, and messages.
   * @param \AlexSkrypnyk\SkillTest\Hooks\HookCase $case
   *   The case to run.
   *
   * @return \AlexSkrypnyk\SkillTest\Contract\CheckResult
   *   The pass/fail result carrying the input as evidence.
   */
  protected function runCase(string $path, string $script, HookCase $case): CheckResult {
    $expected = $case->expectsBlock() ? self::BLOCK_EXIT : self::ALLOW_EXIT;
    [$exit_code, $stderr] = ($this->runner)($path, $this->root, $case->payload());

    $id = self::ID_PREFIX . pathinfo($script, PATHINFO_FILENAME);
    $evidence = $case->inputSummary();

    if ($exit_code === $expected) {
      $verb = $case->expectsBlock() ? 'blocked' : 'allowed';
      $message = sprintf("hook '%s' %s %s input as expected.", $script, $verb, $case->tool);

      return CheckResult::pass($id, $script, $evidence, $message);
    }

    return CheckResult::fail($id, $script, $evidence, $this->failMessage($script, $case, $expected, $exit_code, $stderr));
  }

  /**
   * Builds the failure message, naming the input, the codes, and stderr.
   *
   * @param string $script
   *   The script as declared.
   * @param \AlexSkrypnyk\SkillTest\Hooks\HookCase $case
   *   The case that failed.
   * @param int $expected
   *   The exit code the expected decision requires.
   * @param int $exit_code
   *   The exit code the hook actually returned.
   * @param string $stderr
   *   The hook's captured stderr.
   *
   * @return string
   *   The failure message.
   */
  protected function failMessage(string $script, HookCase $case, int $expected, int $exit_code, string $stderr): string {
    $message = sprintf("hook '%s' on %s input %s: expected %s (exit %d) but got exit %d", $script, $case->tool, $case->inputSummary(), $case->expect, $expected, $exit_code);

    if ($exit_code === self::TIMEOUT_EXIT) {
      $message .= sprintf(' (timed out after %ss)', $this->timeout);
    }

    $stderr = trim($stderr);
    if ($stderr !== '') {
      $message .= sprintf(' - stderr: %s', $stderr);
    }

    return $message . '.';
  }

  /**
   * Runs a hook script through `proc_open`, feeding the payload on stdin.
   *
   * Stdout is discarded to `/dev/null` so a chatty hook cannot fill an unread
   * pipe and deadlock; stderr is the one pipe read, because it carries the
   * diagnostic a failing case surfaces. A hook that outlives its timeout is
   * terminated and reported with the timeout code so a hang cannot block the
   * caller indefinitely.
   *
   * @param string $path
   *   The resolved script path to execute.
   * @param string $cwd
   *   The working directory.
   * @param string $stdin
   *   The PreToolUse payload to write to the hook's stdin.
   *
   * @return array{0: int, 1: string}
   *   The exit code (or the timeout code when terminated) and captured stderr.
   */
  protected function exec(string $path, string $cwd, string $stdin): array {
    $descriptors = [
      0 => ['pipe', 'r'],
      1 => ['file', '/dev/null', 'w'],
      2 => ['pipe', 'w'],
    ];

    $process = proc_open(escapeshellarg($path), $descriptors, $pipes, $cwd);

    // @codeCoverageIgnoreStart
    if (!is_resource($process)) {
      return [1, ''];
    }
    // @codeCoverageIgnoreEnd
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    stream_set_blocking($pipes[2], FALSE);

    $stderr = '';
    $exit_code = self::TIMEOUT_EXIT;
    $deadline = microtime(TRUE) + $this->timeout;

    while (TRUE) {
      $chunk = fread($pipes[2], 8192);

      if ($chunk !== FALSE && $chunk !== '') {
        $stderr .= $chunk;

        continue;
      }

      $status = proc_get_status($process);

      if (!$status['running']) {
        $exit_code = $status['exitcode'];

        break;
      }

      if (microtime(TRUE) >= $deadline) {
        proc_terminate($process);

        break;
      }

      usleep(1000);
    }

    fclose($pipes[2]);
    proc_close($process);

    return [$exit_code, $stderr];
  }

}
