<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Config;

/**
 * One `paths.exclude` entry: a skill exempted from the coverage gate.
 *
 * Each exemption names a skill and must carry a reason; a reason-less exemption
 * is a configuration error, so an exclusion is never an unexplained hole in the
 * coverage grid.
 */
final readonly class ExcludeEntry {

  /**
   * Constructs an ExcludeEntry.
   *
   * @param string $skill
   *   The excluded skill name, or an empty string when none was given.
   * @param string|null $reason
   *   The exemption reason, or NULL when none (or a blank one) was given.
   */
  public function __construct(
    public string $skill,
    public ?string $reason,
  ) {}

  /**
   * Builds an entry from a raw `paths.exclude` item.
   *
   * A bare string is read as a skill name with no reason; a mapping reads its
   * `skill` and `reason` keys. A blank reason is normalised to NULL so absent
   * and empty are treated the same by the required-reason check.
   *
   * @param mixed $value
   *   The raw item: a skill-name string, or a mapping with skill and reason.
   *
   * @return self
   *   The parsed entry.
   */
  public static function fromValue(mixed $value): self {
    if (is_array($value)) {
      $skill = Data::toStringOrNull(Data::get($value, 'skill')) ?? '';
      $reason = Data::toStringOrNull(Data::get($value, 'reason'));

      return new self($skill, ($reason === NULL || trim($reason) === '') ? NULL : $reason);
    }

    return new self(Data::toStringOrNull($value) ?? '', NULL);
  }

}
