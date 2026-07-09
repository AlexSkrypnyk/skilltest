<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Run;

/**
 * Shared filesystem primitives for the artifact writers.
 *
 * Creating a parent directory tree and writing a file, each failing loudly
 * rather than silently, is identical work for every persisted artifact - the
 * results document, JUnit XML, and the session log - so it lives in one place
 * instead of being copied into each writer.
 */
trait WritesFiles {

  /**
   * Creates a directory and its parents when it does not already exist.
   *
   * @param string $dir
   *   The directory to create.
   *
   * @throws \RuntimeException
   *   When the directory cannot be created.
   */
  protected function ensureDir(string $dir): void {
    if (is_dir($dir)) {
      return;
    }

    if (!mkdir($dir, 0777, TRUE) && !is_dir($dir)) {
      // @codeCoverageIgnoreStart
      throw new \RuntimeException(sprintf('Could not create directory "%s".', $dir));
      // @codeCoverageIgnoreEnd
    }
  }

  /**
   * Writes content to a file, failing loudly when the write does not land.
   *
   * @param string $file
   *   The destination file.
   * @param string $content
   *   The content to write.
   *
   * @throws \RuntimeException
   *   When the file cannot be written.
   */
  protected function put(string $file, string $content): void {
    if (file_put_contents($file, $content) === FALSE) {
      // @codeCoverageIgnoreStart
      throw new \RuntimeException(sprintf('Could not write "%s".', $file));
      // @codeCoverageIgnoreEnd
    }
  }

}
