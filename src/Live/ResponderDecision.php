<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

/**
 * One responder move: the action chosen and, for a reply, the message sent.
 *
 * The message is meaningful only for a {@see ResponderAction::Reply} - the text
 * handed back to the agent as the user's next turn. A stop or an abstention
 * carries no message, so it defaults to the empty string.
 */
final readonly class ResponderDecision {

  /**
   * Constructs a ResponderDecision.
   *
   * @param \AlexSkrypnyk\SkillTest\Live\ResponderAction $action
   *   The move the responder made.
   * @param string $message
   *   The reply text sent to the agent, empty for a stop or an abstention.
   */
  public function __construct(
    public ResponderAction $action,
    public string $message = '',
  ) {}

}
