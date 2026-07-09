<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Update;

/**
 * The once-a-day, non-blocking "a newer release exists" notice.
 *
 * A convenience that must never get in the way: it is silent in CI and whenever
 * a flag or environment variable opts out, it never spends more than one
 * network read per day (the latest tag is cached under the tool's own scratch
 * area), and any failure to reach the network is swallowed into no notice at
 * all. It only ever returns a string to print; the caller decides where. The
 * clock and the release client are injected so every branch - fresh cache,
 * stale cache, unreachable network, newer, same - is exercised without a real
 * clock or network.
 */
final readonly class UpdateNotifier {

  /**
   * The cache file name, under the resolved cache directory.
   */
  public const string CACHE_FILE = 'update-check.json';

  /**
   * The environment variable that opts out of the check.
   */
  public const string ENV_DISABLE = 'SKILLTEST_NO_UPDATE_CHECK';

  /**
   * The environment variable whose presence marks a CI run.
   */
  public const string ENV_CI = 'CI';

  /**
   * The cache lifetime, in seconds: one day.
   */
  public const int TTL_SECONDS = 86400;

  /**
   * Constructs an UpdateNotifier.
   *
   * @param \AlexSkrypnyk\SkillTest\Update\ReleaseClient $client
   *   The release client the latest tag is read through.
   * @param array<string, string> $env
   *   The process environment, read for the opt-out and CI signals.
   * @param string $currentVersion
   *   The running tool version; a non-version (e.g. a source build) is silent.
   * @param (\Closure(): int)|null $clock
   *   A clock returning the current Unix timestamp, or NULL for the real clock.
   */
  public function __construct(
    protected ReleaseClient $client,
    protected array $env,
    protected string $currentVersion,
    protected ?\Closure $clock = NULL,
  ) {}

  /**
   * The upgrade notice to print, or NULL when there is nothing to say.
   *
   * @param string $cache_dir
   *   The directory the once-a-day cache is read from and written to.
   * @param bool $flag_disabled
   *   Whether an explicit `--no-update-check` flag was passed.
   *
   * @return string|null
   *   The one-line notice, or NULL when disabled, silent, current, or offline.
   */
  public function notice(string $cache_dir, bool $flag_disabled): ?string {
    if ($this->disabled($flag_disabled) || !$this->isVersion($this->currentVersion)) {
      return NULL;
    }

    $latest = $this->latest($cache_dir);

    if ($latest === NULL) {
      return NULL;
    }

    $normalised = ltrim($latest, 'vV');

    if (!$this->isVersion($normalised) || version_compare($normalised, ltrim($this->currentVersion, 'vV'), '<=')) {
      return NULL;
    }

    return sprintf('A new skilltest release is available: %s (you have %s). Run `skilltest self-update` to upgrade.', $latest, $this->currentVersion);
  }

  /**
   * Whether the check is opted out by flag, environment variable, or CI.
   *
   * @param bool $flag_disabled
   *   Whether an explicit `--no-update-check` flag was passed.
   *
   * @return bool
   *   TRUE when no check should run.
   */
  protected function disabled(bool $flag_disabled): bool {
    return $flag_disabled || ($this->env[self::ENV_DISABLE] ?? '') !== '' || ($this->env[self::ENV_CI] ?? '') !== '';
  }

  /**
   * The latest tag, from a fresh cache or a single dated network read.
   *
   * @param string $cache_dir
   *   The cache directory.
   *
   * @return string|null
   *   The latest tag, or NULL when the read fails and no cache is fresh.
   */
  protected function latest(string $cache_dir): ?string {
    $file = rtrim($cache_dir, '/') . '/' . self::CACHE_FILE;
    $cached = $this->readCache($file);
    $now = $this->now();

    if ($cached !== NULL && ($now - $cached['checked_at']) < self::TTL_SECONDS) {
      return $cached['latest'];
    }

    $latest = $this->client->latestTag();

    if ($latest === NULL) {
      return NULL;
    }

    $this->writeCache($file, ['checked_at' => $now, 'latest' => $latest]);

    return $latest;
  }

  /**
   * The current Unix timestamp, from the injected clock or the real one.
   *
   * @return int
   *   The timestamp.
   */
  protected function now(): int {
    return $this->clock instanceof \Closure ? ($this->clock)() : time();
  }

  /**
   * Reads the cache file into a normalised record, or NULL when unusable.
   *
   * @param string $file
   *   The cache file path.
   *
   * @return array{checked_at: int, latest: string}|null
   *   The record, or NULL when absent, unreadable, or malformed.
   */
  protected function readCache(string $file): ?array {
    if (!is_file($file)) {
      return NULL;
    }

    $contents = @file_get_contents($file);

    if ($contents === FALSE) {
      // @codeCoverageIgnoreStart
      return NULL;
      // @codeCoverageIgnoreEnd
    }

    $decoded = json_decode($contents, TRUE);

    if (!is_array($decoded)) {
      return NULL;
    }

    $checked_at = $decoded['checked_at'] ?? NULL;
    $latest = $decoded['latest'] ?? NULL;

    if (!is_int($checked_at) || !is_string($latest)) {
      return NULL;
    }

    return ['checked_at' => $checked_at, 'latest' => $latest];
  }

  /**
   * Writes the cache record, creating the cache directory when needed.
   *
   * @param string $file
   *   The cache file path.
   * @param array{checked_at: int, latest: string} $record
   *   The record to persist.
   */
  protected function writeCache(string $file, array $record): void {
    $dir = dirname($file);

    if (!is_dir($dir)) {
      @mkdir($dir, 0777, TRUE);
    }

    @file_put_contents($file, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Whether a string looks like a comparable version.
   *
   * @param string $value
   *   The candidate.
   *
   * @return bool
   *   TRUE when it starts with a digit run, optionally `v`-prefixed.
   */
  protected function isVersion(string $value): bool {
    return preg_match('/^v?\d+(\.\d+)*$/', $value) === 1;
  }

}
