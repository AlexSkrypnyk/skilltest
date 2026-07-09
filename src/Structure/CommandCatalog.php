<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Structure;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Process\ProcessRunner;

/**
 * The real command list of the repo's configured `commands.resolve` binary.
 *
 * When a repo points `commands.resolve` at its CLI, the structure group can
 * hold skill documentation honest: every `<binary> <subcommand>` a skill names
 * must be a command the binary actually has. This runs the binary's list
 * command once, parses whatever shape it prints (a JSON array of names, a JSON
 * array of `{name}` objects, Symfony's `{commands:[...]}`, or a text list),
 * and exposes the first token of every known command name. A binary that cannot
 * run or whose output cannot be parsed is a hard error, never a silent skip -
 * the check would otherwise pass by doing nothing. The process call is injected
 * so the parsing is unit-testable without a real binary.
 */
final class CommandCatalog {

  /**
   * The wall-clock budget, in seconds, for the list command.
   */
  public const float DEFAULT_TIMEOUT = 30.0;

  /**
   * The dotted pointer reported on a resolution error.
   */
  public const string POINTER = 'commands.resolve';

  /**
   * The repository root the binary runs in.
   */
  protected string $root;

  /**
   * Runs the list command and returns its exit code and captured stdout.
   *
   * @var \Closure(string, string): array{0: int, 1: string}
   */
  protected \Closure $runner;

  /**
   * The memoised set of known command first-tokens, NULL until resolved.
   *
   * @var string[]|null
   */
  protected ?array $tokens = NULL;

  /**
   * Constructs a CommandCatalog.
   *
   * @param string $root
   *   The repository root; the binary runs with this as its working directory.
   * @param string $binary
   *   The binary as configured, e.g. `bin/harness` (relative to the root).
   * @param string[] $listArgs
   *   The arguments that make the binary print its command list.
   * @param \Closure|null $runner
   *   A runner taking the assembled command and working directory and returning
   *   `[exitCode, stdout]`. Defaults to a real run via {@see ProcessRunner}.
   * @param float $timeout
   *   The wall-clock budget, in seconds, for the list command.
   */
  public function __construct(
    string $root,
    protected string $binary,
    protected array $listArgs,
    ?\Closure $runner = NULL,
    protected float $timeout = self::DEFAULT_TIMEOUT,
  ) {
    $this->root = rtrim($root, '/');
    $this->runner = $runner ?? fn(string $command, string $cwd): array => (new ProcessRunner($this->timeout))->run($command, $cwd);
  }

  /**
   * The reference token a skill uses to name the binary: its basename.
   *
   * @return string
   *   The binary basename, e.g. `harness` for `bin/harness`.
   */
  public function binaryName(): string {
    return basename($this->binary);
  }

  /**
   * The set of first tokens of every known command, resolved once and cached.
   *
   * @return string[]
   *   The unique first tokens, e.g. `['workflow', 'build']`.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the binary cannot run or its output cannot be parsed.
   */
  public function firstTokens(): array {
    if ($this->tokens === NULL) {
      $this->tokens = $this->resolve();
    }

    return $this->tokens;
  }

  /**
   * Runs the binary and reduces its command list to unique first tokens.
   *
   * @return string[]
   *   The unique first tokens.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the binary cannot run or its output cannot be parsed.
   */
  protected function resolve(): array {
    [$exit_code, $stdout] = ($this->runner)($this->buildCommand(), $this->root);

    if ($exit_code !== 0) {
      throw new ConfigException(sprintf("command binary '%s' failed (exit %d) resolving its command list.", $this->binary, $exit_code), $this->binary, self::POINTER);
    }

    $names = $this->parseNames($stdout);

    if ($names === NULL) {
      throw new ConfigException(sprintf("command binary '%s' produced no parseable command list.", $this->binary), $this->binary, self::POINTER);
    }

    $tokens = [];

    foreach ($names as $name) {
      $token = $this->firstToken($name);

      if ($token !== '') {
        $tokens[$token] = TRUE;
      }
    }

    return array_keys($tokens);
  }

  /**
   * Assembles the shell command that prints the binary's command list.
   *
   * @return string
   *   The escaped command.
   */
  protected function buildCommand(): string {
    $parts = [escapeshellarg($this->binary)];

    foreach ($this->listArgs as $arg) {
      $parts[] = escapeshellarg($arg);
    }

    return implode(' ', $parts);
  }

  /**
   * Parses the binary's output into a list of command names.
   *
   * @param string $stdout
   *   The captured stdout.
   *
   * @return string[]|null
   *   The command names, or NULL when the output yields none.
   */
  protected function parseNames(string $stdout): ?array {
    $trimmed = trim($stdout);

    if ($trimmed === '') {
      return NULL;
    }

    $decoded = json_decode($trimmed, TRUE);
    $names = is_array($decoded) ? $this->namesFromJson($decoded) : $this->namesFromText($trimmed);

    return $names === [] ? NULL : $names;
  }

  /**
   * Extracts command names from a decoded JSON command list.
   *
   * Accepts a bare list of name strings, a list of `{name: ...}` objects, or
   * Symfony's `{commands: [...]}` wrapper.
   *
   * @param array<mixed> $decoded
   *   The decoded JSON.
   *
   * @return string[]
   *   The command names, possibly empty.
   */
  protected function namesFromJson(array $decoded): array {
    $wrapped = Data::get($decoded, 'commands');
    $items = is_array($wrapped) ? $wrapped : $decoded;

    $names = [];

    foreach ($items as $item) {
      if (is_string($item)) {
        $names[] = $item;

        continue;
      }

      $name = Data::toStringOrNull(Data::get(Data::toArray($item), 'name'));

      if ($name !== NULL) {
        $names[] = $name;
      }
    }

    return $names;
  }

  /**
   * Extracts command names from a plain-text command list, one per line.
   *
   * Only a line whose leading token is a command name standing alone or
   * followed by a description gap counts: the token must be at end of line or
   * separated from any description by two or more spaces. This keeps help
   * headers like `Usage:`, `Available commands:`, and `Options` - which run a
   * single space into their next word - from being read as commands. JSON
   * output (the recommended `list` form) avoids this heuristic entirely.
   *
   * @param string $text
   *   The trimmed output.
   *
   * @return string[]
   *   The command name of each qualifying line.
   */
  protected function namesFromText(string $text): array {
    $names = [];

    foreach (explode("\n", $text) as $line) {
      if (preg_match('/^\s*([a-z][\w-]*(?::[\w-]+)*)(?:\s{2,}|\s*$)/i', $line, $matches) === 1) {
        $names[] = $matches[1];
      }
    }

    return $names;
  }

  /**
   * Reduces a command name to its first token.
   *
   * A namespaced (`workflow:start`) or multi-word (`workflow start`) command
   * collapses to its leading segment, the token a skill reference must match.
   *
   * @param string $name
   *   The command name.
   *
   * @return string
   *   The first token, or an empty string.
   */
  protected function firstToken(string $name): string {
    $parts = preg_split('/[\s:]+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);

    return ($parts === FALSE || $parts === []) ? '' : $parts[0];
  }

}
