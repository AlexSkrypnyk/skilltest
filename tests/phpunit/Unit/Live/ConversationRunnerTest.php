<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Contract\Transcript;
use AlexSkrypnyk\SkillTest\Live\Conversation;
use AlexSkrypnyk\SkillTest\Live\ConversationRunner;
use AlexSkrypnyk\SkillTest\Live\Responder;
use AlexSkrypnyk\SkillTest\Live\ResponderConfig;
use AlexSkrypnyk\SkillTest\Live\ResponderOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class ConversationRunnerTest.
 *
 * Unit test for the interactive conversation loop, driven with a stubbed agent
 * turn runner and a stubbed responder so no token is spent.
 */
#[CoversClass(ConversationRunner::class)]
final class ConversationRunnerTest extends TestCase {

  /**
   * The agent commands the last run drove, in order.
   *
   * @var string[]
   */
  protected array $turnCommands = [];

  public function testCompletesViaRepliesAndSumsMetrics(): void {
    $runner = new ConversationRunner('claude', '/repo', $this->responder([
      '{"action":"reply","message":"Team Board"}',
      '{"action":"stop"}',
    ]));
    $turns = $this->turnRunner([
      [0, $this->agentTurn('Which board?', 'sess-1', 100, 40), 500],
      [0, $this->agentTurn('All set.', 'sess-1', 60, 20), 400],
    ]);

    $conversation = $runner->run($turns, 'Set up the worker.', 'claude-haiku-4-5', 6, ['Bash'], $this->config(6));

    $this->assertInstanceOf(Conversation::class, $conversation);
    $this->assertSame(ResponderOutcome::Completed, $conversation->outcome);
    $this->assertSame(1, $conversation->followups);
    $this->assertSame(0, $conversation->exitCode);
    $this->assertSame(900, $conversation->durationMs);
    $this->assertSame(160, $conversation->tokensIn);
    $this->assertSame(60, $conversation->tokensOut);
    $this->assertSame(2, $conversation->turns);
    $this->assertEqualsWithDelta(0.02, $conversation->cost, PHP_FLOAT_EPSILON);
    $this->assertSame(['Team Board'], (new Transcript($conversation->transcript))->responderTurns());
    $this->assertStringContainsString('All set.', $conversation->transcript);
  }

  public function testTheReplyResumesTheAgentSession(): void {
    $runner = new ConversationRunner('claude', '/repo', $this->responder(['{"action":"reply","message":"the label is worker"}', '{"action":"stop"}']));
    $turns = $this->turnRunner([
      [0, $this->agentTurn('What label?', 'sess-42', 10, 5), 5],
      [0, $this->agentTurn('Done.', 'sess-42', 10, 5), 5],
    ]);

    $runner->run($turns, 'Go', 'claude-haiku-4-5', 6, ['Bash'], $this->config(6));

    $this->assertStringNotContainsString('--resume', $this->turnCommands[0]);
    $this->assertStringContainsString("--resume 'sess-42'", $this->turnCommands[1]);
    $this->assertStringContainsString('the label is worker', $this->turnCommands[1]);
  }

  public function testAbstentionEndsTheRun(): void {
    $runner = new ConversationRunner('claude', '/repo', $this->responder(['{"action":"abstain"}']));
    $turns = $this->turnRunner([[0, $this->agentTurn('Which board?', 'sess-1', 10, 5), 5]]);

    $conversation = $runner->run($turns, 'Go', 'm', 6, [], $this->config(6));

    $this->assertSame(ResponderOutcome::Abstained, $conversation->outcome);
    $this->assertSame(0, $conversation->followups);
    $this->assertCount(1, $this->turnCommands);
  }

  public function testFollowupCapStopsTheRun(): void {
    $runner = new ConversationRunner('claude', '/repo', $this->responder([
      '{"action":"reply","message":"one"}',
      '{"action":"reply","message":"two"}',
    ]));
    $turns = $this->turnRunner([
      [0, $this->agentTurn('Q1?', 'sess-1', 10, 5), 5],
      [0, $this->agentTurn('Q2?', 'sess-1', 10, 5), 5],
    ]);

    $conversation = $runner->run($turns, 'Go', 'm', 1, [], $this->config(1));

    $this->assertSame(ResponderOutcome::CapExhausted, $conversation->outcome);
    $this->assertSame(1, $conversation->followups);
    $this->assertCount(2, $this->turnCommands);
  }

