<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

use AlexSkrypnyk\SkillTest\Contract\Transcript;

/**
 * Drives one interactive trial: agent turns interleaved with responder moves.
 *
 * A skill that asks follow-up questions cannot be tested with a single prompt,
 * so this runs the opening prompt and then, after every agent turn, asks the
 * responder what the user does next: reply (resume the same session with the
 * answer and go again), stop (the agent finished), or abstain (the persona
 * could not answer). Sending {@see ResponderConfig::$maxFollowups} replies and
 * still being asked for more caps the run. Each responder reply is recorded
 * into the accumulated transcript as a user turn, so the grader and the judge
 * see the whole dialogue, and per-turn usage is summed because each headless
 * turn reports only its own tally. The result is a {@see Conversation} the
 * suite grades exactly as it grades a single-shot run, plus the terminal
 * {@see ResponderOutcome}. The agent turns run through an injected runner so
 * the loop is tested without an agent, and a non-zero agent exit ends the
 * conversation for the grader to fail.
 */
final readonly class ConversationRunner {

  /**
   * Constructs a ConversationRunner.
   *
   * @param string $binary
   *   The resolved agent binary or command prefix, for building turn commands.
   * @param string $root
   *   The working directory the responder call runs in.
   * @param \AlexSkrypnyk\SkillTest\Live\Responder $responder
   *   The responder that plays the user after each agent turn.
   */
  public function __construct(
    protected string $binary,
    protected string $root,
    protected Responder $responder,
  ) {}

  /**
   * Runs one interactive trial to a graded-ready conversation.
   *
   * @param \Closure(string): array{0: int, 1: string, 2: int} $turn_runner
   *   A runner taking an assembled agent command and returning
   *   `[exitCode, stdout, durationMs]`, bound to the trial's workspace.
   * @param string $prompt
   *   The opening task prompt.
   * @param string|null $model
   *   The resolved execution model id, or NULL for the agent default.
   * @param int|null $max_turns
   *   The per-turn turn cap, or NULL for none.
   * @param string[] $allowed
   *   The contract's allowed tools.
   * @param \AlexSkrypnyk\SkillTest\Live\ResponderConfig $config
   *   The task's responder configuration.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\Conversation
   *   The accumulated conversation and its terminal outcome.
   */
  public function run(\Closure $turn_runner, string $prompt, ?string $model, ?int $max_turns, array $allowed, ResponderConfig $config): Conversation {
    $command = AgentCommand::build($this->binary, $prompt, $model, $max_turns, $allowed);
    [$exit_code, $stdout, $duration_ms] = $turn_runner($command);

    $segments = [$stdout];
    $agent_outputs = [$stdout];
    $last_agent = $stdout;
    $dialogue = [['role' => 'agent', 'text' => (new Transcript($stdout))->resultText()]];
    $followups = 0;
    $outcome = ResponderOutcome::Completed;

    // The opening turn failing is an agent problem the grader fails on its exit
    // code; the responder is never engaged, so the loop is skipped entirely.
    while ($exit_code === 0) {
      $decision = $this->responder->respond($config, $dialogue, $this->root);

      if (!$decision instanceof ResponderDecision) {
        $outcome = ResponderOutcome::Error;
        break;
      }

      if ($decision->action === ResponderAction::Stop) {
        $outcome = ResponderOutcome::Completed;
        break;
      }

      if ($decision->action === ResponderAction::Abstain) {
        $outcome = ResponderOutcome::Abstained;
        break;
      }

      if ($followups >= $config->maxFollowups) {
        $outcome = ResponderOutcome::CapExhausted;
        break;
      }

      $followups++;
      $segments[] = self::responderLine($decision->message);
      $dialogue[] = ['role' => 'user', 'text' => $decision->message];

      $resume = AgentCommand::build($this->binary, $decision->message, $model, $max_turns, $allowed, resume: (new Transcript($last_agent))->sessionId());
      [$exit_code, $stdout, $turn_ms] = $turn_runner($resume);
      $segments[] = $stdout;
      $agent_outputs[] = $stdout;
      $last_agent = $stdout;
      $duration_ms += $turn_ms;
      $dialogue[] = ['role' => 'agent', 'text' => (new Transcript($stdout))->resultText()];
    }

    return $this->assemble($exit_code, $segments, $agent_outputs, $duration_ms, $outcome, $followups);
  }

  /**
   * Assembles the conversation from its segments and summed agent metrics.
   *
   * @param int $exit_code
   *   The last agent turn's exit code.
   * @param string[] $segments
   *   The transcript segments, agent turns interleaved with responder turns.
   * @param string[] $agent_outputs
   *   The agent turn transcripts, the only source of usage metrics.
   * @param int $duration_ms
   *   The summed wall-clock duration across agent turns.
   * @param \AlexSkrypnyk\SkillTest\Live\ResponderOutcome $outcome
   *   The terminal outcome the loop reached.
   * @param int $followups
   *   The number of responder replies sent.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\Conversation
   *   The assembled conversation.
   */
  protected function assemble(int $exit_code, array $segments, array $agent_outputs, int $duration_ms, ResponderOutcome $outcome, int $followups): Conversation {
    $tokens_in = 0;
    $tokens_out = 0;
    $turns = 0;
    $cost = 0.0;

    foreach ($agent_outputs as $output) {
      $metrics = TranscriptMetrics::fromTranscript($output);
      $tokens_in += $metrics->tokensIn;
      $tokens_out += $metrics->tokensOut;
      $turns += $metrics->turns;
      $cost += $metrics->cost;
    }

    return new Conversation($exit_code, implode('', $segments), $duration_ms, $tokens_in, $tokens_out, $turns, $cost, $outcome, $followups);
  }

  /**
   * Renders one responder reply as an injected user turn in the transcript.
   *
   * @param string $message
   *   The reply the responder sent to the agent.
   *
   * @return string
   *   The JSONL line, marked so the transcript parser recognises it as a
   *   responder turn and the contract engine ignores it.
   */
  protected static function responderLine(string $message): string {
    return json_encode(['type' => 'user', 'responder' => TRUE, 'text' => $message], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
  }

}
