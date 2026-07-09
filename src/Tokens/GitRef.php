<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tokens;

use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Process\ProcessRunner;

/**
 * The git ref token counts are compared against, and file content at it.
 *
 * A token comparison needs two things from git: a ref that actually resolves
 * (the requested one, or `origin/main` falling back to `main`) and each file's
 * content as of that ref. A ref that cannot be resolved is a hard
 * configuration error rather than an empty comparison - a gate that silently
 * compares against nothing would always pass. The process call is injected so
 * resolution and content reads are unit-testable without a real repository.
 */
final class GitRef {

  /**
   * The wall-clock budget, in seconds, for one git call.
   */
  public const float DEFAULT_TIMEOUT = 30.0;

  /**
   * The ref compared against when none is requested.
   */
  public const string DEFAULT_REF = 'origin/main';

  /**
   * The ref compared against when the default does not resolve.
   */
  public const string FALLBACK_REF = 'main';

  /**
   * The repository root git runs in.
   */
  protected string $root;

  /**
   * Runs a git command and returns its exit code and captured stdout.
   *
   * @var \Closure(string, string): array{0: int, 1: string}
   */
  protected \Closure $runner;

  /**
   * Constructs a GitRef.
   *
   * @param string $root
   *   The repository root; git runs with this as its working directory.
   * @param \Closure|null $runner
   *   A runner taking the assembled command and working directory and
   *   returning `[exitCode, stdout]`. Defaults to a real run via
   *   {@see ProcessRunner}.
   * @param float $timeout
   *   The wall-clock budget, in seconds, for one git call.
   */
  public function __construct(string $root, ?\Closure $runner = NULL, protected float $timeout = self::DEFAULT_TIMEOUT) {
    $this->root = rtrim($root, '/');
    $this->runner = $runner ?? fn(string $command, string $cwd): array => (new ProcessRunner($this->timeout))->run($command, $cwd);
  }

  /**
   * Resolves the ref to compare against.
   *
   * @param string|null $requested
   *   The explicitly requested ref, or NULL to use the default chain.
   *
   * @return string
   *   The resolved ref.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the requested ref, or neither default ref, resolves to a commit.
   */
  public function resolve(?string $requested): string {
    if ($requested !== NULL && $requested !== '') {
      if (!$this->exists($requested)) {
        throw new ConfigException(sprintf("git ref '%s' does not resolve to a commit.", $requested), $this->root);
      }

      return $requested;
    }

    if ($this->exists(self::DEFAULT_REF)) {
      return self::DEFAULT_REF;
    }

    if ($this->exists(self::FALLBACK_REF)) {
      return self::FALLBACK_REF;
    }

    throw new ConfigException(sprintf("neither '%s' nor '%s' resolves to a commit (is this a git repository?); pass an explicit ref.", self::DEFAULT_REF, self::FALLBACK_REF), $this->root);
  }

  /**
   * Whether a ref resolves to a commit.
   *
   * @param string $ref
   *   The ref to verify.
   *
   * @return bool
   *   TRUE when the ref resolves.
   */
  public function exists(string $ref): bool {
    [$exit_code] = ($this->runner)('git rev-parse --verify --quiet ' . escapeshellarg($ref . '^{commit}'), $this->root);

    return $exit_code === 0;
  }

  /**
   * Reads a file's content as of a ref.
   *
   * @param string $ref
   *   The resolved ref.
   * @param string $path
   *   The file path relative to the repository root.
   *
   * @return string|null
   *   The content, or NULL when the file does not exist at the ref.
   */
  public function contentAt(string $ref, string $path): ?string {
    [$exit_code, $stdout] = ($this->runner)('git show ' . escapeshellarg($ref . ':' . $path), $this->root);

    return $exit_code === 0 ? $stdout : NULL;
  }

}
