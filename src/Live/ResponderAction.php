<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

/**
 * The move a responder makes after one agent turn of an interactive trial.
 *
 * The responder plays the user, so after each agent turn it either answers a
 * question ({@see Reply}), decides the agent has finished and there is nothing
 * left to answer ({@see Stop}), or gives up because the persona brief does not
 * let it infer an answer ({@see Abstain}). These are the only three legitimate
 * moves; a responder that returns anything else has not produced a usable
 * decision, which the conversation treats as a responder failure.
 */
enum ResponderAction: string {

  /**
   * The responder answers the agent's question and the conversation continues.
   */
  case Reply = 'reply';

  /**
   * The agent has finished; the conversation ends and the final state is graded.
   */
  case Stop = 'stop';

  /**
   * The persona cannot infer an answer; the run fails with an abstention.
   */
  case Abstain = 'abstain';

}
