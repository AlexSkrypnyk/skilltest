<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Judge;

/**
 * Builds the one-shot `claude -p` invocation that scores one trial.
 *
 * The judge is a single-turn scoring call, not an agent run, so the command is
 * deliberately minimal: the prompt drives it and the model is pinned from
 * config, with no stream-json, tool restriction, or turn cap. The model is
 * passed explicitly on every call so the judge model never follows the
 * execution model. The binary is a command prefix (`claude`, or `php
 * /path/to/stub` in tests) used verbatim; the prompt and model are
 * shell-escaped.
 */
final readonly class JudgeCommand {

  /**
   * Builds the judge command string.
   *
   * @param string $binary
   *   The resolved agent binary or command prefix, used verbatim.
   * @param string $prompt
   *   The judge prompt handed to the model with `-p`.
   * @param string $model
   *   The resolved judge model id.
   *
   * @return string
   *   The assembled command.
   */
  public static function build(string $binary, string $prompt, string $model): string {
    return sprintf('%s -p %s --model %s', $binary, escapeshellarg($prompt), escapeshellarg($model));
  }

}
