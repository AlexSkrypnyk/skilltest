<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Hooks\HookRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class HookRunnerFunctionalTest.
 *
 * Exercises the real process path of the hook runner against executable fixture
 * scripts, proving that the PreToolUse payload reaches the hook on stdin, that
 * the block/allow decision is asserted by exit code, that a hang trips the
 * timeout, and that a missing or non-executable script is a configuration
 * error.
 */
#[CoversClass(HookRunner::class)]
#[Group('hooks')]
final class HookRunnerFunctionalTest extends TestCase {

  /**
   * A real working directory the fixture hooks live and execute in.
   */
  protected string $workdir;

  /**
   * A hook that blocks `gh pr create` and allows everything else.
   */
  protected const string BROKER = "#!/usr/bin/env php\n<?php\n\$data = json_decode(stream_get_contents(STDIN), TRUE);\n\$command = \$data['tool_input']['command'] ?? '';\nif (strpos(\$command, 'gh pr create') !== FALSE) {\n  fwrite(STDERR, 'blocked: gh pr create is brokered');\n  exit(2);\n}\nexit(0);\n";

  /**
   * A hook that allows every call while logging a note to stderr.
   */
  protected const string LAX = "#!/usr/bin/env php\n<?php\nfwrite(STDERR, 'permitting: gh pr create');\nexit(0);\n";

  /**
   * A hook that hangs, to exercise the timeout path.
   */
  protected const string HANG = "#!/usr/bin/env php\n<?php\nsleep(5);\nexit(0);\n";

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->workdir = dirname(__DIR__, 3) . '/.artifacts/tmp/hookrunner-' . getmypid() . '-' . uniqid();

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

  public function testBlockAndAllowCasesPass(): void {
    $this->writeHook('broker.php', self::BROKER);
    $hooks = [
      ['script' => 'broker.php', 'cases' => [
        ['tool' => 'Bash', 'input' => ['command' => 'gh pr create --title x'], 'expect' => 'block'],
        ['tool' => 'Bash', 'input' => ['command' => 'gh pr view 1'], 'expect' => 'allow'],
      ]],
    ];

    $results = (new HookRunner($this->workdir))->run($hooks);

    $this->assertCount(2, $results);
    $this->assertTrue($results[0]->pass, $results[0]->message);
    $this->assertTrue($results[1]->pass, $results[1]->message);
    $this->assertSame("hook 'broker.php' blocked Bash input as expected.", $results[0]->message);
    $this->assertSame("hook 'broker.php' allowed Bash input as expected.", $results[1]->message);
  }

  public function testWrongDecisionFailsWithStderrAndExitCode(): void {
    $this->writeHook('lax.php', self::LAX);
    $hooks = [['script' => 'lax.php', 'cases' => [['tool' => 'Bash', 'input' => ['command' => 'gh pr create'], 'expect' => 'block']]]];

    $results = (new HookRunner($this->workdir))->run($hooks);

    $this->assertFalse($results[0]->pass);
    $this->assertStringContainsString('expected block (exit 2) but got exit 0', $results[0]->message);
    $this->assertStringContainsString('stderr: permitting: gh pr create', $results[0]->message);
  }

  public function testHangingHookTripsTimeout(): void {
    $this->writeHook('hang.php', self::HANG);
    $hooks = [['script' => 'hang.php', 'cases' => [['tool' => 'Bash', 'input' => ['command' => 'anything'], 'expect' => 'block']]]];

    $results = (new HookRunner($this->workdir, NULL, NULL, 0.5))->run($hooks);

    $this->assertFalse($results[0]->pass);
    $this->assertStringContainsString('got exit ' . HookRunner::TIMEOUT_EXIT, $results[0]->message);
    $this->assertStringContainsString('timed out after 0.5s', $results[0]->message);
  }

  public function testMissingScriptIsConfigError(): void {
    $hooks = [['script' => 'absent.php', 'cases' => [['tool' => 'Bash', 'input' => ['command' => 'x'], 'expect' => 'block']]]];

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('hook script not found or not executable: absent.php');

    (new HookRunner($this->workdir))->run($hooks);
  }

  public function testNonExecutableScriptIsConfigError(): void {
    $this->writeHook('plain.php', self::BROKER, 0644);
    $hooks = [['script' => 'plain.php', 'cases' => [['tool' => 'Bash', 'input' => ['command' => 'x'], 'expect' => 'block']]]];

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('hook script not found or not executable: plain.php');

    (new HookRunner($this->workdir))->run($hooks);
  }

  /**
   * Writes an executable fixture hook into the working directory.
   *
   * @param string $name
   *   The script filename.
   * @param string $body
   *   The script body, including its shebang.
   * @param int $mode
   *   The file mode; the default is executable.
   */
  protected function writeHook(string $name, string $body, int $mode = 0755): void {
    $path = $this->workdir . '/' . $name;
    file_put_contents($path, $body);
    chmod($path, $mode);
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
