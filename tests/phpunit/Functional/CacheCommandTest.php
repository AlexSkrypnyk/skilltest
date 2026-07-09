<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\PhpunitHelpers\Traits\ApplicationTrait;
use AlexSkrypnyk\SkillTest\Command\CacheCommand;
use AlexSkrypnyk\SkillTest\Live\TrialCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class CacheCommandTest.
 *
 * Functional test for the cache command: clearing removes every cached trial
 * result and reports the count, an empty cache clears to zero, and an unknown
 * action is a configuration error.
 */
#[CoversClass(CacheCommand::class)]
#[Group('command')]
final class CacheCommandTest extends TestCase {

  use ApplicationTrait;

  /**
   * The temporary repository root holding the cache directory.
   */
  protected string $tempDir = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->tempDir = dirname(__DIR__, 3) . '/.artifacts/tmp/cachecmd-' . getmypid() . '-' . uniqid();
    mkdir($this->tempDir, 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->remove($this->tempDir);
    $this->applicationTearDown();

    parent::tearDown();
  }

  public function testClearRemovesCachedResults(): void {
    $cache_dir = $this->tempDir . '/' . TrialCache::CACHE_DIR;
    mkdir($cache_dir, 0777, TRUE);
    file_put_contents($cache_dir . '/a.json', '[]');
    file_put_contents($cache_dir . '/b.json', '[]');

    $output = $this->runCache(['action' => 'clear', '--dir' => $this->tempDir], 0);

    $this->assertStringContainsString('cleared 2 cached trial result(s).', $output);
    $this->assertSame([], glob($cache_dir . '/*.json') ?: []);
  }

  public function testClearOnEmptyCacheReportsZero(): void {
    $output = $this->runCache(['action' => 'clear', '--dir' => $this->tempDir], 0);

    $this->assertStringContainsString('cleared 0 cached trial result(s).', $output);
  }

  public function testUnknownActionIsError(): void {
    $output = $this->runCache(['action' => 'purge', '--dir' => $this->tempDir], 2);

    $this->assertStringContainsString('unknown action; expected one of: clear', $output);
  }

  public function testDefaultsToCurrentDirectory(): void {
    $output = $this->runCache(['action' => 'clear'], 0);

    $this->assertStringContainsString('cleared 0 cached trial result(s).', $output);
  }

  /**
   * Runs the cache command and asserts the exit code.
   *
   * @param array<string, string|bool> $input
   *   The command input.
   * @param int $expected_exit
   *   The expected exit code.
   *
   * @return string
   *   The command output.
   */
  protected function runCache(array $input, int $expected_exit): string {
    $this->applicationInitFromCommand(CacheCommand::class);
    $this->applicationRun($input, [], $expected_exit !== 0);

    $this->assertSame($expected_exit, $this->applicationGetTester()->getStatusCode());

    return $this->applicationGetTester()->getDisplay();
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
