<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest;

/**
 * Tool identity: name, version, and supported data schema versions.
 *
 * Single source of truth for the version command output and for the
 * versions stamped into configuration and results files.
 */
final class Version {

  /**
   * The tool name.
   */
  public const string NAME = 'skilltest';

  /**
   * The tool version, replaced by Box when the PHAR is compiled.
   */
  public const string VERSION = '@skilltest-version@';

  /**
   * The version reported when running from source.
   */
  public const string FALLBACK = 'development';

  /**
   * The eval.yaml and skilltest.yml schema version this tool reads.
   */
  public const string CONFIG_SCHEMA_VERSION = '1';

  /**
   * The results.json schema version this tool writes.
   */
  public const string RESULTS_SCHEMA_VERSION = '1';

  /**
   * Resolves the effective tool version.
   *
   * Box replaces the placeholder textually in every file it ships, so the
   * unreplaced marker is detected by its sigil: mentioning the placeholder
   * literally here would get it replaced in the PHAR as well and break the
   * detection.
   *
   * @param string|null $version
   *   The raw version to resolve, or NULL to use the compiled-in value.
   *
   * @return string
   *   The version string, or the fallback when running from source.
   */
  public static function id(?string $version = NULL): string {
    $version ??= self::VERSION;

    return str_starts_with($version, '@') ? self::FALLBACK : $version;
  }

  /**
   * Reports the runtime form of the executable.
   *
   * @return string
   *   Either 'phar' or 'source'.
   */
  public static function runtime(): string {
    return \Phar::running() === '' ? 'source' : 'phar';
  }

}
