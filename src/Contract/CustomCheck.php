<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Contract;

use AlexSkrypnyk\SkillTest\Config\Data;

/**
 * Runs a skill's custom check script and renders its verdict as a result.
 *
 * The escape hatch for the genuinely skill-specific residue: a check may be a
 * script instead of a declared pattern. The script is invoked with the
 * transcript path as `$1` and the skill directory as `$2`; its exit code
 * decides pass or fail, and an optional JSON object on stdout
 * (`{"pass": bool, "message": "...", "evidence": "..."}`) enriches - or
 * overrides - the verdict so it renders like any pre-baked check. Process
 * execution is funnelled through one injectable runner so the verdict logic is
 * unit-testable without spawning a process.
 */
final readonly class CustomCheck {

  /**
   * The check id prefix custom checks render under.
   */
  public const string ID_PREFIX = 'check.';

  /**
   * Runs a command and returns its exit code and captured stdout.
   *
   * @var \Closure(string, string): array{0: int, 1: string}
   */
  protected \Closure $runner;

  /**
   * Constructs a CustomCheck runner.
   *
   * @param string $root
   *   The repository root; check `run` commands execute with this as the
   *   working directory, so relative script paths resolve against it.
   * @param \Closure|null $runner
   *   A runner taking the assembled command and working directory and returning
   *   `[exitCode, stdout]`. Defaults to a real process run via `proc_open`.
   */
  public function __construct(
    protected string $root,
    ?\Closure $runner = NULL,
  ) {
    $this->runner = $runner ?? self::exec(...);
  }

  /**
   * Runs one custom check entry against a transcript.
   *
   * @param array<mixed> $check
   *   The check entry: a `name` and a `run` command.
   * @param string $transcript_path
   *   The transcript path passed to the script as `$1`.
   * @param string $skill_dir
   *   The skill directory passed to the script as `$2`.
   *
   * @return \AlexSkrypnyk\SkillTest\Contract\CheckResult|null
   *   The rendered result, or NULL when the entry names no script to run.
   */
  public function run(array $check, string $transcript_path, string $skill_dir): ?CheckResult {
    $name = Data::toStringOrNull(Data::get($check, 'name'));
    $run = Data::toStringOrNull(Data::get($check, 'run'));

    if ($name === NULL || $name === '' || $run === NULL || $run === '') {
      return NULL;
    }

    $command = sprintf('%s %s %s', $run, escapeshellarg($transcript_path), escapeshellarg($skill_dir));
    [$exit_code, $stdout] = ($this->runner)($command, $this->root);

    $verdict = $this->parseVerdict($stdout);
    $pass = $verdict['pass'] ?? ($exit_code === 0);
    $evidence = $verdict['evidence'] ?? '';
    $message = $verdict['message'] ?? ($pass
      ? sprintf("custom check '%s' passed.", $name)
      : sprintf("custom check '%s' failed (exit %d).", $name, $exit_code));

    $id = self::ID_PREFIX . $name;

    return $pass
      ? CheckResult::pass($id, $name, $evidence, $message)
      : CheckResult::fail($id, $name, $evidence, $message);
  }

  /**
   * Parses an optional JSON verdict from a script's stdout.
   *
   * @param string $stdout
   *   The captured stdout.
   *
   * @return array{pass?: bool, message?: string, evidence?: string}
   *   The recognised verdict keys; empty when stdout carries no JSON object.
   */
  protected function parseVerdict(string $stdout): array {
    $trimmed = trim($stdout);

    if ($trimmed === '') {
      return [];
    }

    $decoded = json_decode($trimmed, TRUE);

    if (!is_array($decoded)) {
      return [];
    }

    $verdict = [];

    if (isset($decoded['pass']) && is_bool($decoded['pass'])) {
      $verdict['pass'] = $decoded['pass'];
    }

    $message = Data::toStringOrNull($decoded['message'] ?? NULL);
    if ($message !== NULL) {
      $verdict['message'] = $message;
    }

    $evidence = Data::toStringOrNull($decoded['evidence'] ?? NULL);
    if ($evidence !== NULL) {
      $verdict['evidence'] = $evidence;
    }

    return $verdict;
  }

  /**
   * Runs a command through `proc_open`, capturing its exit code and stdout.
   *
   * @param string $command
   *   The command to run through the shell.
   * @param string $cwd
   *   The working directory.
   *
   * @return array{0: int, 1: string}
   *   The exit code and captured stdout.
   */
  protected static function exec(string $command, string $cwd): array {
    $descriptors = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, $cwd);

    // @codeCoverageIgnoreStart
    if (!is_resource($process)) {
      return [1, ''];
    }
    // @codeCoverageIgnoreEnd
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    // Drain stderr so a chatty script cannot block on a full pipe buffer.
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exit_code = proc_close($process);

    return [$exit_code, $stdout === FALSE ? '' : $stdout];
  }

}
