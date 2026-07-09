<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\SkillTest\Process\ProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class ProcessRunnerTest.
 *
 * Exercises the real process path of the shared runner: it captures stdout and
 * the exit code, discards stderr, and terminates a command that overruns.
 */
#[CoversClass(ProcessRunner::class)]
#[Group('process')]
final class ProcessRunnerTest extends TestCase {

  /**
   * A real working directory the scripts execute in.
   */
  protected string $workdir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->workdir = dirname(__DIR__, 3) . '/.artifacts/tmp/processrunner-' . getmypid() . '-' . uniqid();

    if (!is_dir($this->workdir)) {
      mkdir($this->workdir, 0777, TRUE);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->remove($this->workdir);

    parent::tearDown();
  }

  public function testCapturesStdoutAndZeroExit(): void {
    file_put_contents($this->workdir . '/ok.php', "<?php\nfwrite(STDOUT, 'hello');\nexit(0);\n");

    [$exit_code, $stdout] = (new ProcessRunner(5.0))->run('php ok.php', $this->workdir);

    $this->assertSame(0, $exit_code);
    $this->assertSame('hello', $stdout);
  }

  public function testCapturesNonZeroExit(): void {
    file_put_contents($this->workdir . '/fail.php', "<?php\nexit(2);\n");

    [$exit_code, $stdout] = (new ProcessRunner(5.0))->run('php fail.php', $this->workdir);

    $this->assertSame(2, $exit_code);
    $this->assertSame('', $stdout);
  }

  public function testDiscardsStderr(): void {
    file_put_contents($this->workdir . '/noise.php', "<?php\nfwrite(STDERR, 'boom');\nfwrite(STDOUT, 'clean');\nexit(0);\n");

    [$exit_code, $stdout] = (new ProcessRunner(5.0))->run('php noise.php', $this->workdir);

    $this->assertSame(0, $exit_code);
    $this->assertSame('clean', $stdout);
  }

  public function testTerminatesOnTimeout(): void {
    file_put_contents($this->workdir . '/hang.php', "<?php\nsleep(5);\nexit(0);\n");

    [$exit_code] = (new ProcessRunner(0.5))->run('php hang.php', $this->workdir);

    $this->assertSame(ProcessRunner::TIMEOUT_EXIT, $exit_code);
  }

  /**
   * Recursively removes a directory tree.
   *
   * @param string $dir
   *   The directory to remove.
   */
  protected function remove(string $dir): void {
    if (!is_dir($dir)) {
      return;
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
        $this->remove($path);

        continue;
      }

      unlink($path);
    }

    rmdir($dir);
  }

}
