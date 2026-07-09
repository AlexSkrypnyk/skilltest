<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Process\ProcessRunner;

/**
 * Assembles and tears down the fresh working directory one live trial runs in.
 *
 * A trial must run against a real, isolated checkout, not the developer's tree,
 * so each gets its own workspace built the same way every time: the task's
 * fixture is copied in, its declared repos are materialised as detached
 * `git worktree` checkouts (cheap, offline, sharing the source object store),
 * the skill under test is installed into the workspace discovery path so the
 * agent can find it, and the agent's start directory is resolved from the
 * task's `workdir`. Everything is removed afterwards - worktrees through
 * git's own bookkeeping, then the directory tree - so a run leaves no trace
 * and repeated trials never collide. The git seam is injectable so assembly is
 * testable without a real repository.
 */
final class TrialWorkspace {

  /**
   * The discovery path skills are installed under inside the workspace.
   */
  public const string SKILLS_PATH = '.claude/skills';

  /**
   * The default git reference checked out when a repo entry omits `commit`.
   */
  public const string DEFAULT_COMMIT = 'HEAD';

  /**
   * Runs a command and returns its exit code and captured stdout.
   *
   * @var \Closure(string, string): array{0: int, 1: string}
   */
  protected \Closure $git;

  /**
   * The worktrees created so far, as `[source, dest]` pairs, for cleanup.
   *
   * @var array<int, array{0: string, 1: string}>
   */
  protected array $worktrees = [];

  /**
   * Constructs a TrialWorkspace.
   *
   * @param string $path
   *   The workspace directory to assemble into; created if absent.
   * @param string $root
   *   The repository root, that relative fixture and repo sources resolve
   *   against.
   * @param string $skillName
   *   The skill name, naming its directory under the discovery path.
   * @param string $skillPath
   *   The skill directory, relative to the repository root.
   * @param array{fixture: ?string, repos: array<int, array{source: string, commit: string, dest: string}>, workdir: ?string} $inputs
   *   The parsed, validated task inputs.
   * @param \Closure|null $git
   *   A runner taking a command and working directory and returning
   *   `[exitCode, stdout]`. Defaults to a real process run via ProcessRunner.
   */
  public function __construct(
    protected string $path,
    protected string $root,
    protected string $skillName,
    protected string $skillPath,
    protected array $inputs,
    ?\Closure $git = NULL,
  ) {
    $this->git = $git ?? (new ProcessRunner())->run(...);
  }

  /**
   * Parses and validates a task's inputs into a normalised structure.
   *
   * @param array<mixed> $task
   *   The raw task declaration.
   * @param string $config_file
   *   The declaring `eval.yaml`, for error context.
   *
   * @return array{fixture: ?string, repos: array<int, array{source: string, commit: string, dest: string}>, workdir: ?string}
   *   The normalised inputs.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When a repo entry omits its source or dest, or a dest or workdir is not a
   *   safe relative path.
   */
  public static function parseInputs(array $task, string $config_file): array {
    $inputs = Data::toArray(Data::get($task, 'inputs'));
    $repos = [];

    foreach (Data::toArrayList(Data::get($inputs, 'repos')) as $entry) {
      $source = Data::toStringOrNull(Data::get($entry, 'source'));
      if ($source === NULL || $source === '') {
        throw new ConfigException("a repos entry requires a 'source'.", $config_file, 'llm.tasks.inputs.repos');
      }

      $dest = Data::toStringOrNull(Data::get($entry, 'dest'));
      if ($dest === NULL || $dest === '') {
        throw new ConfigException("a repos entry requires a 'dest'.", $config_file, 'llm.tasks.inputs.repos');
      }

      self::assertSafeRelative($dest, "a repos 'dest'", $config_file);

      $repos[] = [
        'source' => $source,
        'commit' => Data::toStringOrNull(Data::get($entry, 'commit')) ?? self::DEFAULT_COMMIT,
        'dest' => $dest,
      ];
    }

    $workdir = Data::toStringOrNull(Data::get($inputs, 'workdir'));
    if ($workdir !== NULL && $workdir !== '') {
      self::assertSafeRelative($workdir, "the 'workdir'", $config_file);
    }

    return [
      'fixture' => Data::toStringOrNull(Data::get($task, 'fixture')),
      'repos' => $repos,
      'workdir' => $workdir === '' ? NULL : $workdir,
    ];
  }

  /**
   * The workspace directory.
   *
   * @return string
   *   The workspace path.
   */
  public function path(): string {
    return $this->path;
  }

  /**
   * The directory the agent starts in: the workspace, or its `workdir` subdir.
   *
   * @return string
   *   The agent working directory.
   */
  public function agentDir(): string {
    $workdir = $this->inputs['workdir'];

    return $workdir === NULL ? $this->path : $this->path . '/' . $workdir;
  }

