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
 * one run through the identical machinery: the same {@see Environment} that
 * assembles a fresh workspace the skill is installed into, runs the agent in
 * it, and tears it down, and the same {@see AgentCommand} invocation shape. The
 * workspace is torn down whether the run passed, failed, or threw. Grading is
 * deliberately not done here: the command grades the fixture it writes, so the
 * verdict is asserted against the file that ships, and custom checks run once
 * rather than twice. The environment seam is injectable so the orchestration is
 * testable without a real agent.
 */
final readonly class RecordRunner {

  /**
   * Constructs a RecordRunner.
   *
   * @param string $root
   *   The repository root.
   * @param string $binary
   *   The resolved agent binary or command prefix.
   * @param \AlexSkrypnyk\SkillTest\Live\Environment $environment
   *   The environment the trial is assembled, run, and torn down in.
   */
  public function __construct(
    protected string $root,
    protected string $binary,
    protected Environment $environment,
  ) {}

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

    $this->environment->prepare();

    try {
      $workspace = $this->environment->setup($effective->skill, $effective->path, $inputs);

      try {
        [$exit_code, $stdout, $duration_ms] = $this->environment->exec([[$workspace, $command]])[0];

        return new RecordResult($stdout, $exit_code, $duration_ms);
      }
      finally {
        $this->environment->cleanup($workspace);
      }
    }
    finally {
      $this->environment->teardown();
    }
  }

}
