<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Structure;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A parsed `SKILL.md`: its YAML frontmatter and the body beneath it.
 *
 * A skill document opens with a `---` fenced YAML block followed by the
 * markdown body. This value object splits the two once so every structure
 * check works from the same parse: the frontmatter as data (for the name and
 * description checks), the raw content (for line-scanned checks), and the body
 * with the line it starts on (so body-scoped checks report file line numbers,
 * not body-relative ones). Malformed or missing frontmatter is recorded as a
 * flag rather than thrown, because "the frontmatter does not parse" is itself a
 * check outcome.
 */
final readonly class SkillDocument {

  /**
   * The fence line that opens and closes the frontmatter block.
   */
  public const string FENCE = '---';

  /**
   * Constructs a SkillDocument.
   *
   * @param string $content
   *   The full `SKILL.md` content.
   * @param bool $frontmatterPresent
   *   Whether an opening frontmatter fence was found on the first line.
   * @param bool $frontmatterValid
   *   Whether the frontmatter block closed and parsed to a mapping.
   * @param array<mixed> $frontmatter
   *   The parsed frontmatter mapping, or an empty array when invalid or absent.
   * @param string $body
   *   The content beneath the frontmatter (the whole content when absent).
   * @param int $bodyStartLine
   *   The 1-based line in the file where the body begins.
   */
  public function __construct(
    public string $content,
    public bool $frontmatterPresent,
    public bool $frontmatterValid,
    public array $frontmatter,
    public string $body,
    public int $bodyStartLine,
  ) {}

  /**
   * Reads and parses a `SKILL.md` from disk.
   *
   * @param string $path
   *   The absolute `SKILL.md` path.
   *
   * @return self
   *   The parsed document; an unreadable file yields empty, absent frontmatter.
   */
  public static function fromFile(string $path): self {
    $content = @file_get_contents($path);

    return self::fromString($content === FALSE ? '' : $content);
  }

  /**
   * Parses a `SKILL.md` from its content.
   *
   * @param string $content
   *   The full `SKILL.md` content.
   *
   * @return self
   *   The parsed document.
   */
  public static function fromString(string $content): self {
    $lines = explode("\n", $content);

    if (rtrim($lines[0], "\r") !== self::FENCE) {
      return new self($content, FALSE, FALSE, [], $content, 1);
    }

    $close = self::closingFence($lines);

    if ($close === NULL) {
      // The block opened but never closed: there is no valid frontmatter and
      // no body to scan, so treat the whole file as unparsed frontmatter.
      return new self($content, TRUE, FALSE, [], '', 1);
    }

    $raw = implode("\n", array_slice($lines, 1, $close - 1));
    $body = implode("\n", array_slice($lines, $close + 1));
    $parsed = self::parse($raw);

    return new self($content, TRUE, $parsed !== NULL, $parsed ?? [], $body, $close + 2);
  }

  /**
   * Finds the index of the closing fence, searching after the opening one.
   *
   * @param string[] $lines
   *   The file split into lines.
   *
   * @return int|null
   *   The 0-based index of the closing fence, or NULL when there is none.
   */
  protected static function closingFence(array $lines): ?int {
    $count = count($lines);

    for ($index = 1; $index < $count; $index++) {
      if (rtrim($lines[$index], "\r") === self::FENCE) {
        return $index;
      }
    }

    return NULL;
  }

  /**
   * Parses the raw frontmatter block into a mapping.
   *
   * @param string $raw
   *   The raw frontmatter text between the fences.
   *
   * @return array<mixed>|null
   *   The parsed mapping, or NULL when it does not parse to one.
   */
  protected static function parse(string $raw): ?array {
    // An empty frontmatter block is a valid, if empty, mapping - not a parse
    // failure. Yaml::parse('') returns NULL, so handle it before parsing.
    if (trim($raw) === '') {
      return [];
    }

    try {
      $parsed = Yaml::parse($raw);
    }
    catch (ParseException) {
      return NULL;
    }

    if (!is_array($parsed)) {
      return NULL;
    }

    // Frontmatter must be a mapping; a non-empty YAML sequence is not valid
    // frontmatter, only a mapping (or an empty document) is.
    if ($parsed !== [] && array_is_list($parsed)) {
      return NULL;
    }

    return $parsed;
  }

}