  /**
   * Assembles the workspace: fixture, repos, skill, and agent directory.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When a declared fixture is missing or a repo worktree cannot be created.
   */
  public function assemble(): void {
    $this->makeDir($this->path);
    $this->copyFixture();
    $this->materialiseRepos();
    $this->installSkill();
    $this->makeDir($this->agentDir());
  }

  /**
   * Removes the workspace and every worktree it created.
   */
  public function cleanup(): void {
    foreach ($this->worktrees as [$source, $dest]) {
      ($this->git)('git worktree remove --force ' . escapeshellarg($dest), $source);
    }

    self::removeTree($this->path);

    foreach ($this->worktrees as [$source]) {
      ($this->git)('git worktree prune', $source);
    }
  }

  /**
   * Copies the task fixture into the workspace, when one is declared.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the declared fixture path does not exist.
   */
  protected function copyFixture(): void {
    $fixture = $this->inputs['fixture'];

    if ($fixture === NULL || $fixture === '') {
      return;
    }

    $source = str_starts_with($fixture, '/') ? $fixture : $this->root . '/' . $this->skillPath . '/' . $fixture;

    if (is_dir($source)) {
      self::copyTree($source, $this->path);

      return;
    }

    if (is_file($source)) {
      copy($source, $this->path . '/' . basename($source));

      return;
    }

    throw new ConfigException(sprintf("fixture '%s' was not found.", $fixture));
  }

  /**
   * Materialises each declared repo as a detached worktree in the workspace.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When a worktree cannot be created from its source at its commit.
   */
  protected function materialiseRepos(): void {
    foreach ($this->inputs['repos'] as $repo) {
      $source = str_starts_with($repo['source'], '/') ? $repo['source'] : $this->root . '/' . $repo['source'];
      $dest = $this->path . '/' . $repo['dest'];
      $this->makeDir(dirname($dest));

      $command = sprintf('git worktree add --detach %s %s', escapeshellarg($dest), escapeshellarg($repo['commit']));
      [$exit_code] = ($this->git)($command, $source);

      if ($exit_code !== 0) {
        throw new ConfigException(sprintf("could not create a worktree for '%s' at '%s'.", $repo['source'], $repo['commit']));
      }

      // Tracked as each is created so a later failure still cleans up the ones
      // already materialised.
      $this->worktrees[] = [$source, $dest];
    }
  }

  /**
   * Installs the skill under test into the workspace discovery path.
   */
  protected function installSkill(): void {
    self::copyTree($this->root . '/' . $this->skillPath, $this->path . '/' . self::SKILLS_PATH . '/' . $this->skillName);
  }

  /**
   * Rejects a path that is absolute or escapes the workspace with `..`.
   *
   * @param string $value
   *   The path to check.
   * @param string $label
   *   The human label naming the field, for the error message.
   * @param string $config_file
   *   The declaring `eval.yaml`, for error context.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the path is absolute or contains a `..` segment.
   */
  protected static function assertSafeRelative(string $value, string $label, string $config_file): void {
    $segments = preg_split('#[/\\\\]#', $value) ?: [];

    if (str_starts_with($value, '/') || in_array('..', $segments, TRUE)) {
      throw new ConfigException(sprintf('%s must be a relative path without a ".." segment.', $label), $config_file, 'llm.tasks.inputs');
    }
  }

  /**
   * Creates a directory and its parents when it does not already exist.
   *
   * @param string $dir
   *   The directory to create.
   */
  protected function makeDir(string $dir): void {
    if (!is_dir($dir)) {
      mkdir($dir, 0777, TRUE);
    }
  }

  /**
   * Recursively copies a directory tree into a destination directory.
   *
   * @param string $source
   *   The source directory.
   * @param string $dest
   *   The destination directory, created if absent.
   */
  protected static function copyTree(string $source, string $dest): void {
    if (!is_dir($dest)) {
      mkdir($dest, 0777, TRUE);
    }

    foreach (scandir($source) ?: [] as $item) {
      if ($item === '.') {
        continue;
      }
      if ($item === '..') {
        continue;
      }
      $from = $source . '/' . $item;
      $to = $dest . '/' . $item;

      if (is_dir($from)) {
        self::copyTree($from, $to);

        continue;
      }

      copy($from, $to);
    }
  }

  /**
   * Recursively removes a directory tree.
   *
   * @param string $dir
   *   The directory to remove.
   */
  protected static function removeTree(string $dir): void {
    if (!is_dir($dir)) {
      return;
    }

    foreach (scandir($dir) ?: [] as $item) {
      if ($item === '.') {
        continue;
      }
      if ($item === '..') {
        continue;
      }
      $path = $dir . '/' . $item;

      if (is_dir($path) && !is_link($path)) {
        self::removeTree($path);

        continue;
      }

      unlink($path);
    }

    rmdir($dir);
  }

}
