<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

/**
 * Resolves an executable by name on a PATH string.
 *
 * Both preflights find the tool they gate on the same way - the first
 * executable of that name across the PATH entries - so the scan lives here once
 * rather than drifting apart in two copies. The PATH is passed in explicitly so
 * the helper reads no ambient state and stays a pure lookup.
 */
trait BinaryOnPathTrait {

  /**
   * Finds an executable of the given name on a PATH string.
   *
   * @param string $path
   *   The PATH environment value, its entries separated by the OS separator.
   * @param string $name
   *   The binary name.
   *
   * @return string|null
   *   The absolute path to the first executable match, or NULL when none.
   */
  protected static function onPath(string $path, string $name): ?string {
    foreach (array_filter(explode(PATH_SEPARATOR, $path), static fn(string $dir): bool => $dir !== '') as $dir) {
      $candidate = rtrim($dir, '/') . '/' . $name;

      if (is_file($candidate) && is_executable($candidate)) {
        return $candidate;
      }
    }

    return NULL;
  }

}
