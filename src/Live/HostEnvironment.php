<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

use AlexSkrypnyk\SkillTest\Exception\ConfigException;

/**
 * Runs trials on the machine itself: the fastest loop, the weakest isolation.
 *
 * Workspaces are assembled under the consumer project's `.skilltest/tmp/` - the
 * tool's own scratch area, never the system temp - and each trial's command
 * runs there through the host's own agent binary and credentials. Concurrency
 * is a bounded {@see ProcessPool}, so `--parallel` shortens wall-clock without
 * changing any verdict. The pool and the workspace git seam are injectable so
 * the whole environment is testable without a real agent or repository. The
 * agent runs with the host user's permissions, constrained by the contract and
 * the turn cap rather than an OS boundary - honest for development and for CI
 * runners that are already ephemeral sandboxes.
 */
final class HostEnvironment implements EnvironmentInterface {

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
   * Constructs a HostEnvironment.
   *
   * @param string $root
   *   The repository root, that relative fixture and repo sources resolve
   *   against.
   * @param int $parallel
   *   The maximum number of concurrent trials.
   * @param float $timeout
   *   The per-trial wall-clock budget, in seconds.
   * @param \Closure|null $pool
   *   An override for the concurrent process runner, for tests.
   * @param \Closure|null $git
   *   An override for the workspace git runner, for tests.
   * @param string|null $workspace_base
   *   An override for the workspace base directory, for tests.
   * @param bool $keepWorkspaces
   *   When TRUE, workspaces are preserved instead of removed and their paths
   *   recorded, so a run can be inspected after the fact.
   */
  public function __construct(
    protected string $root,
    int $parallel,
    float $timeout,
    ?\Closure $pool = NULL,
    protected ?\Closure $git = NULL,
    ?string $workspace_base = NULL,
    protected bool $keepWorkspaces = FALSE,
  ) {
    $this->pool = $pool ?? (new ProcessPool($parallel, $timeout))->run(...);
    $this->workspaceBase = $workspace_base ?? rtrim($root, '/') . '/' . self::WORKSPACE_DIR;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the workspace base directory cannot be created.
   */
  public function prepare(): void {
    // A concurrent run may create the base between the check and the mkdir, so
    // a failed mkdir is only an error when the directory still does not exist;
    // that leaves an unwritable scratch area an explicit failure here rather
    // than a confusing one later in setup().
    if (!is_dir($this->workspaceBase) && !@mkdir($this->workspaceBase, 0777, TRUE) && !is_dir($this->workspaceBase)) {
      throw new ConfigException(sprintf("could not create the workspace base directory '%s'.", $this->workspaceBase));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setup(string $skill, string $path, array $inputs): TrialWorkspace {
    $workspace = new TrialWorkspace($this->workspaceBase . '/' . uniqid('ws-', TRUE), $this->root, $skill, $path, $inputs, $this->git);

    // Assembly is transactional: a half-built workspace (a missing fixture, a
    // failed worktree) is removed here rather than left for a caller that never
    // received the handle to clean up.
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
  public function exec(array $batch): array {
    $commands = [];

    foreach ($batch as $key => [$workspace, $command]) {
      $commands[$key] = [$command, $workspace->agentDir()];
    }

    return ($this->pool)($commands);
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
  public function teardown(): void {
    // Remove the run's scratch area, but only once it is empty, so a concurrent
    // run's workspaces under the same base - and any preserved by retention -
    // are never disturbed.
    if (is_dir($this->workspaceBase) && (scandir($this->workspaceBase) ?: []) === ['.', '..']) {
      rmdir($this->workspaceBase);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function keptWorkspaces(): array {
    return $this->keptWorkspaces;
  }

}
