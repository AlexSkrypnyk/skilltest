<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Validation;

/**
 * An accumulating collection of validation findings.
 *
 * Coherence and schema checks append findings here rather than throwing, so a
 * single run reports every problem at once instead of stopping at the first.
 */
final class ValidationResult {

  /**
   * The accumulated findings.
   *
   * @var \AlexSkrypnyk\SkillTest\Validation\ValidationMessage[]
   */
  protected array $messages = [];

  /**
   * Appends a finding.
   *
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationMessage $message
   *   The finding to append.
   */
  public function add(ValidationMessage $message): void {
    $this->messages[] = $message;
  }

  /**
   * Appends an error finding.
   *
   * @param string $file
   *   The configuration file the finding relates to.
   * @param string $pointer
   *   A dotted pointer to the offending key, or an empty string.
   * @param string $message
   *   The human-readable description.
   */
  public function addError(string $file, string $pointer, string $message): void {
    $this->messages[] = ValidationMessage::error($file, $pointer, $message);
  }

  /**
   * Appends a warning finding.
   *
   * @param string $file
   *   The configuration file the finding relates to.
   * @param string $pointer
   *   A dotted pointer to the offending key, or an empty string.
   * @param string $message
   *   The human-readable description.
   */
  public function addWarning(string $file, string $pointer, string $message): void {
    $this->messages[] = ValidationMessage::warning($file, $pointer, $message);
  }

  /**
   * Returns every finding, errors and warnings alike.
   *
   * @return \AlexSkrypnyk\SkillTest\Validation\ValidationMessage[]
   *   All findings, in the order added.
   */
  public function messages(): array {
    return $this->messages;
  }

  /**
   * Returns only the error findings.
   *
   * @return \AlexSkrypnyk\SkillTest\Validation\ValidationMessage[]
   *   The error findings.
   */
  public function errors(): array {
    return array_values(array_filter($this->messages, static fn(ValidationMessage $message): bool => $message->isError));
  }

  /**
   * Returns only the warning findings.
   *
   * @return \AlexSkrypnyk\SkillTest\Validation\ValidationMessage[]
   *   The warning findings.
   */
  public function warnings(): array {
    return array_values(array_filter($this->messages, static fn(ValidationMessage $message): bool => !$message->isError));
  }

  /**
   * Whether any error finding was recorded.
   *
   * @return bool
   *   TRUE when at least one error is present.
   */
  public function hasErrors(): bool {
    foreach ($this->messages as $message) {
      if ($message->isError) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
