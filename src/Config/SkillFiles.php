<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Config;

/**
 * Lists the real regular files a skill ships, recursively.
 *
 * Both the security scan and the structure group read every file a skill
 * bundles, so they share one walk. Symlinks are never followed: one could
 * loop back into the skill or resolve outside it, and a scan only ever
 * reasons about the skill's own real files. Results are sorted so a report
 * over them is deterministic.
 */
final class SkillFiles {

  /**
   * File extensions that mark a file as markdown.
   */
  public const array MARKDOWN_EXTENSIONS = ['md', 'markdown'];

  /**
   * Returns every regular file under a directory, recursively and sorted.
   *
   * @param string $dir
   *   The absolute directory to walk.
   *
   * @return string[]
   *   The absolute file paths, sorted for deterministic reporting.
   */
  public static function under(string $dir): array {
    $found = self::collect($dir);
    sort($found);

    return $found;
  }

  /**
   * Returns every markdown file under a directory, recursively and sorted.
   *
   * @param string $dir
   *   The absolute directory to walk.
   *
   * @return string[]
   *   The absolute markdown file paths, sorted for deterministic reporting.
   */
  public static function markdownUnder(string $dir): array {
    return array_values(array_filter(self::under($dir), self::isMarkdown(...)));
  }

  /**
   * Whether a path carries a markdown extension.
   *
   * @param string $path
   *   The file path.
   *
   * @return bool
   *   TRUE when the extension is a markdown one.
   */
  public static function isMarkdown(string $path): bool {
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::MARKDOWN_EXTENSIONS, TRUE);
  }

  /**
   * Collects regular files under a directory, recursively and unsorted.
   *
   * @param string $dir
   *   The absolute directory to walk.
   *
   * @return string[]
   *   The absolute file paths, in traversal order.
   */
  protected static function collect(string $dir): array {
    $entries = @scandir($dir);

    // @codeCoverageIgnoreStart
    if ($entries === FALSE) {
      return [];
    }
    // @codeCoverageIgnoreEnd
    $files = [];

    foreach ($entries as $entry) {
      if ($entry === '.') {
        continue;
      }
      if ($entry === '..') {
        continue;
      }

      $path = $dir . '/' . $entry;

      if (is_link($path)) {
        continue;
      }

      if (is_dir($path)) {
        foreach (self::collect($path) as $nested) {
          $files[] = $nested;
        }

        continue;
      }

      $files[] = $path;
    }

    return $files;
  }

}