  public function testAnUnusableResponderResponseIsAnError(): void {
    $runner = new ConversationRunner('claude', '/repo', $this->responder(['not a decision']));
    $turns = $this->turnRunner([[0, $this->agentTurn('Which board?', 'sess-1', 10, 5), 5]]);

    $conversation = $runner->run($turns, 'Go', 'm', 6, [], $this->config(6));

    $this->assertSame(ResponderOutcome::Error, $conversation->outcome);
    $this->assertSame(0, $conversation->followups);
  }

  public function testAFailedOpeningTurnSkipsTheResponder(): void {
    $runner = new ConversationRunner('claude', '/repo', $this->responder(['{"action":"stop"}']));
    $turns = $this->turnRunner([[7, '', 5]]);

    $conversation = $runner->run($turns, 'Go', 'm', 6, [], $this->config(6));

    $this->assertSame(7, $conversation->exitCode);
    $this->assertSame(ResponderOutcome::Completed, $conversation->outcome);
    $this->assertSame(0, $conversation->followups);
    $this->assertCount(1, $this->turnCommands);
  }

  public function testAFailedResumeTurnEndsTheConversation(): void {
    $runner = new ConversationRunner('claude', '/repo', $this->responder(['{"action":"reply","message":"answer"}', '{"action":"stop"}']));
    $turns = $this->turnRunner([
      [0, $this->agentTurn('Which board?', 'sess-1', 10, 5), 5],
      [9, '', 5],
    ]);

    $conversation = $runner->run($turns, 'Go', 'm', 6, [], $this->config(6));

    $this->assertSame(9, $conversation->exitCode);
    $this->assertSame(ResponderOutcome::Completed, $conversation->outcome);
    $this->assertSame(1, $conversation->followups);
    $this->assertCount(2, $this->turnCommands);
  }

  /**
   * A responder config with the given follow-up cap and a fixed persona.
   *
   * @param int $max_followups
   *   The follow-up cap.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\ResponderConfig
   *   The configuration.
   */
  protected function config(int $max_followups): ResponderConfig {
    return new ResponderConfig('You are the repo owner.', $max_followups, 'claude-haiku-4-5');
  }

  /**
   * Builds a responder whose injected runner returns queued responses.
   *
   * @param string[] $responses
   *   The per-call responder stdout, each returned with exit 0.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\Responder
   *   The responder.
   */
  protected function responder(array $responses): Responder {
    $index = 0;

    return new Responder('claude', function (string $command, string $cwd) use ($responses, &$index): array {
      $response = $responses[$index];
      $index++;

      return [0, $response];
    });
  }

  /**
   * Builds an agent turn runner that records commands and returns queued turns.
   *
   * @param array<int, array{int, string, int}> $outcomes
   *   The per-turn `[exit, stdout, durationMs]`.
   *
   * @return \Closure
   *   The turn runner.
   */
  protected function turnRunner(array $outcomes): \Closure {
    $this->turnCommands = [];
    $index = 0;

    return function (string $command) use ($outcomes, &$index): array {
      $this->turnCommands[] = $command;
      $outcome = $outcomes[$index];
      $index++;

      return $outcome;
    };
  }

  /**
   * Renders one agent turn's stream-json transcript.
   *
   * @param string $result
   *   The turn's final result text.
   * @param string $session
   *   The session id the turn reports.
   * @param int $tokens_in
   *   The input token count.
   * @param int $tokens_out
   *   The output token count.
   *
   * @return string
   *   The JSONL transcript for the turn.
   */
  protected function agentTurn(string $result, string $session, int $tokens_in, int $tokens_out): string {
    $init = sprintf('{"type":"system","subtype":"init","session_id":"%s"}', $session);
    $done = sprintf('{"type":"result","result":"%s","session_id":"%s","num_turns":1,"total_cost_usd":0.01,"usage":{"input_tokens":%d,"output_tokens":%d}}', $result, $session, $tokens_in, $tokens_out);

    return $init . "\n" . $done . "\n";
  }

}
