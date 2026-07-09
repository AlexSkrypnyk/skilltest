<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Live\DockerPreflight;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class DockerPreflightTest.
 *
 * Unit test for the docker preflight: the CLI, daemon, and credential checks
 * that gate a containerised run, exercised with an injected daemon probe and a
 * controlled PATH so no real Docker is required.
 */
#[CoversClass(DockerPreflight::class)]
final class DockerPreflightTest extends TestCase {

  /**
   * The temporary directory holding any fake binaries, removed on teardown.
   */
  protected string $dir = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->dir = dirname(__DIR__, 3) . '/.artifacts/tmp/dockerpre-' . getmypid() . '-' . uniqid();
    mkdir($this->dir, 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach (glob($this->dir . '/*') ?: [] as $file) {
      unlink($file);
    }

    if (is_dir($this->dir)) {
      rmdir($this->dir);
    }

    parent::tearDown();
  }

  public function testNoProblemWhenBinaryDaemonAndCredentialsPresent(): void {
    $preflight = new DockerPreflight(
      ['SKILLTEST_DOCKER' => 'docker', 'ANTHROPIC_API_KEY' => 'sk-x'],
      $this->dir,
      static fn(): array => [0, 'Server: 27.0'],
    );

    $this->assertNull($preflight->problem());
  }

  public function testMissingBinaryIsReported(): void {
    $preflight = new DockerPreflight(['PATH' => $this->dir, 'ANTHROPIC_API_KEY' => 'sk-x'], $this->dir, static fn(): array => [0, '']);

    $this->assertSame(DockerPreflight::PROBLEM_NO_BINARY, $preflight->problem());
  }

  public function testUnreachableDaemonIsReported(): void {
    $preflight = new DockerPreflight(
      ['SKILLTEST_DOCKER' => 'docker', 'ANTHROPIC_API_KEY' => 'sk-x'],
      $this->dir,
      static fn(): array => [1, 'Cannot connect to the Docker daemon'],
    );

    $this->assertSame(DockerPreflight::PROBLEM_DAEMON, $preflight->problem());
  }

  public function testMissingCredentialsIsReported(): void {
    $preflight = new DockerPreflight(['SKILLTEST_DOCKER' => 'docker'], $this->dir, static fn(): array => [0, '']);

    $this->assertSame(DockerPreflight::PROBLEM_NO_CREDENTIALS, $preflight->problem());
  }

  public function testOauthTokenSatisfiesCredentials(): void {
    $preflight = new DockerPreflight(['SKILLTEST_DOCKER' => 'docker', 'CLAUDE_CODE_OAUTH_TOKEN' => 'tok'], $this->dir, static fn(): array => [0, '']);

    $this->assertTrue($preflight->hasCredentials());
    $this->assertNull($preflight->problem());
  }

  public function testBinaryOverrideWins(): void {
    $preflight = new DockerPreflight(['SKILLTEST_DOCKER' => '  /custom/docker  '], $this->dir);

    $this->assertSame('/custom/docker', $preflight->binary());
  }

  public function testBinaryResolvedFromPath(): void {
    $docker = $this->dir . '/docker';
    file_put_contents($docker, "#!/bin/sh\nexit 0\n");
    chmod($docker, 0755);

    $preflight = new DockerPreflight(['PATH' => '/nonexistent-bin' . PATH_SEPARATOR . $this->dir], $this->dir);

    $this->assertSame($docker, $preflight->binary());
  }

  public function testBinaryNullWhenNotOnPath(): void {
    $preflight = new DockerPreflight(['PATH' => $this->dir], $this->dir);

    $this->assertNull($preflight->binary());
  }

  public function testUsesRealRunnerByDefault(): void {
    // With no injected runner the daemon probe runs a real process; a bogus
    // binary name exits non-zero, so the daemon is reported unreachable rather
    // than the constructor touching a real Docker.
    $preflight = new DockerPreflight(['SKILLTEST_DOCKER' => 'false', 'ANTHROPIC_API_KEY' => 'sk-x'], $this->dir);

    $this->assertSame(DockerPreflight::PROBLEM_DAEMON, $preflight->problem());
  }

}
