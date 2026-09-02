<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

/**
 * Builds the one-shot `claude -p` invocation that produces one responder move.
 *
 * The responder is a single-turn call, not an agent run, so the command is
 * minimal: the prompt drives it and the model is pinned from the task's
 * responder config, with no stream-json, tool restriction, or turn cap. The
 * binary is a command prefix (`claude`, or `php /path/to/stub` in tests) used
 * verbatim; the prompt and model are shell-escaped.
 */
final readonly class ResponderCommand {

  /**
   * Builds the responder command string.
   *
   * @param string $binary
   *   The resolved agent binary or command prefix, used verbatim.
   * @param string $prompt
   *   The responder prompt handed to the model with `-p`.
   * @param string $model
   *   The resolved responder model id.
   *
   * @return string
   *   The assembled command.
   */
  public static function build(string $binary, string $prompt, string $model): string {
    return sprintf('%s -p %s --model %s', $binary, escapeshellarg($prompt), escapeshellarg($model));
  }

}
