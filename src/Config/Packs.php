<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Config;

/**
 * The catalog of pre-baked pattern and security packs shipped with the tool.
 *
 * A pattern position in a contract may reference a pack with `pack:<name>`
 * instead of a hand-written regex; the security group enables named packs by
 * listing them. Coherence validation checks that every referenced pack name
 * exists in this catalog.
 */
final class Packs {

  /**
   * The prefix that marks a pattern value as a pack reference.
   */
  public const string PREFIX = 'pack:';

  /**
   * Packs usable in any command pattern position.
   */
  public const array PATTERN = [
    'git-mutations',
    'gh-mutations',
    'gh-readonly',
    'package-installs',
    'network-fetch',
    'system-temp',
  ];

  /**
   * Packs usable in the security group's `packs:` list.
   */
  public const array SECURITY = [
    'baseline',
  ];

  /**
   * Extracts the pack name from a pattern value, when it is a pack reference.
   *
   * @param string $value
   *   A pattern value from a contract.
   *
   * @return string|null
   *   The pack name, or NULL when the value is a plain regex.
   */
  public static function reference(string $value): ?string {
    return str_starts_with($value, self::PREFIX) ? substr($value, strlen(self::PREFIX)) : NULL;
  }

  /**
   * Whether a name is a known pattern pack.
   *
   * @param string $name
   *   The pack name.
   *
   * @return bool
   *   TRUE when the pattern pack exists.
   */
  public static function isPatternPack(string $name): bool {
    return in_array($name, self::PATTERN, TRUE);
  }

  /**
   * Whether a name is a known security pack.
   *
   * @param string $name
   *   The pack name.
   *
   * @return bool
   *   TRUE when the security pack exists.
   */
  public static function isSecurityPack(string $name): bool {
    return in_array($name, self::SECURITY, TRUE);
  }

}
