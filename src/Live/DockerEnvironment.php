<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

use AlexSkrypnyk\SkillTest\Config\DockerConfig;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Process\ProcessRunner;

/**
 * Runs each trial in its own container: strong isolation over the fastest loop.
 *
 * Workspaces are assembled on the host exactly as the host environment
 * assembles them - the same {@see TrialWorkspace}, under the project's
 * `.skilltest/tmp/` - and then bind-mounted into a fresh container per trial,
 * so what the agent sees and writes stays a real, inspectable directory while
 * the process itself is sealed off. Only the two credential variables are
 * forwarded in; nothing else from the host environment crosses the boundary.
 * The run image (a base image plus optional build steps) is prepared once so
 * per-trial cost is a container start, not an image build. Concurrency and the
 * wall-clock timeout are the same bounded {@see ProcessPool} the host uses;
 * because a killed `docker run` client need not stop its container, a timed-out
 * trial's container is force-removed by name and a label sweep at teardown
 * guarantees no container or image is left behind. The pool and the docker/git
 * seams are injectable so the whole environment is testable without a real
 * daemon.
 */
final class DockerEnvironment implements EnvironmentInterface {

  /**
   * The scratch directory, relative to the repo root, workspaces live under.
   */
  public const string WORKSPACE_DIR = '.skilltest/tmp';

  /**
   * The directory the workspace is mounted at inside every container.
   */
  public const string CONTAINER_WORKDIR = '/work';

  /**
   * The label key stamped on every container so a run can find and remove its.
   */
  public const string RUN_LABEL = 'skilltest.run';

  /**
   * The wall-clock budget, in seconds, for image preparation and teardown.
   */
  public const float PREPARE_TIMEOUT = 600.0;

  /**
   * Runs a pool of commands and returns each one's exit, stdout, and duration.
   *
   * @var \Closure(array<array-key, array{0: string, 1: string}>): array<array-key, array{0: int, 1: string, 2: int}>
   */
  protected \Closure $pool;

  /**
   * Runs one docker admin command and returns its exit code and stdout.
   *
   * @var \Closure(string, string): array{0: int, 1: string}
   */
  protected \Closure $docker;

  /**
   * The base directory trial workspaces are assembled under.
   */
  protected string $workspaceBase;

  /**
   * The run identifier stamped on containers so teardown can sweep them.
   */
  protected string $runId;

  /**
   * The image every trial container starts from, resolved by {@see prepare}.
   */
  protected string $runImage;

  /**
   * The image built for this run, or NULL when the base image is used as-is.
   */
  protected ?string $builtImage = NULL;

  /**
   * The paths of workspaces preserved because retention was requested.
   *
   * @var string[]
   */
  protected array $keptWorkspaces = [];

