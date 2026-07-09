<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

use AlexSkrypnyk\SkillTest\Process\ProcessRunner;

/**
 * Asks a pinned model to play the user and make one conversational move.
 *
 * This is the invocation seam the conversation loop drives after every agent
 * turn of an interactive trial: it builds the persona-and-dialogue prompt, runs
 * the responder through the same stubbable process seam the judge and runner use,
 * and parses the reply into a {@see ResponderDecision}. A responder process that
 * exits non-zero and a response that cannot be parsed into one of the three
 * legitimate moves both yield NULL, so the loop treats a broken or nonsensical
 * responder as a failure rather than a silent stop. The model is pinned by the
 * task's responder config and never derived from the execution model. The process
 * seam is injectable so the loop is tested without spending a token.
 */
final readonly class Responder {

  /**
   * The default wall-clock budget, in seconds, for one responder call.
   */
  public const float DEFAULT_TIMEOUT = 120.0;

  /**
   * Runs a command and returns its exit code and captured stdout.
   *
   * @var \Closure(string, string): array{0: int, 1: string}
   */
  protected \Closure $runner;

  /**
   * Constructs a Responder.
   *
   * @param string $binary
   *   The agent binary (or command prefix) invoked with `-p <prompt>`.
   * @param \Closure|null $runner
   *   A runner taking the assembled command and working directory and returning
   *   `[exitCode, stdout]`. Defaults to a real process run via ProcessRunner.
   * @param float $timeout
   *   The wall-clock budget, in seconds, before the responder call is terminated.
   */
  public function __construct(
    protected string $binary,
    ?\Closure $runner = NULL,
    float $timeout = self::DEFAULT_TIMEOUT,
  ) {
    $this->runner = $runner ?? (new ProcessRunner($timeout))->run(...);
  }

  /**
   * Asks the responder for its next move given the dialogue so far.
   *
   * @param \AlexSkrypnyk\SkillTest\Live\ResponderConfig $config
   *   The task's responder configuration, supplying the persona and the model.
   * @param array<int, array{role: string, text: string}> $dialogue
   *   The conversation so far, in order.
   * @param string $cwd
   *   The working directory the responder call runs in.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\ResponderDecision|null
   *   The parsed decision, or NULL when the responder process failed or returned
   *   an unusable response.
   */
  public function respond(ResponderConfig $config, array $dialogue, string $cwd): ?ResponderDecision {
    $prompt = ResponderPrompt::build($config->instructions, $dialogue);
    $command = ResponderCommand::build($this->binary, $prompt, $config->model);

    [$exit_code, $stdout] = ($this->runner)($command, $cwd);

    if ($exit_code !== 0) {
      return NULL;
    }

    return (new ResponderDecisionParser())->parse($stdout);
  }

}
