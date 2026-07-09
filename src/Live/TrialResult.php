<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

use AlexSkrypnyk\SkillTest\Contract\CheckResult;

/**
 * One live trial's outcome: its verdict, graded checks, and run metrics.
 *
 * A trial is a single headless run of a task on one model. It passes only when
 * every contract and custom check it was graded against passed - a non-zero
 * agent exit or a timeout is folded in as a failing check so a broken run can
 * never be a passing trial. The transcript is carried verbatim (so it can be
 * persisted as an artifact) alongside the relative path the results document
 * references it by, and the token, turn, duration, and cost metrics make the
 * price of the trial a number in the report.
 */
final readonly class TrialResult {

  /**
   * Constructs a TrialResult.
   *
   * @param int $number
   *   The 1-based trial number within its model.
   * @param bool $pass
   *   TRUE when every graded check passed.
   * @param \AlexSkrypnyk\SkillTest\Contract\CheckResult[] $checks
   *   The contract and custom check results, in assertion order.
   * @param int $tokensIn
   *   The input token count.
   * @param int $tokensOut
   *   The output token count.
   * @param int $turns
   *   The number of agent turns.
   * @param float $cost
   *   The run cost in USD.
   * @param int $durationMs
   *   The measured wall-clock duration in milliseconds.
   * @param string $transcript
   *   The raw stream-json transcript.
   * @param string $transcriptPath
   *   The path the results document references the transcript by, relative to
   *   the run directory.
   */
  public function __construct(
    public int $number,
    public bool $pass,
    public array $checks,
    public int $tokensIn,
    public int $tokensOut,
    public int $turns,
    public float $cost,
    public int $durationMs,
    public string $transcript,
    public string $transcriptPath,
  ) {}

  /**
   * The check results that did not pass.
   *
   * @return \AlexSkrypnyk\SkillTest\Contract\CheckResult[]
   *   The failed checks, in order.
   */
  public function failures(): array {
    return array_values(array_filter($this->checks, static fn(CheckResult $result): bool => !$result->pass));
  }

  /**
   * Renders the trial as a results-document row.
   *
   * @return array<string, mixed>
   *   The trial row matching the results schema.
   */
  public function toArray(): array {
    return [
      'trial' => $this->number,
      'pass' => $this->pass,
      'contract' => array_map(static fn(CheckResult $result): array => $result->toCheckRow(), $this->checks),
      'judge' => [],
      'unknowns' => 0,
      'duration_ms' => $this->durationMs,
      'turns' => $this->turns,
      'tokens' => ['in' => $this->tokensIn, 'out' => $this->tokensOut],
      'cost_usd' => $this->cost,
      'transcript' => $this->transcriptPath,
    ];
  }

}
