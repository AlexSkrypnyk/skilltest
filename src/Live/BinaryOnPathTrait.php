<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

/**
 * Resolves an executable from an environment override or a PATH lookup.
 *
 * The PATH is passed in explicitly so the helper reads no ambient state and
 * stays a pure lookup.
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

  /**
   * Resolves a binary as the environment override or a PATH lookup.
   *
   * @param string $override_var
   *   The environment variable that may name the binary or command prefix.
   * @param string $default_binary
   *   The binary name searched for on PATH when the override is unset.
   *
   * @return string|null
   *   The override value, the discovered path, or NULL when neither exists.
   */
  protected function overrideOrOnPath(string $override_var, string $default_binary): ?string {
    $override = trim((string) ($this->env[$override_var] ?? ''));

    if ($override !== '') {
      return $override;
    }

    return self::onPath((string) ($this->env['PATH'] ?? ''), $default_binary);
  }

}
