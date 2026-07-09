<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

use AlexSkrypnyk\SkillTest\Process\ProcessRunner;

/**
 * Decides whether a docker run can start: the CLI, a reachable daemon, creds.
 *
 * Unlike the host environment the agent binary ships inside the image, so the
 * host needs only the `docker` CLI (the `SKILLTEST_DOCKER` override or the
 * first `docker` on PATH) and a daemon that answers. Credentials still matter
 * because they are the one thing forwarded into the otherwise-sealed
 * container, and only an explicit env var crosses - a host `~/.claude` login
 * does not - so without an API key or OAuth token the run is a guaranteed,
 * expensive failure and the tool exits 2 before any container starts, with a
 * message naming the missing half.
 */
final readonly class DockerPreflight {

  use BinaryOnPathTrait;

  /**
   * The environment variable naming the docker binary or command prefix.
   */
  public const string ENV_DOCKER = 'SKILLTEST_DOCKER';

  /**
   * The default docker binary searched for on PATH.
   */
  public const string DEFAULT_BINARY = 'docker';

  /**
   * The problem reported when no docker binary can be found.
   */
  public const string PROBLEM_NO_BINARY = "the 'docker' binary was not found on PATH; install Docker or set SKILLTEST_DOCKER to its path to use --env docker.";

  /**
   * The problem reported when the docker daemon does not answer.
   */
  public const string PROBLEM_DAEMON = 'the Docker daemon is not reachable; start Docker to use --env docker.';

  /**
   * The problem reported when no credentials are available to pass in.
   */
  public const string PROBLEM_NO_CREDENTIALS = 'no agent credentials to pass into the container; set ANTHROPIC_API_KEY or CLAUDE_CODE_OAUTH_TOKEN.';

  /**
   * The credential-bearing environment variables forwarded into a container.
   */
  public const array CREDENTIAL_VARS = ['ANTHROPIC_API_KEY', 'CLAUDE_CODE_OAUTH_TOKEN'];

  /**
   * Runs a command in a working directory, returning its exit and stdout.
   *
   * @var \Closure(string, string): array{0: int, 1: string}
   */
  protected \Closure $runner;

  /**
   * Constructs a DockerPreflight.
   *
   * @param array<string, string> $env
   *   The environment map, as returned by `getenv()`.
   * @param string $cwd
   *   A working directory the daemon probe runs in.
   * @param \Closure|null $runner
   *   An override for the daemon-probe runner, for tests. Defaults to a real
   *   process run via ProcessRunner.
   */
  public function __construct(
    protected array $env,
    protected string $cwd,
    ?\Closure $runner = NULL,
  ) {
    $this->runner = $runner ?? (new ProcessRunner())->run(...);
  }

  /**
   * The first blocking problem, or NULL when the run can start.
   *
   * @return string|null
   *   The problem message, or NULL when the CLI, daemon, and credentials are
   *   all present.
   */
  public function problem(): ?string {
    if ($this->binary() === NULL) {
      return self::PROBLEM_NO_BINARY;
    }

    if (!$this->daemonReachable()) {
      return self::PROBLEM_DAEMON;
    }

    if (!$this->hasCredentials()) {
      return self::PROBLEM_NO_CREDENTIALS;
    }

    return NULL;
  }

  /**
   * Resolves the docker binary or command prefix.
   *
   * @return string|null
   *   The `SKILLTEST_DOCKER` override, the discovered `docker` path, or NULL
   *   when neither is available.
   */
  public function binary(): ?string {
    $override = trim((string) ($this->env[self::ENV_DOCKER] ?? ''));

    if ($override !== '') {
      return $override;
    }

    return self::onPath((string) ($this->env['PATH'] ?? ''), self::DEFAULT_BINARY);
  }

  /**
   * Whether at least one credential the container needs is set.
   *
   * @return bool
   *   TRUE when an API key or OAuth token is present in the environment.
   */
  public function hasCredentials(): bool {
    foreach (self::CREDENTIAL_VARS as $name) {
      if (trim((string) ($this->env[$name] ?? '')) !== '') {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Whether the docker daemon answers a trivial version probe.
   *
   * @return bool
   *   TRUE when `docker version` exits cleanly, so the daemon is up.
   */
  protected function daemonReachable(): bool {
    [$exit_code] = ($this->runner)($this->binary() . ' version', $this->cwd);

    return $exit_code === 0;
  }

}
