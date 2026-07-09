<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Judge\JudgeCriterion;

/**
 * One live trial's outcome: its verdict, graded checks, and run metrics.
 *
 * A trial is a single headless run of a task on one model. It passes only when
 * every contract and custom check it was graded against passed - a non-zero
 * agent exit, a timeout, a judge failure, or a responder failure is folded in
 * as a failing check so a broken run can never be a passing trial. When the
 * skill declares a rubric the judge's per-criterion verdict and the pinned
 * judge model travel with the trial too, and an interactive task records how
 * its conversation ended and how many follow-ups it took. The transcript is
 * carried verbatim (so it can be persisted as an artifact) alongside the
 * relative path the results document references it by, and the token, turn,
 * duration, and cost metrics make the price of the trial a number in the
 * report.
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
   * @param \AlexSkrypnyk\SkillTest\Judge\JudgeCriterion[] $criteria
   *   The judge's per-criterion verdict, empty when the skill declares no
   *   rubric.
   * @param string|null $judgeModel
   *   The pinned judge model id, or NULL when the skill declares no rubric.
   * @param array<string, string> $mockLogs
   *   Each mocked server's call log, keyed by the document-relative artifact
   *   path it is persisted under; empty when the task declared no mocks or made
   *   no mocked calls.
   * @param \AlexSkrypnyk\SkillTest\Live\ResponderOutcome|null $responderOutcome
   *   How the interactive conversation ended, or NULL for a single-shot task.
   * @param int $followups
   *   The number of responder replies sent, zero for a single-shot task.
   * @param bool $cached
   *   TRUE when the trial was replayed from the cache rather than executed
   *   live; a cache hit reuses a prior verdict without spending a token.
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
    public array $criteria = [],
    public ?string $judgeModel = NULL,
    public array $mockLogs = [],
    public ?ResponderOutcome $responderOutcome = NULL,
    public int $followups = 0,
    public bool $cached = FALSE,
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
    $row = [
      'trial' => $this->number,
      'pass' => $this->pass,
      'cached' => $this->cached,
      'contract' => array_map(static fn(CheckResult $result): array => $result->toCheckRow(), $this->checks),
      'judge' => array_map(static fn(JudgeCriterion $criterion): array => $criterion->toArray(), $this->criteria),
      'unknowns' => count(array_filter($this->criteria, static fn(JudgeCriterion $criterion): bool => $criterion->unknown)),
      'judge_model' => $this->judgeModel,
      'duration_ms' => $this->durationMs,
      'turns' => $this->turns,
      'tokens' => ['in' => $this->tokensIn, 'out' => $this->tokensOut],
      'cost_usd' => $this->cost,
      'transcript' => $this->transcriptPath,
      'mocks' => array_keys($this->mockLogs),
    ];

    // An interactive task records how its conversation ended and how many
    // follow-ups it took; a single-shot task carries no responder block.
    if ($this->responderOutcome instanceof ResponderOutcome) {
      $row['responder'] = ['outcome' => $this->responderOutcome->value, 'followups' => $this->followups];
    }

    return $row;
  }

  /**
   * Serialises the whole trial for the result cache.
   *
   * Unlike {@see toArray}, this is a lossless snapshot: every field the graded
   * trial carries - checks with their ids, criteria, the raw transcript, and
   * the mock logs - so {@see fromCache} rebuilds an identical verdict without
   * re-running the agent.
   *
   * @return array<string, mixed>
   *   The cache row.
   */
  public function toCache(): array {
    return [
      'number' => $this->number,
      'pass' => $this->pass,
      'checks' => array_map(static fn(CheckResult $result): array => $result->toArray(), $this->checks),
      'tokensIn' => $this->tokensIn,
      'tokensOut' => $this->tokensOut,
      'turns' => $this->turns,
      'cost' => $this->cost,
      'durationMs' => $this->durationMs,
      'transcript' => $this->transcript,
      'transcriptPath' => $this->transcriptPath,
      'criteria' => array_map(static fn(JudgeCriterion $criterion): array => $criterion->toArray(), $this->criteria),
      'judgeModel' => $this->judgeModel,
      'mockLogs' => $this->mockLogs,
      'responderOutcome' => $this->responderOutcome?->value,
      'followups' => $this->followups,
    ];
  }

  /**
   * Rebuilds a cached trial, flagged as a cache hit.
   *
   * @param array<mixed> $data
   *   A row produced by {@see toCache}.
   *
   * @return self
   *   The rebuilt trial, with `cached` set.
   */
  public static function fromCache(array $data): self {
    $checks = array_map(
      static fn(array $row): CheckResult => new CheckResult(
        Data::toStringOrNull(Data::get($row, 'id')) ?? '',
        Data::toStringOrNull(Data::get($row, 'label')) ?? '',
        (bool) Data::get($row, 'pass'),
        Data::toStringOrNull(Data::get($row, 'evidence')) ?? '',
        Data::toStringOrNull(Data::get($row, 'message')) ?? '',
      ),
      Data::toArrayList(Data::get($data, 'checks')),
    );

    $criteria = array_map(
      static fn(array $row): JudgeCriterion => new JudgeCriterion(
        Data::toIntOrNull(Data::get($row, 'criterion')) ?? 0,
        (bool) Data::get($row, 'pass'),
        (bool) Data::get($row, 'unknown'),
      ),
      Data::toArrayList(Data::get($data, 'criteria')),
    );

    $outcome_value = Data::toStringOrNull(Data::get($data, 'responderOutcome'));
    $responder_outcome = $outcome_value === NULL ? NULL : ResponderOutcome::tryFrom($outcome_value);

    return new self(
      Data::toIntOrNull(Data::get($data, 'number')) ?? 0,
      (bool) Data::get($data, 'pass'),
      $checks,
      Data::toIntOrNull(Data::get($data, 'tokensIn')) ?? 0,
      Data::toIntOrNull(Data::get($data, 'tokensOut')) ?? 0,
      Data::toIntOrNull(Data::get($data, 'turns')) ?? 0,
      Data::toFloatOrNull(Data::get($data, 'cost')) ?? 0.0,
      Data::toIntOrNull(Data::get($data, 'durationMs')) ?? 0,
      Data::toStringOrNull(Data::get($data, 'transcript')) ?? '',
      Data::toStringOrNull(Data::get($data, 'transcriptPath')) ?? '',
      $criteria,
      Data::toStringOrNull(Data::get($data, 'judgeModel')),
      Data::toStringMap(Data::get($data, 'mockLogs')),
      $responder_outcome,
      Data::toIntOrNull(Data::get($data, 'followups')) ?? 0,
      TRUE,
    );
  }

}
