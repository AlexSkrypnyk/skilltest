<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Config;

/**
 * Safe accessors that narrow the mixed values a YAML parse returns.
 *
 * Configuration data arrives as untyped nested arrays; these helpers turn a
 * traversal or coercion into a single, typed call so every consumer narrows
 * the same way instead of scattering is_array/is_string guards.
 */
final class Data {

  /**
   * Returns a value when it is an array, otherwise an empty array.
   *
   * @param mixed $value
   *   The value to narrow.
   *
   * @return array<mixed>
   *   The array, or an empty array.
   */
  public static function toArray(mixed $value): array {
    return is_array($value) ? $value : [];
  }

  /**
   * Traverses nested arrays by key, returning the leaf or NULL.
   *
   * @param array<mixed> $data
   *   The data to traverse.
   * @param string ...$keys
   *   The successive keys to descend.
   *
   * @return mixed
   *   The value at the path, or NULL when any level is missing or not an array.
   */
  public static function get(array $data, string ...$keys): mixed {
    $cursor = $data;

    foreach ($keys as $key) {
      if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
        return NULL;
      }

      $cursor = $cursor[$key];
    }

    return $cursor;
  }

  /**
   * Coerces a scalar to a string, or returns NULL for non-scalars.
   *
   * @param mixed $value
   *   The value to coerce.
   *
   * @return string|null
   *   The string form of a string, int, or float; NULL otherwise.
   */
  public static function toStringOrNull(mixed $value): ?string {
    if (is_string($value)) {
      return $value;
    }

    if (is_int($value) || is_float($value)) {
      return (string) $value;
    }

    return NULL;
  }

  /**
   * Coerces a value to an int, or returns NULL.
   *
   * @param mixed $value
   *   The value to coerce.
   *
   * @return int|null
   *   The int form of an int or numeric string; NULL otherwise.
   */
  public static function toIntOrNull(mixed $value): ?int {
    if (is_int($value)) {
      return $value;
    }

    if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
      return (int) $value;
    }

    return NULL;
  }

  /**
   * Coerces a value to a float, or returns NULL.
   *
   * @param mixed $value
   *   The value to coerce.
   *
   * @return float|null
   *   The float form of an int, float, or numeric string; NULL otherwise.
   */
  public static function toFloatOrNull(mixed $value): ?float {
    if (is_int($value) || is_float($value)) {
      return (float) $value;
    }

    if (is_string($value) && is_numeric($value)) {
      return (float) $value;
    }

    return NULL;
  }

  /**
   * Normalises a scalar or list into a list of strings.
   *
   * A lone scalar becomes a single-element list; a list keeps its scalar
   * items (coerced to strings) and drops anything non-scalar.
   *
   * @param mixed $value
   *   The value to normalise.
   *
   * @return string[]
   *   The list of strings.
   */
  public static function toStringList(mixed $value): array {
    if (!is_array($value)) {
      $single = self::toStringOrNull($value);

      return $single === NULL ? [] : [$single];
    }

    $out = [];

    foreach ($value as $item) {
      $string = self::toStringOrNull($item);

      if ($string !== NULL) {
        $out[] = $string;
      }
    }

    return $out;
  }

  /**
   * Normalises a mapping into an array of string keys to string values.
   *
   * Entries whose value is not a scalar are dropped, so nested structures do
   * not leak into a label-to-pattern map.
   *
   * @param mixed $value
   *   The value to normalise.
   *
   * @return array<string, string>
   *   The string-to-string map.
   */
  public static function toStringMap(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }

    $out = [];

    foreach ($value as $key => $item) {
      $string = self::toStringOrNull($item);

      if ($string !== NULL) {
        $out[(string) $key] = $string;
      }
    }

    return $out;
  }

  /**
   * Normalises a value into a list of the arrays it contains.
   *
   * Non-array items are dropped, so a mixed list yields only its mappings -
   * the shape hooks, tasks, and checks all take.
   *
   * @param mixed $value
   *   The value to normalise.
   *
   * @return array<int, array<mixed>>
   *   The list of arrays.
   */
  public static function toArrayList(mixed $value): array {
    $out = [];

    foreach (self::toArray($value) as $item) {
      if (is_array($item)) {
        $out[] = $item;
      }
    }

    return $out;
  }

}
