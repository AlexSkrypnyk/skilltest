<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Structure;

/**
 * One structure check's outcome for one skill.
 *
 * Unlike a security finding, which only ever records a failure, a structure
 * check reports a verdict for every skill it runs against: it passed, it
 * failed, or it was suppressed. Suppression is a first-class state - a skill's
 * `eval.yaml` may switch a check off with a written reason, and that reason
 * travels with the result so a report can show the check as deliberately
 * suppressed rather than silently absent. Failures carry the offending file,
 * line, and evidence so the report is debuggable without re-running.
 */
final readonly class StructureResult {

  /**
   * The check ran and the skill satisfied it.
   */
  public const string PASS = 'pass';

  /**
   * The check ran and the skill failed it.
   */
  public const string FAIL = 'fail';

  /**
   * The check was switched off for this skill with a written reason.
   */
  public const string SUPPRESSED = 'suppressed';

  /**
   * Constructs a StructureResult.
   *
   * @param string $check
   *   The check id that produced this result, e.g. `structure.frontmatter`.
   * @param string $skill
   *   The skill name the check ran against.
   * @param string $status
   *   One of {@see self::PASS}, {@see self::FAIL}, {@see self::SUPPRESSED}.
   * @param string $message
   *   The human-readable outcome and, on failure, the fix direction.
   * @param string $file
   *   The offending file relative to the repository root, or an empty string.
   * @param int $line
   *   The 1-based offending line, or 0 when the result names no single line.
   * @param string $evidence
   *   The offending content, or an empty string.
   * @param string|null $reason
   *   The suppression reason when suppressed, NULL otherwise.
   */
  public function __construct(
    public string $check,
    public string $skill,
    public string $status,
    public string $message,
    public string $file = '',
    public int $line = 0,
    public string $evidence = '',
    public ?string $reason = NULL,
  ) {}

  /**
   * Creates a passing result.
   *
   * @param string $check
   *   The check id.
   * @param string $skill
   *   The skill name.
   * @param string $message
   *   The human-readable outcome.
   *
   * @return self
   *   The passing result.
   */
  public static function pass(string $check, string $skill, string $message): self {
    return new self($check, $skill, self::PASS, $message);
  }

  /**
   * Creates a failing result.
   *
   * @param string $check
   *   The check id.
   * @param string $skill
   *   The skill name.
   * @param string $message
   *   The human-readable outcome and fix direction.
   * @param string $file
   *   The offending file relative to the repository root, or an empty string.
   * @param int $line
   *   The 1-based offending line, or 0 when the result names no single line.
   * @param string $evidence
   *   The offending content, or an empty string.
   *
   * @return self
   *   The failing result.
   */
  public static function fail(string $check, string $skill, string $message, string $file = '', int $line = 0, string $evidence = ''): self {
    return new self($check, $skill, self::FAIL, $message, $file, $line, $evidence);
  }

  /**
   * Creates a suppressed result carrying the written reason.
   *
   * @param string $check
   *   The check id.
   * @param string $skill
   *   The skill name.
   * @param string $reason
   *   The written suppression reason.
   *
   * @return self
   *   The suppressed result.
   */
  public static function suppressed(string $check, string $skill, string $reason): self {
    return new self($check, $skill, self::SUPPRESSED, sprintf('suppressed: %s', $reason), reason: $reason);
  }

  /**
   * Whether this result fails the gate.
   *
   * @return bool
   *   TRUE only when the status is {@see self::FAIL}.
   */
  public function failed(): bool {
    return $this->status === self::FAIL;
  }

  /**
   * Renders the result as a single scannable line.
   *
   * @return string
   *   The rendered result.
   */
  public function render(): string {
    $location = match (TRUE) {
      $this->file === '' => $this->skill,
      $this->line > 0 => sprintf('%s:%d', $this->file, $this->line),
      default => $this->file,
    };
    $line = sprintf('%s %s %s - %s', $this->check, strtoupper($this->status), $location, $this->message);

    return $this->evidence === '' ? $line : sprintf('%s [%s]', $line, $this->evidence);
  }

  /**
   * Returns the result as a plain array for machine output.
   *
   * @return array{check: string, skill: string, status: string, message: string, file: string, line: int, evidence: string, reason: string|null}
   *   The result fields.
   */
  public function toArray(): array {
    return [
      'check' => $this->check,
      'skill' => $this->skill,
      'status' => $this->status,
      'message' => $this->message,
      'file' => $this->file,
      'line' => $this->line,
      'evidence' => $this->evidence,
      'reason' => $this->reason,
    ];
  }

}
