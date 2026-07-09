<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Live\TrialCache;
use AlexSkrypnyk\SkillTest\Live\TrialResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class TrialCacheTest.
 *
 * Unit test for the trial cache: key sensitivity to every ingredient, the
 * put/get round-trip that flags a hit, miss and corruption handling, and clear.
 */
#[CoversClass(TrialCache::class)]
#[Group('live')]
final class TrialCacheTest extends TestCase {

  /**
   * The temporary base holding the skill tree and the cache directory.
   */
  protected string $base = '';

  /**
   * The skill directory whose files feed the key.
   */
  protected string $skillDir = '';

  /**
   * The cache directory.
   */
  protected string $cacheDir = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->base = dirname(__DIR__, 4) . '/.artifacts/tmp/trialcache-' . getmypid() . '-' . uniqid();
    $this->skillDir = $this->base . '/skills/alpha';
    $this->cacheDir = $this->base . '/.skilltest/cache';

    mkdir($this->skillDir . '/fixtures', 0777, TRUE);
    file_put_contents($this->skillDir . '/SKILL.md', "---\nname: alpha\n---\n# Body\n");
    file_put_contents($this->skillDir . '/eval.yaml', "version: \"1\"\n");
    file_put_contents($this->skillDir . '/fixtures/data.txt', 'seed');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->remove($this->base);

    parent::tearDown();
  }

  public function testKeyIsStableForUnchangedInputs(): void {
    $this->assertSame($this->key(), $this->key());
  }

  public function testKeyChangesWhenSkillFileChanges(): void {
    $before = $this->key();
    file_put_contents($this->skillDir . '/SKILL.md', "---\nname: alpha\n---\n# Changed\n");

    $this->assertNotSame($before, $this->key());
  }

  public function testKeyChangesWhenTheFixtureChanges(): void {
    $before = $this->key();
    file_put_contents($this->skillDir . '/fixtures/data.txt', 'different');

    $this->assertNotSame($before, $this->key());
  }

  public function testKeyChangesWithTheModel(): void {
    $this->assertNotSame($this->key(model: 'claude-haiku-4-5'), $this->key(model: 'claude-sonnet-5'));
  }

  public function testKeyChangesWithTheToolVersion(): void {
    $this->assertNotSame($this->key(tool: '1.0.0'), $this->key(tool: '1.1.0'));
  }

  public function testKeyChangesWithTheTaskDefinition(): void {
    $this->assertNotSame($this->key(), $this->key(entry: ['name' => 'invoked', 'prompt' => 'Do something else', 'task' => []]));
  }

  public function testKeyChangesWithDeclaredRepo(): void {
    $with_repo = ['fixture' => 'fixtures/data.txt', 'repos' => [['source' => 'src', 'commit' => 'HEAD', 'dest' => 'work']], 'workdir' => NULL];

    $this->assertNotSame($this->key(), $this->key(inputs: $with_repo));
  }

  public function testKeyHandlesDirectoryFixture(): void {
    $inputs = ['fixture' => 'fixtures', 'repos' => [], 'workdir' => NULL];
    $before = $this->key(inputs: $inputs);
    file_put_contents($this->skillDir . '/fixtures/data.txt', 'changed');

    $this->assertNotSame($before, $this->key(inputs: $inputs));
  }

  public function testKeyToleratesMissingFixture(): void {
    $present = $this->key();
    $missing = $this->key(inputs: ['fixture' => 'fixtures/absent.txt', 'repos' => [], 'workdir' => NULL]);

    $this->assertNotSame($present, $missing);
  }

  public function testPutThenGetReplaysTrialsFlaggedAsHits(): void {
    $cache = $this->cache();
    $key = $this->key();
    $trials = [
      new TrialResult(1, TRUE, [], 100, 50, 3, 0.01, 1200, 'run-1', 'artifacts/t1.jsonl'),
      new TrialResult(2, FALSE, [], 80, 40, 2, 0.02, 900, 'run-2', 'artifacts/t2.jsonl'),
    ];

    $cache->put($key, $trials);
    $restored = $cache->get($key);

    $this->assertNotNull($restored);
    $this->assertCount(2, $restored);
    $this->assertSame($trials[0]->toCache(), $restored[0]->toCache());
    $this->assertTrue($restored[0]->cached);
    $this->assertTrue($restored[1]->cached);
  }

  public function testGetIsNullOnMiss(): void {
    $this->assertNull($this->cache()->get($this->key()));
  }

  public function testGetIsNullOnCorruptEntry(): void {
    $cache = $this->cache();
    $key = $this->key();
    mkdir($this->cacheDir, 0777, TRUE);
    file_put_contents($this->cacheDir . '/' . $key . '.json', 'not json at all');

    $this->assertNull($cache->get($key));
  }

  public function testClearRemovesEntriesAndCountsThem(): void {
    $cache = $this->cache();
    $cache->put($this->key(model: 'a'), [new TrialResult(1, TRUE, [], 1, 1, 1, 0.0, 1, 'x', 'a.jsonl')]);
    $cache->put($this->key(model: 'b'), [new TrialResult(1, TRUE, [], 1, 1, 1, 0.0, 1, 'x', 'b.jsonl')]);

    $this->assertSame(2, $cache->clear());
    $this->assertSame([], glob($this->cacheDir . '/*.json') ?: []);
  }

  public function testClearOnAbsentDirectoryIsZero(): void {
    $this->assertSame(0, $this->cache()->clear());
  }

  /**
   * Builds a cache over the temp directory.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\TrialCache
   *   The cache.
   */
  protected function cache(): TrialCache {
    return new TrialCache($this->cacheDir, '1.0.0');
  }

  /**
   * Computes a key with the default ingredients and any overrides.
   *
   * @param string|null $tool
   *   A tool-version override.
   * @param string|null $model
   *   A model-id override.
   * @param array<string, mixed>|null $entry
   *   A task-entry override.
   * @param array{fixture: string|null, repos: array<int, array{source: string, commit: string, dest: string}>, workdir: string|null}|null $inputs
   *   A task-inputs override.
   *
   * @return string
   *   The key.
   */
  protected function key(?string $tool = NULL, ?string $model = NULL, ?array $entry = NULL, ?array $inputs = NULL): string {
    $entry ??= ['name' => 'invoked', 'prompt' => 'Build the thing', 'task' => ['name' => 'invoked', 'prompt' => 'Build the thing']];
    $inputs ??= ['fixture' => 'fixtures/data.txt', 'repos' => [], 'workdir' => NULL];

    return (new TrialCache($this->cacheDir, $tool ?? '1.0.0'))->key('alpha', $entry, $model ?? 'claude-haiku-4-5', $this->skillDir, $inputs);
  }

  /**
   * Recursively removes a directory tree.
   *
   * @param string $dir
   *   The directory to remove.
   */
  protected function remove(string $dir): void {
    if (!is_dir($dir)) {
      // @codeCoverageIgnoreStart
      return;
      // @codeCoverageIgnoreEnd
    }

    foreach (scandir($dir) ?: [] as $item) {
      if ($item === '.') {
        continue;
      }
      if ($item === '..') {
        continue;
      }
      $path = $dir . '/' . $item;

      if (is_dir($path) && !is_link($path)) {
        $this->remove($path);

        continue;
      }

      unlink($path);
    }

    rmdir($dir);
  }

}
