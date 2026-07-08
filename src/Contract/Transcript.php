<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Contract;

/**
 * A parsed agent transcript: the ordered tool-use record of one headless run.
 *
 * A transcript is JSONL, one JSON object per line, as emitted by
 * `claude -p --output-format stream-json --verbose`. Every decoded object is
 * walked recursively and each `tool_use` node collected in order, so nested
 * transcript shapes yield the same event stream as flat ones. This parse is the
 * shared substrate the contract engine grades against - the identical events
 * feed the deterministic recorded fixture and every live llm trial.
 */
final class Transcript {

  /**
   * The name of the tool whose input carries a Bash command string.
   */
  public const string BASH_TOOL = 'Bash';

  /**
   * The name of the tool whose input carries a sub-skill name.
   */
  public const string SKILL_TOOL = 'Skill';

  /**
   * The extracted tool-use events, in order.
   *
   * @var list<array{name: string, input: array<array-key, mixed>}>
   */
  protected array $toolUses;

  /**
   * Constructs a Transcript from raw JSONL.
   *
   * @param string $jsonl
   *   The transcript, one JSON object per line.
   */
  public function __construct(string $jsonl) {
    $this->toolUses = self::extract($jsonl);
  }

  /**
   * Builds a transcript from a file.
   *
   * @param string $path
   *   The transcript file path.
   *
   * @return self
   *   The parsed transcript.
   */
  public static function fromFile(string $path): self {
    if (!is_file($path)) {
      return new self('');
    }

    $contents = file_get_contents($path);

    return new self($contents === FALSE ? '' : $contents);
  }

  /**
   * Every tool-use event, in order.
   *
   * @return list<array{name: string, input: array<array-key, mixed>}>
   *   The events.
   */
  public function toolUses(): array {
    return $this->toolUses;
  }

  /**
   * The tool names invoked, in order.
   *
   * @return list<string>
   *   The names.
   */
  public function toolNames(): array {
    return array_map(static fn(array $use): string => $use['name'], $this->toolUses);
  }

  /**
   * The Bash command strings invoked, in order.
   *
   * @return list<string>
   *   The commands.
   */
  public function bashCommands(): array {
    $commands = [];

    foreach ($this->toolUses as $use) {
      if ($use['name'] === self::BASH_TOOL && isset($use['input']['command']) && is_string($use['input']['command'])) {
        $commands[] = $use['input']['command'];
      }
    }

    return $commands;
  }

  /**
   * The sub-skill names invoked through the Skill tool, in order.
   *
   * @return list<string>
   *   The skill names.
   */
  public function skillInvocations(): array {
    $skills = [];

    foreach ($this->toolUses as $use) {
      if ($use['name'] === self::SKILL_TOOL && isset($use['input']['skill']) && is_string($use['input']['skill'])) {
        $skills[] = $use['input']['skill'];
      }
    }

    return $skills;
  }

  /**
   * Extracts every tool-use event from a JSONL transcript.
   *
   * @param string $jsonl
   *   The transcript, one JSON object per line.
   *
   * @return list<array{name: string, input: array<array-key, mixed>}>
   *   The events, in order.
   */
  protected static function extract(string $jsonl): array {
    $uses = [];

    foreach (preg_split('/\R/', trim($jsonl)) ?: [] as $line) {
      if (trim($line) === '') {
        continue;
      }

      $decoded = json_decode($line, TRUE);

      if (is_array($decoded)) {
        self::walk($decoded, $uses);
      }
    }

    return $uses;
  }

  /**
   * Recursively collects tool-use nodes from a decoded transcript node.
   *
   * @param array<int|string, mixed> $node
   *   The node to walk.
   * @param list<array{name: string, input: array<array-key, mixed>}> $uses
   *   The accumulator, appended to in place.
   */
  protected static function walk(array $node, array &$uses): void {
    if (($node['type'] ?? NULL) === 'tool_use' && isset($node['name']) && is_string($node['name'])) {
      $input = isset($node['input']) && is_array($node['input']) ? $node['input'] : [];
      $uses[] = ['name' => $node['name'], 'input' => $input];
    }

    foreach ($node as $value) {
      if (is_array($value)) {
        self::walk($value, $uses);
      }
    }
  }

}
