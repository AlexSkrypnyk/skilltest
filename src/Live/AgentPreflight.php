<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

/**
 * Decides whether a live run can even start: an agent binary and credentials.
 *
 * The llm suite spends tokens through a real `claude`, so before a single trial
 * runs it must find the binary and see plausible credentials; otherwise the run
 * is a guaranteed, expensive failure and the tool exits `2` with a message that
 * names the missing half. The binary is either the explicit `SKILLTEST_AGENT`
 * override (which also lets a test point at a stub, or a user wrap the CLI) or
 * the first executable `claude` on `PATH`. Credentials are detected
 * heuristically - an API key, an OAuth token, or an authenticated Claude Code
 * home - because the tool never handles the secret itself, it only forwards the
 * host environment to the child.
 */
final readonly class AgentPreflight {

  use BinaryOnPathTrait;

  /**
   * The environment variable naming the agent binary or command prefix.
   */
  public const string ENV_AGENT = 'SKILLTEST_AGENT';

  /**
   * The default agent binary searched for on PATH.
   */
  public const string DEFAULT_BINARY = 'claude';

  /**
   * The problem reported when no agent binary can be found.
   */
  public const string PROBLEM_NO_BINARY = "the 'claude' agent was not found on PATH; install Claude Code or set SKILLTEST_AGENT to its path.";

  /**
   * The problem reported when no credentials are visible.
   */
  public const string PROBLEM_NO_CREDENTIALS = 'no agent credentials found; set ANTHROPIC_API_KEY or CLAUDE_CODE_OAUTH_TOKEN, or authenticate Claude Code (~/.claude).';

  /**
   * The credential-bearing environment variables that satisfy the heuristic.
   */
  protected const array CREDENTIAL_VARS = ['ANTHROPIC_API_KEY', 'CLAUDE_CODE_OAUTH_TOKEN'];

  /**
   * Constructs an AgentPreflight.
   *
   * @param array<string, string> $env
   *   The environment map, as returned by `getenv()`.
   */
  public function __construct(
    protected array $env,
  ) {}

  /**
   * The first blocking problem, or NULL when the run can start.
   *
   * @return string|null
   *   The problem message, or NULL when both the binary and credentials are
   *   present.
   */
  public function problem(): ?string {
    if ($this->binary() === NULL) {
      return self::PROBLEM_NO_BINARY;
    }

    if (!$this->hasCredentials()) {
      return self::PROBLEM_NO_CREDENTIALS;
    }

    return NULL;
  }

  /**
   * Resolves the agent binary or command prefix.
   *
   * @return string|null
   *   The `SKILLTEST_AGENT` override, the discovered `claude` path, or NULL
   *   when neither is available.
   */
  public function binary(): ?string {
    $override = trim((string) ($this->env[self::ENV_AGENT] ?? ''));

    if ($override !== '') {
      return $override;
    }

    return self::onPath((string) ($this->env['PATH'] ?? ''), self::DEFAULT_BINARY);
  }

  /**
   * Whether the environment carries plausible agent credentials.
   *
   * @return bool
   *   TRUE when an API key, an OAuth token, or an authenticated Claude Code
   *   home is present.
   */
  public function hasCredentials(): bool {
    foreach (self::CREDENTIAL_VARS as $name) {
      if (trim((string) ($this->env[$name] ?? '')) !== '') {
        return TRUE;
      }
    }

    $home = trim((string) ($this->env['HOME'] ?? ''));

    return $home !== '' && is_dir($home . '/.claude');
  }

}
