<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Security;

/**
 * A single security finding: one danger pattern matched in one shipped file.
 *
 * Every finding names the check that fired, the file and line it fired on, and
 * the offending line as evidence, so a reader can see exactly what tripped the
 * scan and where. Security findings are always errors - there is no warning
 * variant, so nothing a skill declares can downgrade one.
 */
final readonly class SecurityFinding {

  /**
   * Constructs a SecurityFinding.
   *
   * @param string $check
   *   The check id that fired, e.g. `security.curl-pipe-shell`.
   * @param string $file
   *   The offending file, relative to the repository root.
   * @param int $line
   *   The 1-based line number the pattern matched on.
   * @param string $evidence
   *   The matched line, trimmed.
   * @param string $description
   *   A human-readable description of what the pattern flags.
   */
  public function __construct(
    public string $check,
    public string $file,
    public int $line,
    public string $evidence,
    public string $description,
  ) {}

  /**
   * Renders the finding as a single line naming the check, location, and match.
   *
   * @return string
   *   The rendered finding.
   */
  public function render(): string {
    return sprintf('%s %s:%d - %s [%s]', $this->check, $this->file, $this->line, $this->description, $this->evidence);
  }

  /**
   * Returns the finding as a plain array for machine output.
   *
   * @return array{check: string, file: string, line: int, evidence: string, description: string}
   *   The finding fields.
   */
  public function toArray(): array {
    return [
      'check' => $this->check,
      'file' => $this->file,
      'line' => $this->line,
      'evidence' => $this->evidence,
      'description' => $this->description,
    ];
  }

}
