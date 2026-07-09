<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Process\ProcessRunner;

/**
 * Runs the lifecycle hooks that bracket llm work with deterministic state.
 *
 * External state - boards, PRs, deployments, a shared test bed - is the hard
 * part of testing skills whose side effects are not filesystem-local. Hooks
 * give the suite four ordered seams to reset it: `before-run`/`after-run`
 * bracket the whole invocation, `before-task`/`after-task` bracket every trial.
 * A `before-*` hook that fails its acceptable `exit-codes` aborts the run with
 * a configuration error when it declares `error-on-fail`, so a broken setup can
 * never let a trial run against dirty state; every other failure warns and
 * continues, because a failed teardown must not mask the trial's own verdict.
 * Hook commands carry template variables (`{{ skill }}`, `{{ task }}`,
 * `{{ trial }}`, `{{ model }}`, `{{ workspace }}`, and `{{ vars.* }}` from a
 * task's inputs) substituted per call. Every hook is validated up front, so a
 * hook missing its command is caught before any trial rather than mid-run. The
 * process seam is injectable so the orchestration is testable without spawning
 * a process.
 */
final class Lifecycle {

  /**
   * The phase run once before any trial.
   */
  public const string BEFORE_RUN = 'before-run';

  /**
   * The phase run before every trial.
   */
  public const string BEFORE_TASK = 'before-task';

  /**
   * The phase run after every trial.
   */
  public const string AFTER_TASK = 'after-task';

  /**
   * The phase run once after all trials.
   */
  public const string AFTER_RUN = 'after-run';

  /**
   * The exit codes a hook accepts when it declares none.
   */
  public const array DEFAULT_EXIT_CODES = [0];

  /**
   * Runs a hook command in a working directory, returning its exit and stdout.
   *
   * @var \Closure(string, string): array{0: int, 1: string}
   */
  protected \Closure $runner;

  /**
   * Reports a non-aborting hook failure.
   *
   * @var \Closure(string): void
   */
  protected \Closure $warn;

  /**
   * The validated hooks keyed by phase, each with its resolved run settings.
   *
   * @var array<string, array<int, array{command: string, cwd: string, exitCodes: int[], errorOnFail: bool}>>
   */
  protected array $phases;

  /**
   * Constructs a Lifecycle.
   *
   * @param string $root
   *   The repository root; the default working directory and the base relative
   *   `working-directory` paths resolve against.
   * @param array<mixed> $config
   *   The `llm.lifecycle` block: hook lists keyed by phase.
   * @param \Closure|null $runner
   *   A runner taking a command and working directory and returning
   *   `[exitCode, stdout]`. Defaults to a real process run via ProcessRunner.
   * @param \Closure|null $warn
   *   A sink for non-aborting failure messages. Defaults to a no-op.
   * @param float $timeout
   *   The per-hook wall-clock budget, in seconds.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When a declared hook is missing its command.
   */
  public function __construct(
    protected string $root,
    protected array $config,
    ?\Closure $runner = NULL,
    ?\Closure $warn = NULL,
    float $timeout = ProcessRunner::DEFAULT_TIMEOUT,
  ) {
    $this->runner = $runner ?? (new ProcessRunner($timeout))->run(...);
    $this->warn = $warn ?? static function (string $message): void {};
    $this->phases = $this->parse();
  }

  /**
   * Runs the `before-run` hooks once before any trial.
   *
   * @param array<string, string> $vars
   *   The template variables available to the hook commands.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When an `error-on-fail` hook fails its acceptable exit codes.
   */
  public function beforeRun(array $vars): void {
    $this->runPhase(self::BEFORE_RUN, $vars, TRUE);
  }

  /**
   * Runs the `before-task` hooks before one trial.
   *
   * @param array<string, string> $vars
   *   The template variables available to the hook commands.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When an `error-on-fail` hook fails its acceptable exit codes.
   */
  public function beforeTask(array $vars): void {
    $this->runPhase(self::BEFORE_TASK, $vars, TRUE);
  }

  /**
   * Runs the `after-task` hooks after one trial; failures only warn.
   *
   * @param array<string, string> $vars
   *   The template variables available to the hook commands.
   */
  public function afterTask(array $vars): void {
    $this->runPhase(self::AFTER_TASK, $vars, FALSE);
  }

  /**
   * Runs the `after-run` hooks once after all trials; failures only warn.
   *
   * @param array<string, string> $vars
   *   The template variables available to the hook commands.
   */
  public function afterRun(array $vars): void {
    $this->runPhase(self::AFTER_RUN, $vars, FALSE);
  }

