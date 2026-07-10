<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Results;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Config\SchemaVersion;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;

/**
 * Loads a saved `results.json` for consumption by compare, report, and gate.
 *
 * The read-only counterpart to the migrator's reader gate: it decodes the JSON,
 * narrows it to a mapping, and refuses a document whose schema major this tool
 * cannot read, pointing the caller at `skilltest migrate`. It never rewrites -
 * a stale-major file is a hard error here, because a consumer cannot reason
 * about a shape it does not understand. A same-major minor difference is read,
 * because unknown keys are permitted by policy and the accessors degrade
 * gracefully on a missing one.
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
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the file is missing, unreadable, not valid JSON, not a mapping, or
   *   declares a schema major this tool cannot read.
   */
  public static function load(string $file): array {
    if (!is_file($file)) {
      throw new ConfigException('results file not found.', $file);
    }

    $contents = @file_get_contents($file);

    // @codeCoverageIgnoreStart
    if ($contents === FALSE) {
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
   * Refuses a document whose schema major this tool cannot read.
   *
   * @param array<string, mixed> $document
   *   The decoded document.
   * @param string $file
   *   The file path, for error context.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
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
