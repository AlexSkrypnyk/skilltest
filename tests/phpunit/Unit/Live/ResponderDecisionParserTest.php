<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Live\ResponderAction;
use AlexSkrypnyk\SkillTest\Live\ResponderDecision;
use AlexSkrypnyk\SkillTest\Live\ResponderDecisionParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class ResponderDecisionParserTest.
 *
 * Unit test for the hardened responder-decision parser, proven against the
 * noise a real model wraps its JSON in.
 */
#[CoversClass(ResponderDecisionParser::class)]
final class ResponderDecisionParserTest extends TestCase {

  #[DataProvider('dataProviderParses')]
  public function testParses(string $raw, ResponderAction $action, string $message): void {
    $decision = (new ResponderDecisionParser())->parse($raw);

    $this->assertInstanceOf(ResponderDecision::class, $decision);
    $this->assertSame($action, $decision->action);
    $this->assertSame($message, $decision->message);
  }

  public static function dataProviderParses(): \Iterator {
    yield 'a reply carries its message' => ['{"action":"reply","message":"The board is Team Board."}', ResponderAction::Reply, 'The board is Team Board.'];
    yield 'a stop needs no message' => ['{"action":"stop"}', ResponderAction::Stop, ''];
    yield 'an abstention needs no message' => ['{"action":"abstain"}', ResponderAction::Abstain, ''];
    yield 'a fenced decision is unwrapped' => ["```json\n{\"action\":\"stop\"}\n```", ResponderAction::Stop, ''];
    yield 'leading prose is discarded' => ['Here is my move: {"action":"reply","message":"yes"}', ResponderAction::Reply, 'yes'];
    yield 'the action is matched case-insensitively' => ['{"action":"REPLY","message":"ok"}', ResponderAction::Reply, 'ok'];
    yield 'the action is trimmed' => ['{"action":" stop "}', ResponderAction::Stop, ''];
    yield 'a stop may still carry a message that is kept' => ['{"action":"stop","message":"done"}', ResponderAction::Stop, 'done'];
  }

  #[DataProvider('dataProviderRejects')]
  public function testRejects(string $raw): void {
    $this->assertNotInstanceOf(ResponderDecision::class, (new ResponderDecisionParser())->parse($raw));
  }

  public static function dataProviderRejects(): \Iterator {
    yield 'not json at all' => ['I think you should stop now.'];
    yield 'not an object' => ['[1, 2, 3]'];
    yield 'a missing action' => ['{"message":"hello"}'];
    yield 'a non-string action' => ['{"action":5}'];
    yield 'an unknown action' => ['{"action":"maybe"}'];
    yield 'a reply with no message' => ['{"action":"reply"}'];
    yield 'a reply with an empty message' => ['{"action":"reply","message":""}'];
    yield 'a reply with a whitespace-only message' => ['{"action":"reply","message":"   "}'];
  }

}
