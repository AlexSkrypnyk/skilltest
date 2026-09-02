<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Ai;

use AlexSkrypnyk\SkillTest\Process\ProcessRunner;

/**
 * The one-shot `claude -p` prompt seam.
 *
 * The prompt is passed to `claude -p` and the model's stdout is returned
 * verbatim. A non-zero exit - an unauthenticated or absent CLI, or a timeout
 * - yields NULL rather than a partial result. Process execution goes through
 * one injectable runner, so a test needs neither a spawned process nor real
 * credentials.
 */
final readonly class PromptRunner {

  /**
   * The default agent binary invoked for a one-shot prompt.
   */
  public const string DEFAULT_BINARY = 'claude';

  /**
   * The default wall-clock budget, in seconds, for one prompt.
   */
  public const float DEFAULT_TIMEOUT = 120.0;

  /**
   * Runs a command and returns its exit code and captured stdout.
   *
   * @var \Closure(string, string): array{0: int, 1: string}
   */
  protected \Closure $runner;

  /**
   * Constructs a PromptRunner.
   *
   * @param \Closure|null $runner
   *   A runner taking the assembled command and working directory and returning
   *   `[exitCode, stdout]`. Defaults to a real process run via ProcessRunner.
   * @param string $binary
   *   The agent binary (or command prefix) invoked with `-p <prompt>`.
   * @param float $timeout
   *   The wall-clock budget, in seconds, before the prompt is terminated.
   */
  public function __construct(
    ?\Closure $runner = NULL,
    protected string $binary = self::DEFAULT_BINARY,
    float $timeout = self::DEFAULT_TIMEOUT,
  ) {
    $this->runner = $runner ?? (new ProcessRunner($timeout))->run(...);
  }

  /**
   * Runs one prompt and returns the model's stdout.
   *
   * @param string $prompt
   *   The prompt handed to the agent.
   *
   * @return string|null
   *   The captured stdout on success, or NULL when the agent exits non-zero,
   *   including a timeout.
   */
  public function run(string $prompt): ?string {
    $command = sprintf('%s -p %s', $this->binary, escapeshellarg($prompt));

    [$exit_code, $stdout] = ($this->runner)($command, '.');

    return $exit_code === 0 ? $stdout : NULL;
  }

}
