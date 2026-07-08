<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Contract;

/**
 * One assertion's outcome, carrying the evidence that makes it debuggable.
 *
 * Every contract and custom check yields a result with a stable id, the human
 * label that named the behaviour, a pass/fail verdict, the evidence (the
 * matched command, tool, or skill - or the empty string when asserting an
 * absence), and a message stating the outcome and fix direction. Carrying the
 * evidence is the point: a failure is understood from the report, without
 * re-running the transcript.
 */
final readonly class CheckResult {

  /**
   * Constructs a CheckResult.
   *
   * @param string $id
   *   The stable check id, e.g. `contract.commands.required`.
   * @param string $label
   *   The human label the contract gave the behaviour.
   * @param bool $pass
   *   TRUE when the assertion holds.
   * @param string $evidence
   *   The matched command, tool, or skill; empty when asserting an absence.
   * @param string $message
   *   The human-readable outcome and fix direction.
   */
  public function __construct(
    public string $id,
    public string $label,
    public bool $pass,
    public string $evidence,
    public string $message,
  ) {}

  /**
   * Creates a passing result.
   *
   * @param string $id
   *   The stable check id.
   * @param string $label
   *   The human label.
   * @param string $evidence
   *   The matched command, tool, or skill; empty when asserting an absence.
   * @param string $message
   *   The human-readable outcome.
   *
   * @return self
   *   The passing result.
   */
  public static function pass(string $id, string $label, string $evidence, string $message): self {
    return new self($id, $label, TRUE, $evidence, $message);
  }

  /**
   * Creates a failing result.
   *
   * @param string $id
   *   The stable check id.
   * @param string $label
   *   The human label.
   * @param string $evidence
   *   The offending command, tool, or skill; empty when asserting an absence.
   * @param string $message
   *   The human-readable outcome and fix direction.
   *
   * @return self
   *   The failing result.
   */
  public static function fail(string $id, string $label, string $evidence, string $message): self {
    return new self($id, $label, FALSE, $evidence, $message);
  }

  /**
   * Returns the result as a plain array for machine output.
   *
   * @return array{id: string, label: string, pass: bool, evidence: string, message: string}
   *   The result as id, label, pass, evidence, and message keys.
   */
  public function toArray(): array {
    return [
      'id' => $this->id,
      'label' => $this->label,
      'pass' => $this->pass,
      'evidence' => $this->evidence,
      'message' => $this->message,
    ];
  }

}
