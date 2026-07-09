<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

/**
 * A trial's run reduced to what grading needs, single-shot or interactive.
 *
 * A single-prompt trial and a multi-turn conversation reach the grader through
 * the same shape: the accumulated transcript (agent turns, plus the responder
 * turns of an interactive run), the last turn's exit code, the summed duration
 * and usage metrics, and - for an interactive run only - how the conversation
 * ended and how many follow-ups it took. A single-shot trial carries a NULL
 * outcome so the grader knows to record no responder block, while an
 * interactive run always names its {@see ResponderOutcome}. Metrics are summed
 * across agent turns because each headless turn reports only its own tally.
 */
final readonly class Conversation {

  /**
   * Constructs a Conversation.
   *
   * @param int $exitCode
   *   The last agent turn's exit code; non-zero folds in the agent-failure
   *   check.
   * @param string $transcript
   *   The accumulated stream-json transcript, with responder turns injected.
   * @param int $durationMs
   *   The summed wall-clock duration across agent turns, in milliseconds.
   * @param int $tokensIn
   *   The summed input token count.
   * @param int $tokensOut
   *   The summed output token count.
   * @param int $turns
   *   The summed number of agent turns reported across the conversation.
   * @param float $cost
   *   The summed run cost in USD.
   * @param \AlexSkrypnyk\SkillTest\Live\ResponderOutcome|null $outcome
   *   How the conversation ended, or NULL for a non-interactive single-shot
   *   trial.
   * @param int $followups
   *   The number of responder replies sent, zero for a single-shot trial.
   */
  public function __construct(
    public int $exitCode,
    public string $transcript,
    public int $durationMs,
    public int $tokensIn,
    public int $tokensOut,
    public int $turns,
    public float $cost,
    public ?ResponderOutcome $outcome = NULL,
    public int $followups = 0,
  ) {}

  /**
   * Builds a single-shot conversation from one agent run's raw outcome.
   *
   * @param int $exit_code
   *   The agent process exit code.
   * @param string $transcript
   *   The captured stream-json transcript.
   * @param int $duration_ms
   *   The measured wall-clock duration, in milliseconds.
   *
   * @return self
   *   The conversation, with usage read from the single transcript.
   */
  public static function singleShot(int $exit_code, string $transcript, int $duration_ms): self {
    $metrics = TranscriptMetrics::fromTranscript($transcript);

    return new self($exit_code, $transcript, $duration_ms, $metrics->tokensIn, $metrics->tokensOut, $metrics->turns, $metrics->cost);
  }

  /**
   * Whether the responder ended the run in a failure state.
   *
   * @return bool
   *   TRUE when an interactive run abstained or the responder failed.
   */
  public function responderFailed(): bool {
    return $this->outcome?->isFailure() ?? FALSE;
  }

}
