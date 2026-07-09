<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\SkillTest\Live\ProcessPool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class ProcessPoolFunctionalTest.
 *
 * Exercises the real concurrent process path: keyed capture, propagated exit
 * codes, a timeout that terminates a hang, force-kill of a signal-ignoring
 * command, and that concurrency shortens wall-clock without changing results.
 */
#[CoversClass(ProcessPool::class)]
#[Group('process')]
final class ProcessPoolFunctionalTest extends TestCase {

  /**
   * A real working directory the commands execute in.
   */
  protected string $workdir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->workdir = dirname(__DIR__, 3) . '/.artifacts/tmp/processpool-' . getmypid() . '-' . uniqid();
    mkdir($this->workdir, 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->remove($this->workdir);

    parent::tearDown();
  }

  public function testEmptyCommandsReturnEmpty(): void {
    $this->assertSame([], (new ProcessPool(3, 5.0))->run([]));
  }

  public function testCapturesStdoutAndExitKeyedInOrder(): void {
    $results = (new ProcessPool(3, 5.0))->run([
      'a' => [$this->sh('printf alpha'), $this->workdir],
      'b' => [$this->sh('printf beta; exit 3'), $this->workdir],
      'c' => [$this->sh('printf gamma'), $this->workdir],
    ]);

    $this->assertSame(['a', 'b', 'c'], array_keys($results));
    $this->assertSame([0, 'alpha'], [$results['a'][0], $results['a'][1]]);
    $this->assertSame([3, 'beta'], [$results['b'][0], $results['b'][1]]);
    $this->assertSame([0, 'gamma'], [$results['c'][0], $results['c'][1]]);
    $this->assertGreaterThanOrEqual(0, $results['a'][2]);
  }

  public function testCapturesLargeStreamedOutput(): void {
    $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('echo str_repeat("x", 50000);');

    $results = (new ProcessPool(1, 5.0))->run(['big' => [$command, $this->workdir]]);

    $this->assertSame(0, $results['big'][0]);
    $this->assertSame(50000, strlen($results['big'][1]));
  }

  public function testTimeoutTerminatesHangQuickly(): void {
    $started = microtime(TRUE);

    $results = (new ProcessPool(1, 0.3))->run(['slow' => [$this->sh('sleep 5'), $this->workdir]]);

    $this->assertSame(ProcessPool::TIMEOUT_EXIT, $results['slow'][0]);
    $this->assertLessThan(3.0, microtime(TRUE) - $started);
  }

  public function testForceKillsCommandThatIgnoresTermination(): void {
    $results = (new ProcessPool(1, 0.3))->run(['stubborn' => [$this->sh('trap "" TERM; while :; do :; done'), $this->workdir]]);

    $this->assertSame(ProcessPool::TIMEOUT_EXIT, $results['stubborn'][0]);
  }

  public function testConcurrencyShortensWallClock(): void {
    $commands = [
      'one' => [$this->sh('sleep 0.4'), $this->workdir],
      'two' => [$this->sh('sleep 0.4'), $this->workdir],
      'three' => [$this->sh('sleep 0.4'), $this->workdir],
    ];

    $serial_started = microtime(TRUE);
    (new ProcessPool(1, 5.0))->run($commands);
    $serial = microtime(TRUE) - $serial_started;

    $parallel_started = microtime(TRUE);
    $results = (new ProcessPool(3, 5.0))->run($commands);
    $parallel = microtime(TRUE) - $parallel_started;

    $this->assertLessThan($serial, $parallel);
    $this->assertSame([0, 0, 0], [$results['one'][0], $results['two'][0], $results['three'][0]]);
  }

  /**
   * Wraps a shell snippet as a command line for the pool.
   *
   * @param string $snippet
   *   The shell snippet.
   *
   * @return string
   *   The command line.
   */
  protected function sh(string $snippet): string {
    return 'sh -c ' . escapeshellarg($snippet);
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

      if (is_dir($path)) {
        // @codeCoverageIgnoreStart
        $this->remove($path);

        continue;
        // @codeCoverageIgnoreEnd
      }

      unlink($path);
    }

    rmdir($dir);
  }

}
