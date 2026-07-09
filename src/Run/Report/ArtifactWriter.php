<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Run\Report;

use AlexSkrypnyk\SkillTest\Run\WritesFiles;

/**
 * Writes a single reporter artifact - JUnit XML or a session log - to disk.
 *
 * The content handed here is already redacted at the document layer, before it
 * was rendered to XML or NDJSON, so this writer only owns placing the bytes:
 * it creates any missing parent directory and fails loudly rather than
 * silently when a write does not land.
 */
final readonly class ArtifactWriter {

  use WritesFiles;

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
    $this->ensureDir(dirname($file));
    $this->put($file, $content);

    return $file;
  }

}
