<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Config\LoadedSkill;

/**
 * Runs the single live trial `skilltest record` captures into a fixture.
 *
 * The record command needs exactly one clean trial and its raw transcript - not
 * the whole trials-by-models matrix the llm suite aggregates - so this drives
 * one run through the identical machinery: a fresh {@see TrialWorkspace} the
 * skill is installed into, the same {@see AgentCommand} invocation shape, and a
 * single-command {@see ProcessPool} so the run is bounded by the same timeout.
 * The workspace is torn down whether the run passed, failed, or threw. Grading
 * is deliberately not done here: the command grades the fixture it writes, so
 * the verdict is asserted against the file that ships, and custom checks run
 * once rather than twice. The process and git seams are injectable so the
 * orchestration is testable without a real agent.
 */
final readonly class RecordRunner {

  /**
   * Runs a pool of commands and returns each one's exit, stdout, and duration.
   *
   * @var \Closure(array<array-key, array{0: string, 1: string}>): array<array-key, array{0: int, 1: string, 2: int}>
   */
  protected \Closure $pool;

  /**
   * The base directory the trial workspace is assembled under.
   */
  protected string $workspaceBase;

  /**
   * Constructs a RecordRunner.
   *
   * @param string $root
   *   The repository root.
   * @param string $binary
   *   The resolved agent binary or command prefix.
   * @param float $timeout
   *   The trial wall-clock budget, in seconds.
   * @param \Closure|null $pool
   *   An override for the concurrent process runner, for tests.
   * @param \Closure|null $git
   *   An override for the workspace git runner, for tests.
   * @param string|null $workspace_base
   *   An override for the workspace base directory, for tests.
   */
  public function __construct(
    protected string $root,
    protected string $binary,
    protected float $timeout = LlmSuite::DEFAULT_TIMEOUT,
    ?\Closure $pool = NULL,
    protected ?\Closure $git = NULL,
    ?string $workspace_base = NULL,
  ) {
    $this->pool = $pool ?? (new ProcessPool(1, $timeout))->run(...);
    $this->workspaceBase = $workspace_base ?? rtrim($root, '/') . '/.artifacts/tmp/skilltest-record';
  }

  /**
   * Runs one live trial of a task on a model and returns its raw outcome.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $skill
   *   The skill being recorded.
   * @param array{name: string, prompt: string, task: array<mixed>} $entry
   *   The validated task entry to record.
   * @param string $model_id
   *   The resolved model id to record with.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\RecordResult
   *   The raw transcript, exit code, and duration of the run.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the task inputs are malformed or a workspace cannot be assembled.
   */
  public function record(LoadedSkill $skill, array $entry, string $model_id): RecordResult {
    $effective = $skill->effective;
    $inputs = TrialWorkspace::parseInputs($entry['task'], $skill->file);
    $allowed = Data::toStringList(Data::get($effective->contract, 'tools', 'allowed'));
    $command = AgentCommand::build($this->binary, $entry['prompt'], $model_id, $effective->maxTurns, $allowed);

    $workspace = new TrialWorkspace($this->workspaceBase . '/' . uniqid('ws-', TRUE), $this->root, $effective->skill, $effective->path, $inputs, $this->git);

    try {
      $workspace->assemble();
      [$exit_code, $stdout, $duration_ms] = ($this->pool)([[$command, $workspace->agentDir()]])[0];

      return new RecordResult($stdout, $exit_code, $duration_ms);
    }
    finally {
      $workspace->cleanup();
    }
  }

}
