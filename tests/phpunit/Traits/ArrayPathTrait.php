<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Traits;

/**
 * Trait ArrayPathTrait.
 *
 * Walks nested offsets of decoded JSON documents with failing assertions at
 * every step, so tests can reach deep values without unchecked offset access.
 *
 * @mixin \PHPUnit\Framework\TestCase
 */
trait ArrayPathTrait {

  /**
   * Returns the value at a nested path, failing when any step is missing.
   *
   * @param array<mixed> $data
   *   The decoded document.
   * @param string|int ...$keys
   *   The offsets to walk, in order.
   *
   * @return mixed
   *   The value at the path.
   */
  protected function path(array $data, string|int ...$keys): mixed {
    $value = $data;

    foreach ($keys as $key) {
      if (!is_array($value) || !array_key_exists($key, $value)) {
        $this->fail(sprintf("Missing path '%s'.", implode('.', array_map(strval(...), $keys))));
      }

      $value = $value[$key];
    }

    return $value;
  }

  /**
   * Returns the array at a nested path, failing when it is not an array.
   *
   * @param array<mixed> $data
   *   The decoded document.
   * @param string|int ...$keys
   *   The offsets to walk, in order.
   *
   * @return array<mixed>
   *   The array at the path.
   */
  protected function pathArray(array $data, string|int ...$keys): array {
    $value = $this->path($data, ...$keys);

    if (!is_array($value)) {
      $this->fail(sprintf("Expected an array at path '%s'.", implode('.', array_map(strval(...), $keys))));
    }

    return $value;
  }

  /**
   * Returns the string at a nested path, failing when it is not a string.
   *
   * @param array<mixed> $data
   *   The decoded document.
   * @param string|int ...$keys
   *   The offsets to walk, in order.
   *
   * @return string
   *   The string at the path.
   */
  protected function pathString(array $data, string|int ...$keys): string {
    $value = $this->path($data, ...$keys);

    if (!is_string($value)) {
      $this->fail(sprintf("Expected a string at path '%s'.", implode('.', array_map(strval(...), $keys))));
    }

    return $value;
  }

}
