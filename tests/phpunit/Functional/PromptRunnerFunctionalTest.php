<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\SkillTest\Ai\PromptRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class PromptRunnerFunctionalTest.
 *
 * Exercises the real process path of the prompt seam against a fake agent
 * binary, proving a successful reply is returned verbatim and a non-zero exit
 * yields NULL.
 */
#[CoversClass(PromptRunner::class)]
#[Group('ai')]
final class PromptRunnerFunctionalTest extends TestCase {

  /**
   * A real working directory the fake binary lives in.
   */
  protected string $workdir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->workdir = dirname(__DIR__, 3) . '/.artifacts/tmp/promptrunner-' . getmypid() . '-' . uniqid();

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

  public function testReturnsReplyFromRealBinary(): void {
    $binary = $this->fakeAgent("<?php\nfwrite(STDOUT, 'reply for: ' . (\$argv[2] ?? ''));\nexit(0);\n");

    $result = (new PromptRunner(NULL, $binary))->run('draft this');

    $this->assertSame('reply for: draft this', $result);
  }

  public function testReturnsNullWhenBinaryExitsNonZero(): void {
    $binary = $this->fakeAgent("<?php\nfwrite(STDERR, 'not authenticated');\nexit(1);\n");

    $this->assertNull((new PromptRunner(NULL, $binary))->run('draft this'));
  }

  /**
   * Writes a fake agent script and returns the command prefix to invoke it.
   *
   * @param string $body
   *   The PHP source of the fake agent.
   *
   * @return string
   *   The `php <path>` command prefix.
   */
  protected function fakeAgent(string $body): string {
    $path = $this->workdir . '/fake-claude.php';
    file_put_contents($path, $body);

    return 'php ' . escapeshellarg($path);
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