  /**
   * Constructs a DockerEnvironment.
   *
   * @param string $root
   *   The repository root, that relative fixture and repo sources resolve
   *   against and every docker command runs from.
   * @param int $parallel
   *   The maximum number of concurrent trials.
   * @param float $timeout
   *   The per-trial wall-clock budget, in seconds.
   * @param \AlexSkrypnyk\SkillTest\Config\DockerConfig $config
   *   The image and per-container limits.
   * @param string $binary
   *   The docker binary or command prefix.
   * @param array<string, string> $env
   *   The host environment, read only to decide which credential variables to
   *   forward into the container by name.
   * @param \Closure|null $pool
   *   An override for the concurrent process runner, for tests.
   * @param \Closure|null $docker
   *   An override for the docker admin runner (image prep, container sweep),
   *   for tests.
   * @param \Closure|null $git
   *   An override for the workspace git runner, for tests.
   * @param string|null $workspace_base
   *   An override for the workspace base directory, for tests.
   * @param bool $keepWorkspaces
   *   When TRUE, workspaces are preserved instead of removed and their paths
   *   recorded, so a run can be inspected after the fact.
   * @param string|null $run_id
   *   An override for the run identifier, for tests.
   */
  public function __construct(
    protected string $root,
    int $parallel,
    float $timeout,
    protected DockerConfig $config,
    protected string $binary = DockerPreflight::DEFAULT_BINARY,
    protected array $env = [],
    ?\Closure $pool = NULL,
    ?\Closure $docker = NULL,
    protected ?\Closure $git = NULL,
    ?string $workspace_base = NULL,
    protected bool $keepWorkspaces = FALSE,
    ?string $run_id = NULL,
  ) {
    $this->pool = $pool ?? (new ProcessPool($parallel, $timeout))->run(...);
    $this->docker = $docker ?? (new ProcessRunner(self::PREPARE_TIMEOUT))->run(...);
    $this->workspaceBase = $workspace_base ?? rtrim($root, '/') . '/' . self::WORKSPACE_DIR;
    $this->runId = $run_id ?? str_replace('.', '', uniqid());
    $this->runImage = $config->image;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the workspace base cannot be created or the run image cannot be
   *   prepared.
   */
  public function prepare(): void {
    // Same guarded mkdir as the host: a concurrent run may create the base
    // between the check and the mkdir, so a failed mkdir is only an error when
    // the directory still does not exist.
    if (!is_dir($this->workspaceBase) && !@mkdir($this->workspaceBase, 0777, TRUE) && !is_dir($this->workspaceBase)) {
      throw new ConfigException(sprintf("could not create the workspace base directory '%s'.", $this->workspaceBase));
    }

    $this->runImage = $this->prepareImage();
  }

  /**
   * {@inheritdoc}
   */
  public function setup(string $skill, string $path, array $inputs): TrialWorkspace {
    $workspace = new TrialWorkspace($this->workspaceBase . '/' . uniqid('ws-', TRUE), $this->root, $skill, $path, $inputs, $this->git);

    // Assembly is transactional: a half-built workspace is removed here rather
    // than left for a caller that never received the handle to clean up.
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
    $names = [];

    foreach ($batch as $key => [$workspace, $command]) {
      $names[$key] = $this->containerName();
      $commands[$key] = [$this->runCommand($names[$key], $workspace->agentDir(), $command), $this->root];
    }

    $resolved = [];

    foreach (($this->pool)($commands) as $key => [$exit_code, $stdout, $duration_ms]) {
      // A wall-clock kill terminates the client, which need not stop the
      // container, so a timed-out trial's container is force-removed by name.
      if ($exit_code === ProcessPool::TIMEOUT_EXIT) {
        ($this->docker)($this->binary . ' rm -f ' . escapeshellarg($names[$key]), $this->root);
      }

      $resolved[$key] = [$exit_code, $this->diagnose($exit_code, $stdout), $duration_ms];
    }

    return $resolved;
  }

  /**
   * {@inheritdoc}
   */
  public function cleanup(TrialWorkspace $workspace): void {
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
    // Remove any container this run left behind (a timed-out client can orphan
    // one), then the image built for this run, then the scratch area - but only
    // while it is empty, so a concurrent run and any retained workspaces are
    // never disturbed.
    $this->sweepContainers();

    if ($this->builtImage !== NULL) {
      ($this->docker)($this->binary . ' rmi -f ' . escapeshellarg($this->builtImage), $this->root);
    }

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

  /**
   * A runner that executes a lifecycle hook inside a container from the image.
   *
   * A docker run's hooks default to the trial's isolation: the hook runs in a
   * fresh container from the same run image, its working directory mounted in,
   * with the same credentials forwarded. The `on-host` escape bypasses this by
   * using the host runner instead, so a hook that must manage host-side state
   * still can.
   *
   * @return \Closure(string, string): array{0: int, 1: string}
   *   A runner taking a command and working directory and returning
   *   `[exitCode, stdout]`.
   */
  public function hookRunner(): \Closure {
    return function (string $command, string $cwd): array {
      $name = $this->containerName();
      $result = ($this->docker)($this->hookCommand($name, $command, $cwd), $this->root);

      // A timed-out hook, like a timed-out trial, can outlive its killed
      // client, so force-remove its container by name; the teardown label sweep
      // is the backstop for anything this misses.
      if ($result[0] === ProcessPool::TIMEOUT_EXIT) {
        ($this->docker)($this->binary . ' rm -f ' . escapeshellarg($name), $this->root);
      }

      return $result;
    };
  }

  /**
   * Resolves the run image: the base image, or one built with the setup steps.
   *
   * @return string
   *   The image every trial container starts from.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the base image cannot be pulled or the run image cannot be built.
   */
  protected function prepareImage(): string {
    $setup = trim($this->config->setup);

    if ($setup === '') {
      $this->ensureBaseImage();

      return $this->config->image;
    }

    return $this->buildRunImage($setup);
  }

  /**
   * Ensures the base image is present locally, pulling it once when it is not.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the image is absent and cannot be pulled.
   */
  protected function ensureBaseImage(): void {
    [$inspect] = ($this->docker)($this->binary . ' image inspect ' . escapeshellarg($this->config->image), $this->root);

    if ($inspect === 0) {
      return;
    }

    [$pull] = ($this->docker)($this->binary . ' pull ' . escapeshellarg($this->config->image), $this->root);

    if ($pull !== 0) {
      throw new ConfigException(sprintf("could not pull the docker image '%s'.", $this->config->image));
    }
  }

  /**
   * Builds a run image from the base image plus the configured setup steps.
   *
   * @param string $setup
   *   The extra Dockerfile instructions to append after the base image.
   *
   * @return string
   *   The tag of the built run image.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the build fails.
   */
  protected function buildRunImage(string $setup): string {
    $tag = 'skilltest-run-' . $this->runId;
    $context = $this->workspaceBase . '/build-' . $this->runId;

    if (!is_dir($context)) {
      mkdir($context, 0777, TRUE);
    }

    file_put_contents($context . '/Dockerfile', sprintf("FROM %s\n%s\n", $this->config->image, $setup));

    [$build] = ($this->docker)($this->binary . ' build -t ' . escapeshellarg($tag) . ' ' . escapeshellarg($context), $this->root);

    unlink($context . '/Dockerfile');
    rmdir($context);

    if ($build !== 0) {
      throw new ConfigException(sprintf("could not build the docker run image from '%s'.", $this->config->image));
    }

    $this->builtImage = $tag;

    return $tag;
  }

  /**
   * Force-removes every container this run stamped with its label.
   */
  protected function sweepContainers(): void {
    [, $stdout] = ($this->docker)($this->binary . ' ps -aq --filter ' . escapeshellarg('label=' . self::RUN_LABEL . '=' . $this->runId), $this->root);

    $ids = array_values(array_filter(array_map(trim(...), preg_split('/\R/', trim($stdout)) ?: [])));

    if ($ids === []) {
      return;
    }

    ($this->docker)($this->binary . ' rm -f ' . implode(' ', array_map(escapeshellarg(...), $ids)), $this->root);
  }

  /**
   * Builds the `docker run` command that runs one trial command in a container.
   *
   * @param string $name
   *   The container name, so a timed-out container can be removed by it.
   * @param string $agent_dir
   *   The host workspace directory bind-mounted as the container's workdir.
   * @param string $command
   *   The trial command to run inside the container.
   *
   * @return string
   *   The full docker run command.
   */
  protected function runCommand(string $name, string $agent_dir, string $command): string {
    $parts = [
      $this->binary,
      'run',
      '--rm',
      '--name',
      escapeshellarg($name),
      '--label',
      escapeshellarg(self::RUN_LABEL . '=' . $this->runId),
    ];

    foreach ($this->limitFlags() as $flag) {
      $parts[] = $flag;
    }

    foreach ($this->credentialFlags() as $flag) {
      $parts[] = $flag;
    }

    $parts[] = '-v';
    $parts[] = escapeshellarg($agent_dir . ':' . self::CONTAINER_WORKDIR);
    $parts[] = '-w';
    $parts[] = self::CONTAINER_WORKDIR;
    $parts[] = escapeshellarg($this->runImage);
    $parts[] = 'sh';
    $parts[] = '-c';
    $parts[] = escapeshellarg($command);

    return implode(' ', $parts);
  }

  /**
   * Builds the `docker run` command that runs a lifecycle hook in a container.
   *
   * The hook container carries the same name and run label as a trial's, so a
   * timed-out hook is removable by name and the teardown sweep never leaves one
   * behind to block the run image's removal.
   *
   * @param string $name
   *   The container name, so a timed-out hook container can be removed by it.
   * @param string $command
   *   The hook command to run inside the container.
   * @param string $cwd
   *   The host working directory bind-mounted as the container's workdir.
   *
   * @return string
   *   The full docker run command.
   */
  protected function hookCommand(string $name, string $command, string $cwd): string {
    $parts = [
      $this->binary,
      'run',
      '--rm',
      '--name',
      escapeshellarg($name),
      '--label',
      escapeshellarg(self::RUN_LABEL . '=' . $this->runId),
    ];

    foreach ($this->credentialFlags() as $flag) {
      $parts[] = $flag;
    }

    $parts[] = '-v';
    $parts[] = escapeshellarg($cwd . ':' . self::CONTAINER_WORKDIR);
    $parts[] = '-w';
    $parts[] = self::CONTAINER_WORKDIR;
    $parts[] = escapeshellarg($this->runImage);
    $parts[] = 'sh';
    $parts[] = '-c';
    $parts[] = escapeshellarg($command);

    return implode(' ', $parts);
  }

  /**
   * The `--cpus`/`--memory` flags for the configured limits, when any are set.
   *
   * @return string[]
   *   The limit flags, empty when neither limit is configured.
   */
  protected function limitFlags(): array {
    $flags = [];

    if ($this->config->cpus !== NULL) {
      $flags[] = '--cpus=' . $this->config->cpus;
    }

    if ($this->config->memoryMb !== NULL) {
      $flags[] = '--memory=' . $this->config->memoryMb . 'm';
    }

    return $flags;
  }

  /**
   * The `-e` flags forwarding each set credential variable into a container.
   *
   * The value is never placed on the command line - `-e NAME` forwards it by
   * name from the inherited environment - so a secret cannot leak into a
   * captured command or process listing.
   *
   * @return string[]
   *   The credential flags, in pairs, empty when none are set.
   */
  protected function credentialFlags(): array {
    $flags = [];

    foreach (DockerPreflight::CREDENTIAL_VARS as $name) {
      if (trim((string) ($this->env[$name] ?? '')) !== '') {
        $flags[] = '-e';
        $flags[] = $name;
      }
    }

    return $flags;
  }

  /**
   * A container name unique to this run and trial.
   *
   * @return string
   *   The container name.
   */
  protected function containerName(): string {
    return 'skilltest-' . $this->runId . '-' . str_replace('.', '', uniqid('', TRUE));
  }

  /**
   * Resolves a failed trial's stdout into a diagnostic when it produced none.
   *
   * A container's own output is its best diagnostic and is kept as-is; only if
   * a failing trial produced nothing - a timed-out kill, or a container that
   * died before printing - is a synthetic message substituted so the persisted
   * transcript still explains the failure.
   *
   * @param int $exit_code
   *   The trial's exit code.
   * @param string $stdout
   *   The captured stdout.
   *
   * @return string
   *   The stdout, or a synthetic diagnostic when a failed trial produced none.
   */
  protected function diagnose(int $exit_code, string $stdout): string {
    if ($exit_code === 0 || $stdout !== '') {
      return $stdout;
    }

    return $exit_code === ProcessPool::TIMEOUT_EXIT
      ? 'docker: the trial timed out and its container was killed.'
      : sprintf('docker: the container exited with code %d and produced no output.', $exit_code);
  }

}
