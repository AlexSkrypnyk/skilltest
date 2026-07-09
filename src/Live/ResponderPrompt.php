<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

/**
 * Builds the prompt that asks a responder for its next move.
 *
 * The prompt frames the model as the user in a conversation with an AI agent,
 * hands it the persona brief it must stay consistent with, and shows the
 * dialogue so far so its answer follows from the whole exchange rather than the
 * last line alone. It ends with the exact JSON shape the decision parser
 * expects and the three legitimate moves - reply, stop, abstain - so the
 * responder answers, recognises when the agent is done, or abstains rather than
 * inventing an answer the brief does not support.
 */
final readonly class ResponderPrompt {

  /**
   * Builds the responder prompt.
   *
   * @param string $instructions
   *   The persona and target configuration the responder plays.
   * @param array<int, array{role: string, text: string}> $dialogue
   *   The conversation so far, in order; each turn names its `role`
   *   (`agent` or `user`) and its `text`.
   *
   * @return string
   *   The assembled responder prompt.
   */
  public static function build(string $instructions, array $dialogue): string {
    $lines = [];

    foreach ($dialogue as $turn) {
      $speaker = $turn['role'] === 'user' ? 'USER (you)' : 'AGENT';
      $text = trim($turn['text']);
      $lines[] = sprintf('  %s: %s', $speaker, $text === '' ? '(no text)' : $text);
    }

    return implode("\n", [
      'SYSTEM: You are role-playing the USER in a conversation with an AI agent.',
      "        Answer the agent's questions consistently with the persona below.",
      '        If the agent has finished and is not asking anything, stop. If you',
      '        genuinely cannot infer an answer from the persona, abstain rather',
      '        than inventing one.',
      'PERSONA:',
      trim($instructions),
      'CONVERSATION SO FAR:',
      $lines === [] ? '  (none)' : implode("\n", $lines),
      'Return JSON only, with no prose and no code fences:',
      '  {"action":"reply","message":"..."}   to answer the agent',
      '  {"action":"stop"}                     when the agent has finished',
      '  {"action":"abstain"}                  when you cannot infer an answer',
    ]);
  }

}
