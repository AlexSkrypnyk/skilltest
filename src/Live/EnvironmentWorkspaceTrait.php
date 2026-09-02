<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

/**
 * The workspace plumbing shared by the execution environments.
 */
trait EnvironmentWorkspaceTrait {

  /**
   * The scratch directory, relative to the repo root, workspaces live under.
   */
  public const string WORKSPACE_DIR = '.skilltest/tmp';

  /**
   * Runs a pool of commands and returns each one's exit, stdout, and duration.
   *
   * @var \Closure(array<array-key, array{0: string, 1: string}>): array<array-key, array{0: int, 1: string, 2: int}>
   */
  protected \Closure $pool;

  /**
   * The base directory trial workspaces are assembled under.
   */
  protected string $workspaceBase;

  /**
   * The paths of workspaces preserved because retention was requested.
   *
   * @var string[]
   */
  protected array $keptWorkspaces = [];

  /**
   * {@inheritdoc}
   */
  public function setup(string $skill, string $path, array $inputs): TrialWorkspace {
    $workspace = new TrialWorkspace($this->workspaceBase . '/' . uniqid('ws-', TRUE), $this->root, $skill, $path, $inputs, $this->git);

    // Assembly is transactional: a half-built workspace (a missing fixture, a
    // failed worktree) is removed here, because the caller never received the
    // handle and cannot clean it up.
    try {
      $workspace->assemble();
    }
    catch (\Throwable $throwable) {
      $workspace->cleanup();

      throw $throwable;
    }

    return $workspace;
  }

  /**
   * {@inheritdoc}
   */
  public function cleanup(TrialWorkspace $workspace): void {
    // Retention keeps the whole workspace tree - its worktrees included - so a
    // failed trial can be inspected exactly as the agent left it.
    if ($this->keepWorkspaces) {
      $this->keptWorkspaces[] = $workspace->path();

      return;
    }

    $workspace->cleanup();
  }

  /**
   * {@inheritdoc}
   */
  public function keptWorkspaces(): array {
    return $this->keptWorkspaces;
  }

}
