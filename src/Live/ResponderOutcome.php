<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

/**
 * How an interactive trial's conversation ended, recorded in the results.
 *
 * The trial-level counterpart to a per-turn {@see ResponderAction}: it is the
 * terminal state the conversation loop reached, and it travels into
 * `results.json` so a reader can tell a clean completion from the two failure
 * shapes the responder exists to surface. {@see Completed} and
 * {@see CapExhausted} both grade the final state; {@see Abstained} and
 * {@see Error} fail the trial with a responder check and never spend a judge
 * token on an incomplete run.
 */
enum ResponderOutcome: string {

  // The responder stopped because the agent had finished.
  case Completed = 'completed';

  // The persona could not infer an answer; the brief was too vague.
  case Abstained = 'abstained';

  // The follow-up cap was reached while the agent was still asking.
  case CapExhausted = 'cap-exhausted';

  // The responder process failed or returned an unusable decision.
  case Error = 'error';

  /**
   * Whether this outcome fails the trial and skips the judge.
   *
   * An abstention or a responder error is an incomplete run: the conversation
   * never reached a state worth judging, so the trial fails on a responder
   * check and no judge token is spent. A completion or a cap-exhaustion grades
   * the final state normally.
   *
   * @return bool
   *   TRUE when the outcome is a responder failure.
   */
  public function isFailure(): bool {
    return $this === self::Abstained || $this === self::Error;
  }

}
