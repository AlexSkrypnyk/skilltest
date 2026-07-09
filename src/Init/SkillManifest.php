<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Init;

use AlexSkrypnyk\SkillTest\Config\Data;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The parsed shape of a `SKILL.md`: its frontmatter and its body.
 *
 * `init` reads exactly what a scaffold needs from a skill file - the declared
 * name, the one-line description, and the allowed tools - and hands the body
 * on for optional AI drafting. Parsing is pure (string in, value out) so it is
 * exercised without touching the filesystem, and it degrades gracefully: a file
 * with no frontmatter, or with malformed frontmatter, still yields a manifest
 * whose body is the whole document.
 */
final readonly class SkillManifest {

  /**
   * The frontmatter key that declares the tools a skill may use.
   */
  public const string TOOLS_KEY = 'allowed-tools';

  /**
   * Constructs a SkillManifest.
   *
   * @param string|null $name
   *   The declared skill name, or NULL when the frontmatter omits it.
   * @param string|null $description
   *   The declared description, or NULL when the frontmatter omits it.
   * @param string[] $allowedTools
   *   The bare tool names declared in `allowed-tools`, deduplicated.
   * @param string $body
   *   The document body with the frontmatter block removed.
   */
  public function __construct(
    public ?string $name,
    public ?string $description,
    public array $allowedTools,
    public string $body,
  ) {}

  /**
   * Parses a `SKILL.md` document into a manifest.
   *
   * @param string $contents
   *   The raw file contents.
   *
   * @return self
   *   The parsed manifest.
   */
  public static function fromString(string $contents): self {
    [$frontmatter, $body] = self::split($contents);
    $data = self::parse($frontmatter);

    return new self(
      Data::toStringOrNull(Data::get($data, 'name')),
      Data::toStringOrNull(Data::get($data, 'description')),
      self::tools($data),
      $body,
    );
  }

  /**
   * Splits a document into its frontmatter block and body.
   *
   * @param string $contents
   *   The raw file contents.
   *
   * @return array{0: string, 1: string}
   *   The frontmatter (empty when there is none) and the body.
   */
  protected static function split(string $contents): array {
    $normalised = str_replace("\r\n", "\n", $contents);

    if (!str_starts_with($normalised, "---\n")) {
      return ['', $contents];
    }

    $lines = explode("\n", substr($normalised, 4));
    $frontmatter = [];
    $body = [];
    $closed = FALSE;

    foreach ($lines as $line) {
      if (!$closed && $line === '---') {
        $closed = TRUE;

        continue;
      }

      if ($closed) {
        $body[] = $line;

        continue;
      }

      $frontmatter[] = $line;
    }

    if (!$closed) {
      return ['', $contents];
    }

    return [implode("\n", $frontmatter), implode("\n", $body)];
  }

  /**
   * Parses a frontmatter block into an array, tolerating malformed YAML.
   *
   * @param string $frontmatter
   *   The frontmatter block.
   *
   * @return array<mixed>
   *   The parsed mapping, or an empty array when it is empty or malformed.
   */
  protected static function parse(string $frontmatter): array {
    if (trim($frontmatter) === '') {
      return [];
    }

    try {
      return Data::toArray(Yaml::parse($frontmatter));
    }
    catch (ParseException) {
      return [];
    }
  }

  /**
   * Extracts the bare, deduplicated tool names from the frontmatter.
   *
   * `allowed-tools` may be a YAML list or a comma-separated string, and each
   * entry may carry a permission scope in parentheses
   * (`Bash(agent-browser:*)`); only the leading tool name is kept.
   *
   * @param array<mixed> $data
   *   The parsed frontmatter.
   *
   * @return string[]
   *   The tool names, in declared order, without duplicates.
   */
  protected static function tools(array $data): array {
    $raw = Data::get($data, self::TOOLS_KEY);
    $tokens = is_array($raw) ? Data::toStringList($raw) : self::splitList(Data::toStringOrNull($raw));

    $tools = [];

    foreach ($tokens as $token) {
      $name = trim($token);
      $scope = strpos($name, '(');

      if ($scope !== FALSE) {
        $name = trim(substr($name, 0, $scope));
      }

      if ($name !== '' && !in_array($name, $tools, TRUE)) {
        $tools[] = $name;
      }
    }

    return $tools;
  }

  /**
   * Splits a comma-separated tool string into raw tokens.
   *
   * @param string|null $value
   *   The raw string value, or NULL when the key is absent.
   *
   * @return string[]
   *   The comma-separated tokens, or an empty list when there is no value.
   */
  protected static function splitList(?string $value): array {
    if ($value === NULL || trim($value) === '') {
      return [];
    }

    return explode(',', $value);
  }

}
