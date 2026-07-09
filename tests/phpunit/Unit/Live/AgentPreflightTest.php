<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Live\AgentPreflight;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class AgentPreflightTest.
 *
 * Unit test for the agent binary and credential preflight.
 */
#[CoversClass(AgentPreflight::class)]
final class AgentPreflightTest extends TestCase {

  /**
   * A real working directory holding fake binaries and a fake home.
   */
  protected string $workdir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->workdir = dirname(__DIR__, 4) . '/.artifacts/tmp/preflight-' . getmypid() . '-' . uniqid();
    mkdir($this->workdir . '/bin', 0777, TRUE);
    mkdir($this->workdir . '/home/.claude', 0777, TRUE);

    file_put_contents($this->workdir . '/bin/claude', "#!/bin/sh\nexit 0\n");
    chmod($this->workdir . '/bin/claude', 0755);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->remove($this->workdir);

    parent::tearDown();
  }

  public function testExplicitOverrideIsUsedVerbatim(): void {
    $preflight = new AgentPreflight([AgentPreflight::ENV_AGENT => 'php /stub.php', 'ANTHROPIC_API_KEY' => 'sk-test-key']);

    $this->assertSame('php /stub.php', $preflight->binary());
    $this->assertNull($preflight->problem());
  }

  public function testResolvesClaudeOnPath(): void {
    $preflight = new AgentPreflight(['PATH' => $this->workdir . '/bin', 'CLAUDE_CODE_OAUTH_TOKEN' => 'oauth-token']);

    $this->assertSame($this->workdir . '/bin/claude', $preflight->binary());
    $this->assertNull($preflight->problem());
  }

  public function testMissingBinaryIsReported(): void {
    $preflight = new AgentPreflight(['PATH' => $this->workdir . '/empty', 'ANTHROPIC_API_KEY' => 'sk-test-key']);

    $this->assertNull($preflight->binary());
    $this->assertSame(AgentPreflight::PROBLEM_NO_BINARY, $preflight->problem());
  }

  public function testMissingCredentialsIsReported(): void {
    $preflight = new AgentPreflight([AgentPreflight::ENV_AGENT => 'claude', 'HOME' => $this->workdir . '/nowhere']);

    $this->assertFalse($preflight->hasCredentials());
    $this->assertSame(AgentPreflight::PROBLEM_NO_CREDENTIALS, $preflight->problem());
  }

  public function testAuthenticatedHomeSatisfiesCredentials(): void {
    $preflight = new AgentPreflight([AgentPreflight::ENV_AGENT => 'claude', 'HOME' => $this->workdir . '/home']);

    $this->assertTrue($preflight->hasCredentials());
    $this->assertNull($preflight->problem());
  }

  public function testBlankOverrideFallsBackToPathSearch(): void {
    $preflight = new AgentPreflight([AgentPreflight::ENV_AGENT => '  ', 'PATH' => $this->workdir . '/bin', 'ANTHROPIC_API_KEY' => 'sk-test-key']);

    $this->assertSame($this->workdir . '/bin/claude', $preflight->binary());
  }

  public function testPathSearchSkipsNonExecutableAndEmptyEntries(): void {
    mkdir($this->workdir . '/plain', 0777, TRUE);
    file_put_contents($this->workdir . '/plain/claude', "text\n");
    chmod($this->workdir . '/plain/claude', 0644);

    $env = ['PATH' => '::' . $this->workdir . '/plain:' . $this->workdir . '/bin', 'ANTHROPIC_API_KEY' => 'sk-test-key'];
    $preflight = new AgentPreflight($env);

    $this->assertSame($this->workdir . '/bin/claude', $preflight->binary());
  }

  public function testBlankCredentialValuesDoNotCount(): void {
    $preflight = new AgentPreflight([AgentPreflight::ENV_AGENT => 'claude', 'ANTHROPIC_API_KEY' => '', 'CLAUDE_CODE_OAUTH_TOKEN' => '   ', 'HOME' => '']);

    $this->assertFalse($preflight->hasCredentials());
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
        $this->remove($path);

        continue;
      }

      unlink($path);
    }

    rmdir($dir);
  }

}
