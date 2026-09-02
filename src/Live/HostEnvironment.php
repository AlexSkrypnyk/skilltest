<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

use AlexSkrypnyk\File\File;
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

  use EnvironmentWorkspaceTrait;

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
    // An unwritable scratch area is an explicit failure here rather than a
    // confusing one later in setup().
    try {
      File::mkdir($this->workspaceBase);
    }
    catch (\Throwable) {
      throw new ConfigException(sprintf("could not create the workspace base directory '%s'.", $this->workspaceBase));
    }
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
  public function teardown(): void {
    // Remove the run's scratch area, but only once it is empty, so a concurrent
    // run's workspaces under the same base - and any preserved by retention -
    // are never disturbed.
    if (is_dir($this->workspaceBase) && File::dirIsEmpty($this->workspaceBase)) {
      File::rmdir($this->workspaceBase);
    }
  }

}
