<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

/**
 * Reads the token, turn, and cost totals a stream-json run reports.
 *
 * A headless `claude -p --output-format stream-json` run ends with a `result`
 * event carrying the run's usage (`num_turns`, `usage.input_tokens`,
 * `usage.output_tokens`, `total_cost_usd`). This pulls those totals out of the
 * transcript so a trial's cost is a number in the report rather than a guess.
 * The last `result` event wins - a run that streams several is summarised by
 * its final tally - and every field defaults to zero when a transcript carries
 * no usable result, so a truncated or timed-out transcript still yields a
 * well-formed record.
 */
final readonly class TranscriptMetrics {

  /**
   * Constructs a TranscriptMetrics.
   *
   * @param int $tokensIn
   *   The input token count.
   * @param int $tokensOut
   *   The output token count.
   * @param int $turns
   *   The number of agent turns.
   * @param float $cost
   *   The run cost in USD.
   */
  public function __construct(
    public int $tokensIn,
    public int $tokensOut,
    public int $turns,
    public float $cost,
  ) {}

  /**
   * Extracts the run totals from a stream-json transcript.
   *
   * @param string $jsonl
   *   The transcript, one JSON object per line.
   *
   * @return self
   *   The extracted totals, zeroed where the transcript reports none.
   */
  public static function fromTranscript(string $jsonl): self {
    $result = self::lastResult($jsonl);

    $usage = is_array($result['usage'] ?? NULL) ? $result['usage'] : [];

    return new self(
      self::int($usage['input_tokens'] ?? NULL),
      self::int($usage['output_tokens'] ?? NULL),
      self::int($result['num_turns'] ?? NULL),
      self::float($result['total_cost_usd'] ?? NULL),
    );
  }

  /**
   * Finds the last `result` event in a stream-json transcript.
   *
   * @param string $jsonl
   *   The transcript, one JSON object per line.
   *
   * @return array<string, mixed>
   *   The decoded result event, or an empty array when there is none.
   */
  protected static function lastResult(string $jsonl): array {
    $found = [];

    foreach (preg_split('/\R/', trim($jsonl)) ?: [] as $line) {
      if (trim($line) === '') {
        continue;
      }

      $decoded = json_decode($line, TRUE);

      if (is_array($decoded) && ($decoded['type'] ?? NULL) === 'result') {
        $found = $decoded;
      }
    }

    return $found;
  }

  /**
   * Coerces a usage value to a non-negative integer.
   *
   * @param mixed $value
   *   The raw value.
   *
   * @return int
   *   The integer value, or zero when it is not a usable number.
   */
  protected static function int(mixed $value): int {
    return is_int($value) || (is_float($value) && $value >= 0) ? max(0, (int) $value) : 0;
  }

  /**
   * Coerces a usage value to a non-negative float.
   *
   * @param mixed $value
   *   The raw value.
   *
   * @return float
   *   The float value, or zero when it is not a usable number.
   */
  protected static function float(mixed $value): float {
    return (is_int($value) || is_float($value)) && $value >= 0 ? (float) $value : 0.0;
  }

}
