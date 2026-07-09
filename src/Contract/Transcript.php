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
   * The last `result` event's text, or the empty string when there is none.
   */
  protected string $resultText;

  /**
   * The last `session_id` seen, or NULL when the transcript carries none.
   */
  protected ?string $sessionId;

  /**
   * The injected responder (user) turn texts, in order.
   *
   * @var list<string>
   */
  protected array $responderTurns;

  /**
   * Constructs a Transcript from raw JSONL.
   *
   * @param string $jsonl
   *   The transcript, one JSON object per line.
   */
  public function __construct(string $jsonl) {
    $this->toolUses = self::extract($jsonl);
    [$this->resultText, $this->sessionId, $this->responderTurns] = self::scanMeta($jsonl);
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

    foreach ($this->toolUses as $tool_use) {
      if ($tool_use['name'] === self::BASH_TOOL && isset($tool_use['input']['command']) && is_string($tool_use['input']['command'])) {
        $commands[] = $tool_use['input']['command'];
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

    foreach ($this->toolUses as $tool_use) {
      if ($tool_use['name'] === self::SKILL_TOOL && isset($tool_use['input']['skill']) && is_string($tool_use['input']['skill'])) {
        $skills[] = $tool_use['input']['skill'];
      }
    }

    return $skills;
  }

  /**
   * The last `result` event's text.
   *
   * This is the agent's final assistant message - the reply a conversational
   * responder answers and the "final output" a judge scores.
   *
   * @return string
   *   The result text, or the empty string when the transcript has no result.
   */
  public function resultText(): string {
    return $this->resultText;
  }

  /**
   * The last session id the transcript reports.
   *
   * A headless run emits its `session_id` on both the init and result events;
   * the last one is the handle a follow-up turn resumes the conversation with.
   *
   * @return string|null
   *   The session id, or NULL when the transcript carries none.
   */
  public function sessionId(): ?string {
    return $this->sessionId;
  }

  /**
   * The injected responder (user) turn texts, in order.
   *
   * @return list<string>
   *   The responder replies recorded into the transcript, in order.
   */
  public function responderTurns(): array {
    return $this->responderTurns;
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

  /**
   * Scans a transcript for its result text, session id, and responder turns.
   *
   * A single pass over the top-level events collects the three whole-run facts
   * the tool-use walk does not: the last `result` event's text and the last
   * `session_id` (both defined by the run's final tally), and every injected
   * responder turn in the order the conversation recorded them.
   *
   * @param string $jsonl
   *   The transcript, one JSON object per line.
   *
   * @return array{0: string, 1: string|null, 2: list<string>}
   *   The result text, the last session id, and the responder turn texts.
   */
  protected static function scanMeta(string $jsonl): array {
    $result_text = '';
    $session_id = NULL;
    $responder_turns = [];

    foreach (preg_split('/\R/', trim($jsonl)) ?: [] as $line) {
      if (trim($line) === '') {
        continue;
      }

      $decoded = json_decode($line, TRUE);

      if (!is_array($decoded)) {
        continue;
      }

      if (isset($decoded['session_id']) && is_string($decoded['session_id'])) {
        $session_id = $decoded['session_id'];
      }

      if (($decoded['type'] ?? NULL) === 'result' && isset($decoded['result']) && is_string($decoded['result'])) {
        $result_text = $decoded['result'];
      }

      if (($decoded['type'] ?? NULL) === 'user' && ($decoded['responder'] ?? NULL) === TRUE && isset($decoded['text']) && is_string($decoded['text'])) {
        $responder_turns[] = $decoded['text'];
      }
    }

    return [$result_text, $session_id, $responder_turns];
  }

}
