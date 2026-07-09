<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Live\TranscriptMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class TranscriptMetricsTest.
 *
 * Unit test for extracting run totals from a stream-json transcript.
 */
#[CoversClass(TranscriptMetrics::class)]
final class TranscriptMetricsTest extends TestCase {

  public function testReadsTheResultEvent(): void {
    $jsonl = '{"type":"assistant","message":{}}' . "\n" . '{"type":"result","num_turns":6,"total_cost_usd":0.0132,"usage":{"input_tokens":4211,"output_tokens":883}}' . "\n";

    $metrics = TranscriptMetrics::fromTranscript($jsonl);

    $this->assertSame(4211, $metrics->tokensIn);
    $this->assertSame(883, $metrics->tokensOut);
    $this->assertSame(6, $metrics->turns);
    $this->assertEqualsWithDelta(0.0132, $metrics->cost, PHP_FLOAT_EPSILON);
  }

  public function testLastResultWins(): void {
    $jsonl = '{"type":"result","num_turns":1,"usage":{"input_tokens":1,"output_tokens":1}}' . "\n" . '{"type":"result","num_turns":9,"usage":{"input_tokens":100,"output_tokens":50}}' . "\n";

    $metrics = TranscriptMetrics::fromTranscript($jsonl);

    $this->assertSame(100, $metrics->tokensIn);
    $this->assertSame(9, $metrics->turns);
  }

  public function testZeroesWhenNoResultEvent(): void {
    $metrics = TranscriptMetrics::fromTranscript('{"type":"assistant"}' . "\n" . '   ' . "\n" . 'not json');

    $this->assertSame(0, $metrics->tokensIn);
    $this->assertSame(0, $metrics->tokensOut);
    $this->assertSame(0, $metrics->turns);
    $this->assertEqualsWithDelta(0.0, $metrics->cost, PHP_FLOAT_EPSILON);
  }

  public function testZeroesOnNegativeOrNonNumericUsage(): void {
    $jsonl = '{"type":"result","num_turns":-3,"total_cost_usd":"free","usage":{"input_tokens":-1,"output_tokens":"lots"}}';

    $metrics = TranscriptMetrics::fromTranscript($jsonl);

    $this->assertSame(0, $metrics->tokensIn);
    $this->assertSame(0, $metrics->tokensOut);
    $this->assertSame(0, $metrics->turns);
    $this->assertEqualsWithDelta(0.0, $metrics->cost, PHP_FLOAT_EPSILON);
  }

  public function testAcceptsFloatTokenCounts(): void {
    $jsonl = '{"type":"result","num_turns":2.0,"usage":{"input_tokens":10.0,"output_tokens":5.0}}';

    $metrics = TranscriptMetrics::fromTranscript($jsonl);

    $this->assertSame(10, $metrics->tokensIn);
    $this->assertSame(5, $metrics->tokensOut);
    $this->assertSame(2, $metrics->turns);
  }

  public function testIgnoresResultWhenUsageIsNotAnArray(): void {
    $metrics = TranscriptMetrics::fromTranscript('{"type":"result","num_turns":4,"usage":"none"}');

    $this->assertSame(0, $metrics->tokensIn);
    $this->assertSame(4, $metrics->turns);
  }

}
