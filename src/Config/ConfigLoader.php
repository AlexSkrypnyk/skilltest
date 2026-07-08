<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Config;

use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads and merges the repo config and every discovered skill's `eval.yaml`.
 *
 * Loading owns the hard gates: a file that will not parse, or a schema major
 * this tool cannot read, throws a {@see ConfigException} and stops. Everything
 * that survives is returned as a {@see LoadedConfig} for schema and coherence
 * validation, which accumulate findings rather than throw.
 */
final readonly class ConfigLoader {

  /**
   * The repo config filename at the repository root.
   */
  public const string CONFIG_FILE = 'skilltest.yml';

  /**
   * The environment variable overriding the repo config path.
   */
  public const string ENV_CONFIG = 'SKILLTEST_CONFIG';

  /**
   * The repository root.
   */
  protected string $root;

  /**
   * Constructs a ConfigLoader.
   *
   * @param string $root
   *   The repository root. Defaults to the current directory when empty.
   */
  public function __construct(string $root) {
    $trimmed = rtrim($root, '/');
    $this->root = $trimmed === '' ? '.' : $trimmed;
  }

  /**
   * Loads the whole configuration.
   *
   * @param array<string, mixed> $cli
   *   CLI overrides keyed by name (models, threshold, trials, env).
   *
   * @return \AlexSkrypnyk\SkillTest\Config\LoadedConfig
   *   The loaded configuration.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When a file will not parse or declares an unreadable schema major.
   */
  public function load(array $cli = []): LoadedConfig {
    $repo_file = $this->repoConfigFile();
    $repo_data = [];

    if ($repo_file !== '') {
      $repo_data = $this->parseFile($repo_file);
      $this->gateVersion($repo_data, $repo_file);
    }

    $repo = RepoConfig::fromArray($repo_data);

    $discovery = new Discovery($this->root, $repo);
    $skills = [];
    $skills_without_eval = [];

    foreach ($discovery->skills() as $skill_dir) {
      $eval_file = $this->root . '/' . $skill_dir . '/' . $repo->evalFile;

      if (!is_file($eval_file)) {
        $skills_without_eval[] = $skill_dir;

        continue;
      }

      $eval_data = $this->parseFile($eval_file);
      $this->gateVersion($eval_data, $eval_file);
      $effective = EffectiveConfig::resolve($repo, $eval_data, $cli, basename($skill_dir), $skill_dir);
      $skills[] = new LoadedSkill($eval_file, $eval_data, $effective);
    }

    return new LoadedConfig($repo, $repo_data, $repo_file, $skills, $skills_without_eval);
  }

  /**
   * Resolves the repo config file to load, or an empty string when absent.
   *
   * An explicit environment override that points at a missing file is an
   * error, so a typo cannot silently fall through to defaults; the default
   * root path is optional and simply absent when the file does not exist.
   *
   * @return string
   *   The `skilltest.yml` path to load, or an empty string when there is none.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the environment override points at a missing file.
   */
  protected function repoConfigFile(): string {
    $override = getenv(self::ENV_CONFIG);

    if (is_string($override) && $override !== '') {
      if (!is_file($override)) {
        throw new ConfigException(sprintf('configured %s file not found.', self::ENV_CONFIG), $override);
      }

      return $override;
    }

    $default = $this->root . '/' . self::CONFIG_FILE;

    return is_file($default) ? $default : '';
  }

  /**
   * Parses a YAML file into a top-level mapping.
   *
   * @param string $file
   *   The file to parse.
   *
   * @return array<mixed>
   *   The parsed mapping, or an empty array for an empty file.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the file will not parse or is not a mapping.
   */
  protected function parseFile(string $file): array {
    $contents = file_get_contents($file);

    // @codeCoverageIgnoreStart
    if ($contents === FALSE) {
      throw new ConfigException('unable to read file.', $file);
    }
    // @codeCoverageIgnoreEnd
    try {
      $parsed = Yaml::parse($contents);
    }
    catch (ParseException $parse_exception) {
      throw new ConfigException('malformed YAML: ' . $parse_exception->getMessage(), $file);
    }

    if ($parsed === NULL) {
      return [];
    }

    if (!is_array($parsed)) {
      throw new ConfigException('expected a mapping at the top level.', $file);
    }

    // A non-empty sequence is an array too; only a mapping (or an empty
    // document, which parses to an empty array) is a valid config file.
    if ($parsed !== [] && array_is_list($parsed)) {
      throw new ConfigException('expected a mapping at the top level.', $file);
    }

    return $parsed;
  }

  /**
   * Rejects a file whose schema major this tool cannot read.
   *
   * @param array<mixed> $data
   *   The parsed file.
   * @param string $file
   *   The file path, for the error message.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the version is not a string, malformed, or a different major.
   */
  protected function gateVersion(array $data, string $file): void {
    $raw = Data::get($data, 'version');

    if ($raw === NULL) {
      return;
    }

    // The version must be a quoted string; an unquoted YAML number such as
    // 1.10 would parse to the float 1.1 and silently lose the minor.
    if (!is_string($raw)) {
      throw new ConfigException('version must be a quoted string, e.g. "1" or "1.2".', $file, 'version');
    }

    try {
      $version = SchemaVersion::parse($raw);
    }
    catch (\InvalidArgumentException $invalid_argument_exception) {
      throw new ConfigException($invalid_argument_exception->getMessage(), $file, 'version');
    }

    if (!$version->isCurrentMajor()) {
      throw new ConfigException(sprintf('unsupported schema major %d; run `skilltest migrate` to upgrade.', $version->major), $file, 'version');
    }
  }

}
