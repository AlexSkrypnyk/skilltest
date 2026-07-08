<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Hooks;

/**
 * One crafted enforcement case: a tool input and the decision it must provoke.
 *
 * A case is the unit a hook is proven against - it names the tool, the input
 * the model would have run, and whether the hook must block or allow it. It
 * knows how to render itself as the PreToolUse payload the hook reads on stdin,
 * so the runner stays about process control and verdicts rather than the wire
 * format.
 */
final readonly class HookCase {

  /**
   * The hook event this payload represents (Claude Code PreToolUse protocol).
   */
  public const string EVENT_NAME = 'PreToolUse';

  /**
   * The `expect` value asserting the hook blocks the input.
   */
  public const string EXPECT_BLOCK = 'block';

  /**
   * The `expect` value asserting the hook allows the input.
   */
  public const string EXPECT_ALLOW = 'allow';

  /**
   * Constructs a HookCase.
   *
   * @param string $tool
   *   The tool name the input targets, e.g. `Bash`.
   * @param array<mixed> $input
   *   The tool input object, e.g. `['command' => 'gh pr create']`.
   * @param string $expect
   *   The expected decision: `block` or `allow`.
   */
  public function __construct(
    public string $tool,
    public array $input,
    public string $expect,
  ) {}

  /**
   * Renders the case as the JSON payload the hook receives on stdin.
   *
   * The input is cast to an object so an empty input encodes as `{}` rather
   * than `[]`, matching the runtime's always-object `tool_input`.
   *
   * @return string
   *   The PreToolUse payload JSON.
   */
  public function payload(): string {
    return json_encode([
      'hook_event_name' => self::EVENT_NAME,
      'tool_name' => $this->tool,
      'tool_input' => (object) $this->input,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
  }

  /**
   * Returns whether the case expects the hook to block the input.
   *
   * @return bool
   *   TRUE when the expected decision is `block`.
   */
  public function expectsBlock(): bool {
    return $this->expect === self::EXPECT_BLOCK;
  }

  /**
   * Returns a compact, human-readable form of the input for evidence.
   *
   * A Bash-style `command` string is shown verbatim; any other input is shown
   * as its JSON object so the offending case is legible in a failure message.
   *
   * @return string
   *   The command string, or the input encoded as a JSON object.
   */
  public function inputSummary(): string {
    $command = $this->input['command'] ?? NULL;

    if (is_string($command)) {
      return $command;
    }

    return json_encode((object) $this->input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
  }

}
