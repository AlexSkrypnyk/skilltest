<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

/**
 * The seam a live run executes trials through.
 *
 * An environment decides where a trial runs and what it can touch - never what
 * passing means. The contract, checks, judge, and report are identical whatever
 * implements this; only the workspace location and command execution differ. A
 * run brackets its trials with one {@see prepare} and one {@see teardown};
 * every trial is a {@see setup} that builds its workspace, an {@see exec} that
 * runs its command in that workspace's context, and a {@see cleanup} that
 * removes it. `exec` takes a whole batch so a run can drive many trials at once
 * (the host through a bounded process pool, a container environment through
 * parallel containers) without the caller knowing how the concurrency is
 * achieved.
 */
interface EnvironmentInterface {

  /**
   * Prepares run-level state shared by every trial, once before any trial.
   */
  public function prepare(): void;

  /**
   * Assembles the fresh workspace one trial runs in.
   *
   * @param string $skill
   *   The skill name, naming its directory under the workspace discovery path.
   * @param string $path
   *   The skill directory, relative to the repository root.
   * @param array{fixture: ?string, repos: array<int, array{source: string, commit: string, dest: string}>, workdir: ?string} $inputs
   *   The parsed, validated task inputs.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\TrialWorkspace
   *   The assembled workspace, ready for its command to run.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When a declared fixture is missing or a repo worktree cannot be created.
   */
  public function setup(string $skill, string $path, array $inputs): TrialWorkspace;

  /**
   * Runs a batch of trial commands, each in its own workspace's context.
   *
   * @param array<array-key, array{0: \AlexSkrypnyk\SkillTest\Live\TrialWorkspace, 1: string}> $batch
   *   The trials to run, keyed; each is a `[workspace, command]` pair.
   *
   * @return array<array-key, array{0: int, 1: string, 2: int}>
   *   Each trial's `[exit code, captured stdout, duration in ms]`, in the same
   *   keys as the batch.
   */
  public function exec(array $batch): array;

  /**
   * Removes one trial's workspace and any state its assembly created.
   *
   * When workspace retention is on the workspace is preserved instead of
   * removed and its path recorded for {@see keptWorkspaces}, so a run can be
   * inspected after the fact.
   *
   * @param \AlexSkrypnyk\SkillTest\Live\TrialWorkspace $workspace
   *   The workspace to tear down.
   */
  public function cleanup(TrialWorkspace $workspace): void;

  /**
   * Tears down the run-level state, once after all trials.
   */
  public function teardown(): void;

  /**
   * The paths of the workspaces preserved because retention was requested.
   *
   * @return string[]
   *   The kept workspace paths, in cleanup order; empty when retention is off.
   */
  public function keptWorkspaces(): array;

}
