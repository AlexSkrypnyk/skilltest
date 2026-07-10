<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Gate;

/**
 * One thing the gate noticed: a regression, a golden failure, or drift.
 *
 * A finding is either failing (it fails the gate) or a warning (it is surfaced
 * but does not change the verdict), tagged with the category it belongs to so a
 * report can group and a machine can filter. The message is the human sentence
 * a reader acts on.
 */
final readonly class GateFinding {

  /**
   * The severity that fails the gate.
   */
  public const string FAIL = 'fail';

  /**
   * The severity that is surfaced without failing the gate.
   */
  public const string WARN = 'warn';

  /**
   * Constructs a GateFinding.
   *
   * @param string $severity
   *   The severity: {@see FAIL} or {@see WARN}.
   * @param string $category
   *   The finding category, e.g. `regression`, `golden`, `minimal-model`.
   * @param string $message
   *   The human-readable finding.
   */
  public function __construct(
    public string $severity,
    public string $category,
    public string $message,
  ) {}

  /**
   * Creates a failing finding.
   *
   * @param string $category
   *   The finding category.
   * @param string $message
   *   The human-readable finding.
   *
   * @return self
   *   The failing finding.
   */
  public static function fail(string $category, string $message): self {
    return new self(self::FAIL, $category, $message);
  }

  /**
   * Creates a warning finding.
   *
   * @param string $category
   *   The finding category.
   * @param string $message
   *   The human-readable finding.
   *
   * @return self
   *   The warning finding.
   */
  public static function warn(string $category, string $message): self {
    return new self(self::WARN, $category, $message);
  }

  /**
   * Whether this finding fails the gate.
   *
   * @return bool
   *   TRUE when the severity is {@see FAIL}.
   */
  public function failed(): bool {
    return $this->severity === self::FAIL;
  }

  /**
   * Renders the finding as a plain array for machine output.
   *
   * @return array{severity: string, category: string, message: string}
   *   The finding as severity, category, and message keys.
   */
  public function toArray(): array {
    return [
      'severity' => $this->severity,
      'category' => $this->category,
      'message' => $this->message,
    ];
  }

}
