<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Traits;

/**
 * Trait ApplicationJsonDecodeTrait.
 *
 * Decodes the JSON standard output of a command run.
 *
 * @mixin \PHPUnit\Framework\TestCase
 * @mixin \AlexSkrypnyk\PhpunitHelpers\Traits\ApplicationTrait
 */
trait ApplicationJsonDecodeTrait {

  /**
   * Decodes the JSON standard output of a command run.
   *
   * @param string $output
   *   The combined output; only stdout carries the JSON.
   *
   * @return array<mixed>
   *   The decoded payload.
   */
  protected function decodeStdout(string $output): array {
    $stdout = $this->applicationGetTester()->getDisplay();
    $decoded = json_decode(trim($stdout === '' ? $output : $stdout), TRUE, 512, JSON_THROW_ON_ERROR);

    if (!is_array($decoded)) {
      $this->fail('Expected JSON output to decode to an array.');
    }

    return $decoded;
  }

}
