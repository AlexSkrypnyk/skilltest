<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Results;

use AlexSkrypnyk\File\File;
use AlexSkrypnyk\SkillTest\Config\ConfigException;
use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Config\SchemaVersion;

/**
 * Loads and validates a saved `results.json`.
 *
 * Rejects a document whose schema major this tool cannot read and never
 * rewrites, so a stale-major file is a hard error rather than a silent
 * upgrade. A same-major minor difference is read: unknown keys are
 * permitted and missing ones degrade gracefully in the accessors.
 */
final readonly class ResultsFile {

  /**
   * Loads and validates a results document from a file path.
   *
   * @param string $file
   *   The path to the `results.json` document.
   *
   * @return array<string, mixed>
   *   The decoded results document.
   *
   * @throws \AlexSkrypnyk\SkillTest\Config\ConfigException
   *   When the file is missing, unreadable, not valid JSON, not a mapping, or
   *   declares a schema major this tool cannot read.
   */
  public static function load(string $file): array {
    if (!is_file($file)) {
      throw new ConfigException('results file not found.', $file);
    }

    try {
      $contents = File::read($file);
    }
    // @codeCoverageIgnoreStart
    catch (\Throwable) {
      throw new ConfigException('unable to read results file.', $file);
    }
    // @codeCoverageIgnoreEnd
    try {
      $parsed = json_decode($contents, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $json_exception) {
      throw new ConfigException('malformed JSON: ' . $json_exception->getMessage(), $file);
    }

    if (!is_array($parsed) || ($parsed !== [] && array_is_list($parsed))) {
      throw new ConfigException('expected a results object at the top level.', $file);
    }

    self::assertReadableMajor($parsed, $file);

    return $parsed;
  }

  /**
   * Rejects a document whose schema major this tool cannot read.
   *
   * @param array<string, mixed> $document
   *   The decoded document.
   * @param string $file
   *   The file path, for error context.
   *
   * @throws \AlexSkrypnyk\SkillTest\Config\ConfigException
   *   When the version is unparseable or its major is not the current major.
   */
  protected static function assertReadableMajor(array $document, string $file): void {
    $raw = Data::get($document, 'version');

    if ($raw !== NULL && !is_string($raw) && !is_int($raw) && !is_float($raw)) {
      throw new ConfigException('version must be a scalar, e.g. "1" or "1.2".', $file, 'version');
    }

    try {
      $version = SchemaVersion::parse($raw);
    }
    catch (\InvalidArgumentException $invalid_argument_exception) {
      throw new ConfigException($invalid_argument_exception->getMessage(), $file, 'version');
    }

    if (!$version->isCurrentMajor()) {
      throw new ConfigException(sprintf('results file declares schema major %d, which this tool cannot read (current major %d); run `skilltest migrate %s`.', $version->major, SchemaVersion::CURRENT_MAJOR, $file), $file, 'version');
    }
  }

}
