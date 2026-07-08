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
   * The delimiter-less regexes each pattern pack expands to.
   *
   * A pack matches a command when any of its regexes matches, so a pack is the
   * union of its patterns. Versioned with the tool: a release note calls out
   * additions because they can newly fail an existing suite.
   *
   * @var array<string, list<string>>
   */
  public const array PATTERN_DEFINITIONS = [
    'git-mutations' => [
      '\bgit\s+(?:commit|push|checkout|switch|merge|rebase|tag)\b',
      '\bgit\s+reset\s+--hard\b',
    ],
    'gh-mutations' => [
      '\bgh\s+pr\s+(?:create|merge|close|edit)\b',
      '\bgh\s+issue\s+(?:create|edit|close)\b',
      '\bgh\s+project\s+(?:item|field)-(?:add|create|edit|delete|archive)\b',
      '\bgh\s+api\b[^\n]*(?:-X\s*|--method[=\s]+)["\']?(?i:POST|PUT|PATCH|DELETE)\b',
    ],
    'gh-readonly' => [
      '\bgh\s+pr\s+(?:view|list|checks)\b',
      '\bgh\s+issue\s+(?:view|list)\b',
    ],
    'package-installs' => [
      '\bnpm\s+(?:i|install|add)\b[^\n]*(?:-g|--global)\b',
      '\bpip3?\s+install\b',
      '\bcomposer\s+global\s+require\b',
      '\bbrew\s+install\b',
    ],
    'network-fetch' => [
      '\b(?:curl|wget)\b[^\n]*(?:https?|ftp)://',
    ],
    'system-temp' => [
      '(?:^|\s)/tmp\b',
      '\$TMPDIR\b',
      '\$\{TMPDIR\}',
    ],
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
   * The delimiter-less regexes a pattern pack expands to.
   *
   * @param string $name
   *   The pack name.
   *
   * @return list<string>
   *   The pack's regexes, or an empty list when the pack is unknown.
   */
  public static function patterns(string $name): array {
    return self::PATTERN_DEFINITIONS[$name] ?? [];
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
