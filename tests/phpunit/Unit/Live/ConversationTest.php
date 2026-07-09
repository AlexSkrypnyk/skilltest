<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Live\Conversation;
use AlexSkrypnyk\SkillTest\Live\ResponderOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class ConversationTest.
 *
 * Unit test for the conversation value object that unifies single-shot and
 * interactive trials for grading.
 */
#[CoversClass(Conversation::class)]
final class ConversationTest extends TestCase {

  public function testSingleShotReadsUsageFromTheTranscript(): void {
    $transcript = '{"type":"result","num_turns":3,"total_cost_usd":0.02,"usage":{"input_tokens":120,"output_tokens":40}}' . "\n";

    $conversation = Conversation::singleShot(0, $transcript, 1500);

    $this->assertSame(0, $conversation->exitCode);
    $this->assertSame($transcript, $conversation->transcript);
    $this->assertSame(1500, $conversation->durationMs);
    $this->assertSame(120, $conversation->tokensIn);
    $this->assertSame(40, $conversation->tokensOut);
    $this->assertSame(3, $conversation->turns);
    $this->assertEqualsWithDelta(0.02, $conversation->cost, PHP_FLOAT_EPSILON);
    $this->assertNull($conversation->outcome);
    $this->assertSame(0, $conversation->followups);
    $this->assertFalse($conversation->responderFailed());
  }

  public function testInteractiveFieldsAreCarried(): void {
    $conversation = new Conversation(0, 'jsonl', 900, 10, 5, 2, 0.01, ResponderOutcome::CapExhausted, 3);

    $this->assertSame(ResponderOutcome::CapExhausted, $conversation->outcome);
    $this->assertSame(3, $conversation->followups);
  }

  #[DataProvider('dataProviderResponderFailed')]
  public function testResponderFailed(?ResponderOutcome $outcome, bool $failed): void {
    $conversation = new Conversation(0, 'jsonl', 0, 0, 0, 0, 0.0, $outcome);

    $this->assertSame($failed, $conversation->responderFailed());
  }

  public static function dataProviderResponderFailed(): \Iterator {
    yield 'single-shot never fails on a responder' => [NULL, FALSE];
    yield 'completed does not fail' => [ResponderOutcome::Completed, FALSE];
    yield 'cap-exhausted does not fail' => [ResponderOutcome::CapExhausted, FALSE];
    yield 'abstained fails' => [ResponderOutcome::Abstained, TRUE];
    yield 'error fails' => [ResponderOutcome::Error, TRUE];
  }

}
