<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Traits;

/**
 * Trait MemoryStreamTrait.
 *
 * Opens seeded in-memory streams for tests.
 *
 * @mixin \PHPUnit\Framework\TestCase
 */
trait MemoryStreamTrait {

  /**
   * Opens an in-memory stream, optionally primed with content and rewound.
   *
   * @param string $contents
   *   The content to write, then rewind to the start.
   *
   * @return resource
   *   The stream.
   */
  protected function memoryStream(string $contents = '') {
    $stream = fopen('php://memory', 'r+');

    if ($stream === FALSE) {
      // @codeCoverageIgnoreStart
      $this->fail('Could not open an in-memory stream.');
      // @codeCoverageIgnoreEnd
    }

    if ($contents !== '') {
      fwrite($stream, $contents);
      rewind($stream);
    }

    return $stream;
  }

}
