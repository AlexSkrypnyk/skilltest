<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Traits;

/**
 * Trait JsonDecodeTrait.
 *
 * Decodes JSON command output in tests.
 *
 * @mixin \PHPUnit\Framework\TestCase
 */
trait JsonDecodeTrait {

  /**
   * Decodes a JSON command output.
   *
   * @param string $output
   *   The JSON output.
   *
   * @return array<mixed>
   *   The decoded payload.
   */
  protected function decode(string $output): array {
    $decoded = json_decode(trim($output), TRUE, 512, JSON_THROW_ON_ERROR);

    if (!is_array($decoded)) {
      $this->fail('Expected JSON output to decode to an array.');
    }

    return $decoded;
  }

}
