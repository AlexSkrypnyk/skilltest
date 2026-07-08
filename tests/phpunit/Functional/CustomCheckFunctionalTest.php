<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\SkillTest\Contract\CustomCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class CustomCheckFunctionalTest.
 *
 * Exercises the real process path of the custom check runner against fixture
 * scripts, proving the documented arguments reach the script and that a JSON
 * verdict on stdout is rendered into the result.
 */
#[CoversClass(CustomCheck::class)]
#[Group('contract')]
final class CustomCheckFunctionalTest extends TestCase {

  /**
   * A real working directory the check scripts and their run execute in.
   */
  protected string $workdir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->workdir = dirname(__DIR__, 3) . '/.artifacts/tmp/customcheck-' . getmypid() . '-' . uniqid();

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

  public function testRealRunPassesArgumentsAndRendersJsonVerdict(): void {
    $script = "<?php\nfwrite(STDOUT, json_encode(['pass' => true, 'message' => 'verified', 'evidence' => (\$argv[1] ?? '') . '::' . (\$argv[2] ?? '')]));\nexit(0);\n";
    file_put_contents($this->workdir . '/check.php', $script);

    $result = (new CustomCheck($this->workdir))->run(['name' => 'echo-args', 'run' => 'php check.php'], 'transcript.jsonl', 'skills/foo');

    $this->assertNotNull($result);
    $this->assertTrue($result->pass);
    $this->assertSame('check.echo-args', $result->id);
    $this->assertSame('verified', $result->message);
    $this->assertSame('transcript.jsonl::skills/foo', $result->evidence);
  }

  public function testRealRunFailsByExitCodeWithoutJson(): void {
    $script = "<?php\nfwrite(STDERR, 'boom');\nexit(2);\n";
    file_put_contents($this->workdir . '/check.php', $script);

    $result = (new CustomCheck($this->workdir))->run(['name' => 'boom', 'run' => 'php check.php'], 'transcript.jsonl', 'skills/foo');

    $this->assertNotNull($result);
    $this->assertFalse($result->pass);
    $this->assertStringContainsString('failed (exit 2).', $result->message);
    $this->assertSame('', $result->evidence);
  }

  public function testRealRunTerminatesAScriptThatOutlivesItsTimeout(): void {
    $script = "<?php\nsleep(5);\nexit(0);\n";
    file_put_contents($this->workdir . '/check.php', $script);

    $result = (new CustomCheck($this->workdir, NULL, 0.5))->run(['name' => 'hang', 'run' => 'php check.php'], 'transcript.jsonl', 'skills/foo');

    $this->assertNotNull($result);
    $this->assertFalse($result->pass);
    $this->assertStringContainsString('failed (exit ' . CustomCheck::TIMEOUT_EXIT . ').', $result->message);
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
      if ($item === '.' || $item === '..') {
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
