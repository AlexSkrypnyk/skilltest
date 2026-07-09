<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

use AlexSkrypnyk\SkillTest\Ai\JsonObject;
use AlexSkrypnyk\SkillTest\Config\Data;

/**
 * Turns the raw text a responder returns into a decision, defensively.
 *
 * The responder is told to return one small JSON object naming its move, but
 * a model wraps JSON in prose or fences like any other, so parsing is hardened
 * the same way the judge verdict is: the first balanced object is extracted,
 * the `action` is matched case-insensitively against the three legitimate
 * moves, and a reply must carry a non-empty message to be usable. Anything
 * else - undecodable text, a missing or unknown action, or a reply with no
 * message - yields NULL, which the conversation treats as a responder failure
 * rather than a silent stop.
 */
final readonly class ResponderDecisionParser {

  /**
   * Parses a raw responder response into a decision.
   *
   * @param string $raw
   *   The responder's stdout, which may be wrapped in prose or code fences.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\ResponderDecision|null
   *   The parsed decision, or NULL when the response is not a usable move.
   */
  public function parse(string $raw): ?ResponderDecision {
    $decoded = json_decode(JsonObject::extract($raw), TRUE);

    if (!is_array($decoded)) {
      return NULL;
    }

    $token = Data::toStringOrNull($decoded['action'] ?? NULL);

    if ($token === NULL) {
      return NULL;
    }

    $action = ResponderAction::tryFrom(strtolower(trim($token)));

    if ($action === NULL) {
      return NULL;
    }

    $message = Data::toStringOrNull($decoded['message'] ?? NULL) ?? '';

    // A reply that carries no text is not a usable turn: the agent would be
    // resumed with an empty message, so it is a responder failure, not a reply.
    if ($action === ResponderAction::Reply && trim($message) === '') {
      return NULL;
    }

    return new ResponderDecision($action, $message);
  }

}
