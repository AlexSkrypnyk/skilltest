<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Config;

/**
 * A parsed configuration schema version in MAJOR.MINOR form.
 *
 * A missing version means the current version. Same-major minor differences
 * are readable (unknown keys are warned about, never fatal); a different major
 * is rejected by the loader with a pointer to the migrate command.
 */
final readonly class SchemaVersion implements \Stringable {

  /**
   * The major schema version this tool reads.
   */
  public const int CURRENT_MAJOR = 1;

  /**
   * The minor schema version this tool reads.
   */
  public const int CURRENT_MINOR = 0;

  /**
   * Constructs a SchemaVersion.
   *
   * @param int $major
   *   The major component.
   * @param int $minor
   *   The minor component.
   */
  public function __construct(
    public int $major,
    public int $minor,
  ) {}

  /**
   * Parses a raw version value into a SchemaVersion.
   *
   * @param string|int|float|null $raw
   *   The raw value from the config file. NULL or an empty string means the
   *   current version.
   *
   * @return self
   *   The parsed version.
   *
   * @throws \InvalidArgumentException
   *   When the value is present but not MAJOR or MAJOR.MINOR.
   */
  public static function parse(string|int|float|null $raw): self {
    if ($raw === NULL || $raw === '') {
      return new self(self::CURRENT_MAJOR, self::CURRENT_MINOR);
    }

    $normalised = trim((string) $raw);

    if (preg_match('/^(\d+)(?:\.(\d+))?$/', $normalised, $matches) !== 1) {
      throw new \InvalidArgumentException(sprintf('Invalid schema version "%s"; expected MAJOR or MAJOR.MINOR', $normalised));
    }

    return new self((int) $matches[1], isset($matches[2]) ? (int) $matches[2] : 0);
  }

  /**
   * Whether this version's major matches the version this tool reads.
   *
   * @return bool
   *   TRUE when the major is readable by this tool.
   */
  public function isCurrentMajor(): bool {
    return $this->major === self::CURRENT_MAJOR;
  }

  /**
   * Renders the version as a MAJOR.MINOR string.
   *
   * @return string
   *   The version string.
   */
  public function __toString(): string {
    return $this->major . '.' . $this->minor;
  }

}
