<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\SkillTest\Process\ProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class ProcessRunnerFunctionalTest.
 *
 * Exercises the real process path of the shared runner: captured stdout, a
 * propagated non-zero exit, and termination of a script that outlives its
 * timeout.
 */
#[CoversClass(ProcessRunner::class)]
#[Group('process')]
final class ProcessRunnerFunctionalTest extends TestCase {

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
    file_put_contents($this->workdir . '/script.php', "<?php\nfwrite(STDOUT, 'captured output');\nexit(0);\n");

    [$exit_code, $stdout] = (new ProcessRunner())->run('php script.php', $this->workdir);

    $this->assertSame(0, $exit_code);
    $this->assertSame('captured output', $stdout);
  }

  public function testPropagatesNonZeroExit(): void {
    file_put_contents($this->workdir . '/script.php', "<?php\nexit(3);\n");

    [$exit_code, $stdout] = (new ProcessRunner())->run('php script.php', $this->workdir);

    $this->assertSame(3, $exit_code);
    $this->assertSame('', $stdout);
  }

  public function testTerminatesHungScriptAtTimeout(): void {
    file_put_contents($this->workdir . '/script.php', "<?php\nsleep(5);\nexit(0);\n");

    [$exit_code] = (new ProcessRunner(0.5))->run('php script.php', $this->workdir);

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
      // @codeCoverageIgnoreStart
      return;
      // @codeCoverageIgnoreEnd
    }

    foreach (scandir($dir) ?: [] as $item) {
      if ($item === '.' || $item === '..') {
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
