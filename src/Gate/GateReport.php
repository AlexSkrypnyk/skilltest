<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Gate;

/**
 * The outcome of one gate comparison: the rates, the findings, and the verdict.
 *
 * Carries the two pass rates it compared and the tolerance it applied,
 * plus every finding in the order the gate produced them (regression, golden,
 * minimal-model, drift). The verdict is derived, not stored: the gate fails
 * the moment any finding fails, so a warning-only run still passes. This is
 * the single object every gate renderer consumes.
 */
final readonly class GateReport {

  /**
   * Constructs a GateReport.
   *
   * @param float $baselineRate
   *   The baseline aggregate pass rate, in the range 0..1.
   * @param float $currentRate
   *   The current aggregate pass rate, in the range 0..1.
   * @param float $maxRegression
   *   The tolerated drop, in percentage points.
   * @param \AlexSkrypnyk\SkillTest\Gate\GateFinding[] $findings
   *   The findings, in gate order.
   */
  public function __construct(
    public float $baselineRate,
    public float $currentRate,
    public float $maxRegression,
    public array $findings,
  ) {}

  /**
   * Whether the gate failed.
   *
   * @return bool
   *   TRUE when at least one finding is failing.
   */
  public function failed(): bool {
    foreach ($this->findings as $finding) {
      if ($finding->failed()) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * The drop from the baseline to the current rate, in percentage points.
   *
   * @return float
   *   The drop; negative when the current run improved on the baseline.
   */
  public function drop(): float {
    return ($this->baselineRate - $this->currentRate) * 100;
  }

  /**
   * The findings that fail the gate.
   *
   * @return \AlexSkrypnyk\SkillTest\Gate\GateFinding[]
   *   The failing findings, in gate order.
   */
  public function failingFindings(): array {
    return array_values(array_filter($this->findings, static fn(GateFinding $finding): bool => $finding->failed()));
  }

  /**
   * The findings that warn without failing the gate.
   *
   * @return \AlexSkrypnyk\SkillTest\Gate\GateFinding[]
   *   The warning findings, in gate order.
   */
  public function warningFindings(): array {
    return array_values(array_filter($this->findings, static fn(GateFinding $finding): bool => !$finding->failed()));
  }

}
