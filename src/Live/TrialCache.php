<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Config\SkillFiles;

/**
 * A content-addressed store of graded trials, keyed on everything that matters.
 *
 * A live trial is expensive and non-deterministic, so re-running an unchanged
 * task on an unchanged skill wastes tokens for no new signal. This caches the
 * graded trials of one task-on-model under a key that is a digest of the task
 * definition, the resolved model id, the tool version, every file the skill
 * ships, and the task's fixtures - so any change to any of them misses and
 * re-runs, while nothing changing hits and replays the prior verdict. It stores
 * only whole, graded trials; re-scoring a saved run against a tightened rubric
 * is a separate, judge-only path, not this cache.
 */
final readonly class TrialCache {

  /**
   * The directory, relative to the repo root, the cache lives under.
   */
  public const string CACHE_DIR = '.skilltest/cache';

  /**
   * The conventional per-skill fixtures directory.
   *
   * Excluded from the skill-content digest because fixtures are per-task inputs
   * digested separately: folding them into the skill digest would make touching
   * one task's fixture invalidate every task on the skill.
   */
  public const string FIXTURES_DIR = 'fixtures';

  /**
   * Constructs a TrialCache.
   *
   * @param string $dir
   *   The directory cache entries are written to and read from.
   * @param string $toolVersion
   *   The running tool version, folded into every key so a tool upgrade
   *   invalidates results it may grade differently.
   */
  public function __construct(
    protected string $dir,
    protected string $toolVersion,
  ) {}

  /**
   * Builds the cache key for one task-on-model.
   *
   * @param string $skill
   *   The skill name.
   * @param array<string, mixed> $entry
   *   The validated task entry (name, prompt, and the full task declaration).
   * @param string $model_id
   *   The resolved model id.
   * @param string $skill_dir
   *   The absolute skill directory, whose files are digested.
   * @param array{fixture: ?string, repos: array<int, array{source: string, commit: string, dest: string}>, workdir: ?string} $inputs
   *   The parsed task inputs, whose fixture and repos are digested.
   *
   * @return string
   *   The 64-character hex cache key.
   */
  public function key(string $skill, array $entry, string $model_id, string $skill_dir, array $inputs): string {
    $fingerprint = [
      'skill' => $skill,
      'task' => $entry,
      'model' => $model_id,
      'tool' => $this->toolVersion,
      'files' => $this->digestDir($skill_dir, self::FIXTURES_DIR),
      'inputs' => $this->digestInputs($inputs, $skill_dir),
    ];

    return hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Reads the cached trials for a key, or NULL on a miss.
   *
   * @param string $key
   *   The cache key.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\TrialResult[]|null
   *   The rebuilt trials, or NULL when there is no usable entry.
   */
  public function get(string $key): ?array {
    $file = $this->file($key);

    if (!is_file($file)) {
      return NULL;
    }

    $contents = @file_get_contents($file);

    if ($contents === FALSE) {
      // @codeCoverageIgnoreStart
      return NULL;
      // @codeCoverageIgnoreEnd
    }

    $rows = Data::toArrayList(json_decode($contents, TRUE));
    $trials = array_map(TrialResult::fromCache(...), $rows);

    return $trials === [] ? NULL : $trials;
  }

  /**
   * Writes the graded trials for a key.
   *
   * @param string $key
   *   The cache key.
   * @param \AlexSkrypnyk\SkillTest\Live\TrialResult[] $trials
   *   The graded trials to cache.
   */
  public function put(string $key, array $trials): void {
    if (!is_dir($this->dir)) {
      @mkdir($this->dir, 0777, TRUE);
    }

    $rows = array_map(static fn(TrialResult $trial): array => $trial->toCache(), $trials);
    @file_put_contents($this->file($key), json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Removes every cache entry, returning how many were removed.
   *
   * @return int
   *   The number of cache files removed.
   */
  public function clear(): int {
    $count = 0;

    foreach (glob($this->dir . '/*.json') ?: [] as $file) {
      if (is_file($file)) {
        unlink($file);
        $count++;
      }
    }

    return $count;
  }

  /**
   * The cache file path for a key.
   *
   * @param string $key
   *   The cache key.
   *
   * @return string
   *   The file path.
   */
  protected function file(string $key): string {
    return $this->dir . '/' . $key . '.json';
  }

  /**
   * Digests the fixtures and repos a task's inputs pull into a workspace.
   *
   * @param array{fixture: ?string, repos: array<int, array{source: string, commit: string, dest: string}>, workdir: ?string} $inputs
   *   The parsed task inputs.
   * @param string $skill_dir
   *   The absolute skill directory a relative fixture resolves against.
   *
   * @return array<string, mixed>
   *   The digest: the repos verbatim and the fixture's content hash.
   */
  protected function digestInputs(array $inputs, string $skill_dir): array {
    $digest = ['repos' => $inputs['repos'], 'workdir' => $inputs['workdir']];
    $fixture = $inputs['fixture'];

    if ($fixture !== NULL && $fixture !== '') {
      $path = str_starts_with($fixture, '/') ? $fixture : $skill_dir . '/' . $fixture;
      $digest['fixture'] = is_dir($path) ? $this->digestDir($path) : $this->digestFile($path);
    }

    return $digest;
  }

  /**
   * Digests every file under a directory into a path-to-hash map.
   *
   * @param string $dir
   *   The absolute directory.
   * @param string $exclude
   *   A top-level subdirectory name to skip, or an empty string to include all.
   *
   * @return array<string, string>
   *   The content hash of each file, keyed by its path relative to the
   *   directory, in sorted order for a stable key.
   */
  protected function digestDir(string $dir, string $exclude = ''): array {
    $prefix = rtrim($dir, '/') . '/';
    $skip = $exclude === '' ? '' : $prefix . $exclude . '/';
    $digest = [];

    foreach (SkillFiles::under($dir) as $file) {
      if ($skip !== '' && str_starts_with($file, $skip)) {
        continue;
      }

      $digest[substr($file, strlen($prefix))] = $this->digestFile($file);
    }

    return $digest;
  }

  /**
   * The content hash of one file, or the empty string when it is absent.
   *
   * @param string $file
   *   The file path.
   *
   * @return string
   *   The SHA-256 hex digest, or an empty string when the file cannot be read.
   */
  protected function digestFile(string $file): string {
    $hash = @hash_file('sha256', $file);

    return $hash === FALSE ? '' : $hash;
  }

}
