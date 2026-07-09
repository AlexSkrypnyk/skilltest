<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

/**
 * Builds the headless `claude -p` invocation for one live trial.
 *
 * The command is the one place the tool's contract shape is turned into an
 * agent invocation: the prompt drives the run, `stream-json` with `--verbose`
 * emits the JSONL transcript the contract engine parses, `--allowedTools`
 * restricts the agent to the contract's allowed tools, `--max-turns` caps the
 * turn budget, and `--mcp-config` with `--strict-mcp-config` points the agent
 * at the trial's mock servers and only those. A follow-up turn of an
 * interactive conversation adds `--resume <session>` so the reply continues
 * the same session rather than starting a fresh one. Only flags that apply are
 * appended, so a task with no turn cap, no allowed-tools list, no mocks, or no
 * session yields a shorter, valid command. The binary is a command prefix
 * (`claude`, or `php /path/to/stub` in tests) used verbatim; every value
 * derived from configuration is shell-escaped.
 */
final readonly class AgentCommand {

  /**
   * Builds the agent command string.
   *
   * @param string $binary
   *   The resolved agent binary or command prefix, used verbatim.
   * @param string $prompt
   *   The task prompt handed to the agent with `-p`.
   * @param string|null $model
   *   The resolved model id, or NULL to let the agent pick its default.
   * @param int|null $max_turns
   *   The turn cap, or NULL for none.
   * @param string[] $allowed_tools
   *   The contract's allowed tools; empty appends no restriction.
   * @param string|null $mcp_config
   *   The trial's MCP config file, or NULL when the task declares no mocks.
   *   When set, `--strict-mcp-config` is paired with it so only the trial's
   *   mock servers load and no host MCP configuration leaks in.
   * @param string|null $resume
   *   The session id to resume, or NULL for a fresh opening turn.
   *
   * @return string
   *   The assembled command.
   */
  public static function build(string $binary, string $prompt, ?string $model, ?int $max_turns, array $allowed_tools, ?string $mcp_config = NULL, ?string $resume = NULL): string {
    $command = sprintf('%s -p %s --output-format stream-json --verbose', $binary, escapeshellarg($prompt));

    if ($model !== NULL && $model !== '') {
      $command .= ' --model ' . escapeshellarg($model);
    }

    if ($max_turns !== NULL) {
      $command .= ' --max-turns ' . $max_turns;
    }

    if ($allowed_tools !== []) {
      $command .= ' --allowedTools ' . escapeshellarg(implode(',', $allowed_tools));
    }

    if ($mcp_config !== NULL && $mcp_config !== '') {
      $command .= ' --mcp-config ' . escapeshellarg($mcp_config) . ' --strict-mcp-config';
    }

    if ($resume !== NULL && $resume !== '') {
      $command .= ' --resume ' . escapeshellarg($resume);
    }

    return $command;
  }

}
