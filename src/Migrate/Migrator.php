<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Migrate;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Config\SchemaVersion;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Upgrades an `eval.yaml`, `skilltest.yml`, or `results.json` to this schema.
 *
 * The reader gate rejects any file whose major this tool cannot read, so a file
 * left behind at an older major is otherwise unusable; this is the one place
 * that reads such a file on purpose, transforms it to the current major, and
 * writes it back. A file already at the current major is a no-op reported as
 * such - migration never rewrites a file it did not have to. A file from a
 * newer major cannot be downgraded and is refused with a pointer to upgrade the
 * tool. The parse and serialise seam follows the file extension: JSON for
 * `results.json`, YAML for the two config files.
 */
final readonly class Migrator {

  /**
   * The inline depth for the YAML dumper, high enough to stay block-style.
   */
  protected const int YAML_INLINE_DEPTH = 20;

  /**
   * Checks a file against the current schema and rewrites it when it is older.
   *
   * @param string $file
   *   The file to check and, when older, migrate in place.
   *
   * @return \AlexSkrypnyk\SkillTest\Migrate\MigrateResult
   *   The outcome: whether the file changed, the versions, and the message.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the file is missing, unreadable, not a mapping, carries an
   *   unparseable version, or declares a major newer than this tool supports.
   */
  public function migrate(string $file): MigrateResult {
    if (!is_file($file)) {
      throw new ConfigException('file not found.', $file);
    }

    $contents = $this->read($file);
    $json = $this->isJson($file);
    $data = $json ? $this->parseJson($contents, $file) : $this->parseYaml($contents, $file);
    $version = $this->version($data, $file);
    $current = (string) SchemaVersion::CURRENT_MAJOR;

    if ($version->major > SchemaVersion::CURRENT_MAJOR) {
      throw new ConfigException(sprintf('file declares schema major %d, newer than this tool supports (%d); upgrade skilltest to read it.', $version->major, SchemaVersion::CURRENT_MAJOR), $file, 'version');
    }

    if ($version->isCurrentMajor()) {
      return new MigrateResult(FALSE, (string) $version, (string) $version, sprintf('%s is already at the current schema (major %d); no changes.', $file, SchemaVersion::CURRENT_MAJOR));
    }

    // Structural field transforms for a specific major gap would be applied
    // here; the only upgrade to the current major is a version stamp, so the
    // rest of the document is carried through unchanged.
    $data['version'] = $current;
    $this->write($file, $data, $json);

    return new MigrateResult(TRUE, (string) $version, $current, sprintf('%s migrated from schema %s to %s.', $file, $version, $current));
  }

  /**
   * Whether a path is a JSON file, by extension.
   *
   * @param string $file
   *   The file path.
   *
   * @return bool
   *   TRUE when the extension is `json`.
   */
  protected function isJson(string $file): bool {
    return strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'json';
  }

  /**
   * Reads a file, treating an unreadable one as a configuration error.
   *
   * @param string $file
   *   The file path.
   *
   * @return string
   *   The file contents.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the file cannot be read.
   */
  protected function read(string $file): string {
    $contents = @file_get_contents($file);

    // @codeCoverageIgnoreStart
    if ($contents === FALSE) {
      throw new ConfigException('unable to read file.', $file);
    }
    // @codeCoverageIgnoreEnd
    return $contents;
  }

  /**
   * Parses a YAML file into a top-level mapping.
   *
   * @param string $contents
   *   The file contents.
   * @param string $file
   *   The file path, for error context.
   *
   * @return array<mixed>
   *   The parsed mapping.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the file will not parse or is not a mapping.
   */
  protected function parseYaml(string $contents, string $file): array {
    try {
      $parsed = Yaml::parse($contents);
    }
    catch (ParseException $parse_exception) {
      throw new ConfigException('malformed YAML: ' . $parse_exception->getMessage(), $file);
    }

    return $this->asMapping($parsed, $file);
  }

  /**
   * Parses a JSON file into a top-level object.
   *
   * @param string $contents
   *   The file contents.
   * @param string $file
   *   The file path, for error context.
   *
   * @return array<mixed>
   *   The parsed object.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the file will not parse or is not an object.
   */
  protected function parseJson(string $contents, string $file): array {
    try {
      $parsed = json_decode($contents, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $json_exception) {
      throw new ConfigException('malformed JSON: ' . $json_exception->getMessage(), $file);
    }

    return $this->asMapping($parsed, $file);
  }

  /**
   * Narrows a parsed document to a mapping, rejecting lists and scalars.
   *
   * @param mixed $parsed
   *   The parsed document.
   * @param string $file
   *   The file path, for error context.
   *
   * @return array<mixed>
   *   The mapping.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the document is not a mapping.
   */
  protected function asMapping(mixed $parsed, string $file): array {
    if (!is_array($parsed) || ($parsed !== [] && array_is_list($parsed))) {
      throw new ConfigException('expected a mapping at the top level.', $file);
    }

    return $parsed;
  }

  /**
   * Reads and validates the file's declared schema version.
   *
   * @param array<mixed> $data
   *   The parsed document.
   * @param string $file
   *   The file path, for error context.
   *
   * @return \AlexSkrypnyk\SkillTest\Config\SchemaVersion
   *   The parsed version; the current version when none is declared.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the version is a non-scalar or an unparseable string.
   */
  protected function version(array $data, string $file): SchemaVersion {
    $raw = Data::get($data, 'version');

    if ($raw !== NULL && !is_string($raw) && !is_int($raw) && !is_float($raw)) {
      throw new ConfigException('version must be a scalar, e.g. "1" or "1.2".', $file, 'version');
    }

    try {
      return SchemaVersion::parse($raw);
    }
    catch (\InvalidArgumentException $invalid_argument_exception) {
      throw new ConfigException($invalid_argument_exception->getMessage(), $file, 'version');
    }
  }

  /**
   * Serialises the migrated document back to disk in its original format.
   *
   * @param string $file
   *   The file path.
   * @param array<mixed> $data
   *   The migrated document.
   * @param bool $json
   *   Whether the file is JSON; YAML otherwise.
   */
  protected function write(string $file, array $data, bool $json): void {
    $encoded = $json
      ? json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION) . "\n"
      : Yaml::dump($data, self::YAML_INLINE_DEPTH, 2);

    file_put_contents($file, $encoded);
  }

}
