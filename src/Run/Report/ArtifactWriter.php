<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Run\Report;

use AlexSkrypnyk\File\File;

/**
 * Writes a single reporter artifact - JUnit XML or a session log - to disk.
 *
 * Content arrives already redacted at the document layer, before rendering
 * to XML or NDJSON, so this writer only places the bytes. It creates any
 * missing parent directory, and a failed write throws rather than passing
 * silently.
 */
final readonly class ArtifactWriter {

  /**
   * Writes content to a file, creating missing parent directories.
   *
   * @param string $file
   *   The destination file path.
   * @param string $content
   *   The content to write.
   *
   * @return string
   *   The path written.
   */
  public function write(string $file, string $content): string {
    File::dump($file, $content);

    return $file;
  }

}