  /**
   * Runs one phase's hooks, aborting or warning on a failed hook.
   *
   * @param string $phase
   *   The phase to run.
   * @param array<string, string> $vars
   *   The template variables available to the hook commands.
   * @param bool $abortable
   *   Whether an `error-on-fail` failure aborts (a setup phase) rather than
   *   only warning (a teardown phase).
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When an abortable `error-on-fail` hook fails its acceptable exit codes.
   */
  protected function runPhase(string $phase, array $vars, bool $abortable): void {
    foreach ($this->phases[$phase] as $hook) {
      $command = $this->substitute($hook['command'], $vars);
      [$exit_code] = ($this->runner)($command, $hook['cwd']);

      if (in_array($exit_code, $hook['exitCodes'], TRUE)) {
        continue;
      }

      $message = sprintf("lifecycle %s hook '%s' failed with exit %d (expected %s).", $phase, $command, $exit_code, implode(', ', $hook['exitCodes']));

      if ($abortable && $hook['errorOnFail']) {
        throw new ConfigException($message, '', 'llm.lifecycle.' . $phase);
      }

      ($this->warn)($message);
    }
  }

  /**
   * Parses and validates every phase's hooks into resolved run settings.
   *
   * @return array<string, array<int, array{command: string, cwd: string, exitCodes: int[], errorOnFail: bool}>>
   *   The hooks keyed by phase.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When a declared hook is missing its command.
   */
  protected function parse(): array {
    $phases = [];

    foreach ([self::BEFORE_RUN, self::BEFORE_TASK, self::AFTER_TASK, self::AFTER_RUN] as $phase) {
      $hooks = [];

      foreach (Data::toArrayList(Data::get($this->config, $phase)) as $index => $raw) {
        $command = Data::toStringOrNull(Data::get($raw, 'command'));

        if ($command === NULL || $command === '') {
          throw new ConfigException("a lifecycle hook requires a 'command'.", '', sprintf('llm.lifecycle.%s.%d.command', $phase, $index));
        }

        $hooks[] = [
          'command' => $command,
          'cwd' => $this->workingDirectory(Data::toStringOrNull(Data::get($raw, 'working-directory'))),
          'exitCodes' => $this->exitCodes(Data::get($raw, 'exit-codes')),
          'errorOnFail' => Data::toBoolOrNull(Data::get($raw, 'error-on-fail')) ?? FALSE,
        ];
      }

      $phases[$phase] = $hooks;
    }

    return $phases;
  }

  /**
   * Resolves a hook's working directory: the root, or a path under it.
   *
   * @param string|null $directory
   *   The declared `working-directory`, absolute or relative to the root.
   *
   * @return string
   *   The absolute working directory the hook runs in.
   */
  protected function workingDirectory(?string $directory): string {
    if ($directory === NULL || $directory === '') {
      return $this->root;
    }

    return str_starts_with($directory, '/') ? $directory : rtrim($this->root, '/') . '/' . $directory;
  }

  /**
   * Normalises a hook's acceptable exit codes, defaulting to a clean exit.
   *
   * @param mixed $value
   *   The declared `exit-codes`: an int, a list of ints, or nothing.
   *
   * @return int[]
   *   The acceptable exit codes, or the default when none are declared.
   */
  protected function exitCodes(mixed $value): array {
    if (!is_array($value)) {
      $single = Data::toIntOrNull($value);

      return $single === NULL ? self::DEFAULT_EXIT_CODES : [$single];
    }

    $codes = [];

    foreach ($value as $item) {
      $int = Data::toIntOrNull($item);

      if ($int !== NULL) {
        $codes[] = $int;
      }
    }

    return $codes === [] ? self::DEFAULT_EXIT_CODES : $codes;
  }

  /**
   * Substitutes `{{ name }}` template variables in a hook command.
   *
   * An unknown variable substitutes to an empty string rather than being left
   * as a literal brace expression a shell would mangle.
   *
   * @param string $command
   *   The raw hook command.
   * @param array<string, string> $vars
   *   The available variables, keyed by name (including `vars.*`).
   *
   * @return string
   *   The command with every recognised token substituted.
   */
  protected function substitute(string $command, array $vars): string {
    return (string) preg_replace_callback('/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/', static fn(array $matches): string => $vars[$matches[1]] ?? '', $command);
  }

}
