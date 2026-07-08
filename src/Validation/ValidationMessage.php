<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Validation;

/**
 * A single validation finding: an error or a warning against one config key.
 *
 * Every finding names the file and (when known) the offending key, so the
 * reader always knows where to look. Errors fail validation; warnings are
 * reported but do not.
 */
final class ValidationMessage {

  /**
   * Constructs a ValidationMessage.
   *
   * @param bool $isError
   *   TRUE for an error, FALSE for a warning.
   * @param string $file
   *   The configuration file the finding relates to.
   * @param string $pointer
   *   A dotted pointer to the offending key, or an empty string.
   * @param string $message
   *   The human-readable description.
   */
  public function __construct(
    public readonly bool $isError,
    public readonly string $file,
    public readonly string $pointer,
    public readonly string $message,
  ) {}

  /**
   * Creates an error finding.
   *
   * @param string $file
   *   The configuration file the finding relates to.
   * @param string $pointer
   *   A dotted pointer to the offending key, or an empty string.
   * @param string $message
   *   The human-readable description.
   *
   * @return self
   *   The error finding.
   */
  public static function error(string $file, string $pointer, string $message): self {
    return new self(TRUE, $file, $pointer, $message);
  }

  /**
   * Creates a warning finding.
   *
   * @param string $file
   *   The configuration file the finding relates to.
   * @param string $pointer
   *   A dotted pointer to the offending key, or an empty string.
   * @param string $message
   *   The human-readable description.
   *
   * @return self
   *   The warning finding.
   */
  public static function warning(string $file, string $pointer, string $message): self {
    return new self(FALSE, $file, $pointer, $message);
  }

  /**
   * Renders the finding as a single line naming the file and key.
   *
   * @return string
   *   The rendered finding.
   */
  public function render(): string {
    $location = $this->pointer === '' ? $this->file : $this->file . ': ' . $this->pointer;

    return $location . ' - ' . $this->message;
  }

  /**
   * Returns the finding as a plain array for machine output.
   *
   * @return array<string, string>
   *   The finding as file, pointer, and message keys.
   */
  public function toArray(): array {
    return [
      'file' => $this->file,
      'pointer' => $this->pointer,
      'message' => $this->message,
    ];
  }

}
