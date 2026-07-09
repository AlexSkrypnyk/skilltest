<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\SkillTest\Config\DockerConfig;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Live\DockerEnvironment;
use AlexSkrypnyk\SkillTest\Live\ProcessPool;
use AlexSkrypnyk\SkillTest\Live\TrialWorkspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class DockerEnvironmentTest.
 *
 * Hermetic test for the docker environment: the pool and docker seams are
 * injected so every emitted `docker` command - run, image prep, timeout kill,
 * teardown sweep - is asserted precisely without a real daemon, and workspaces
 * are assembled on the real filesystem exactly as a live run assembles them.
 */
#[CoversClass(DockerEnvironment::class)]
#[Group('live')]
final class DockerEnvironmentTest extends TestCase {

  /**
   * The temporary base holding the repo root and workspaces.
   */
  protected string $base;

  /**
   * The repository root the skill and fixtures live under.
   */
  protected string $root;

  /**
   * The workspace base the environment assembles under.
   */
  protected string $workspaceBase;

  /**
   * The docker run commands the pool received, flattened across batches.
   *
   * @var string[]
   */
  protected array $poolCommands = [];

  /**
   * The docker admin commands the single-command runner received.
   *
   * @var string[]
   */
  protected array $dockerCommands = [];

  /**
   * Decides each pool command's result; overridable per test.
   *
   * @var \Closure(string): array{0: int, 1: string, 2: int}
   */
  protected \Closure $poolHandler;

  /**
   * Decides each docker admin command's result; overridable per test.
   *
   * @var \Closure(string): array{0: int, 1: string}
   */
  protected \Closure $dockerHandler;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->base = dirname(__DIR__, 3) . '/.artifacts/tmp/dockerenv-' . getmypid() . '-' . uniqid();
    $this->root = $this->base . '/root';
    $this->workspaceBase = $this->base . '/ws';

    mkdir($this->root . '/skills/alpha/fixtures', 0777, TRUE);
    file_put_contents($this->root . '/skills/alpha/SKILL.md', "---\nname: alpha\n---\n# Body\n");
    file_put_contents($this->root . '/skills/alpha/fixtures/seed.txt', 'seed');

    $this->poolHandler = static fn(string $command): array => [0, 'agent-output', 5];
    $this->dockerHandler = static fn(string $command): array => [0, ''];
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->remove($this->base);

    parent::tearDown();
  }

  public function testPrepareCreatesBaseAndUsesImageWhenNoSetup(): void {
    $environment = $this->environment();
    $environment->prepare();

    $this->assertDirectoryExists($this->workspaceBase);
    // With no setup the image is only ensured present, never built.
    $this->assertContains('docker image inspect ' . escapeshellarg('my/image:1'), $this->dockerCommands);
    $this->assertSame([], array_values(array_filter($this->dockerCommands, static fn(string $c): bool => str_contains($c, ' build '))));
  }

  public function testPrepareThrowsWhenBaseCannotBeCreated(): void {
    $blocker = $this->base . '/blocker';
    mkdir($this->base, 0777, TRUE);
    file_put_contents($blocker, 'x');
    $environment = $this->environment(workspace_base: $blocker . '/tmp');

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('could not create the workspace base directory');

    $environment->prepare();
  }

  public function testPreparePullsMissingImage(): void {
    $this->dockerHandler = static fn(string $command): array => str_contains($command, 'image inspect') ? [1, ''] : [0, ''];
    $environment = $this->environment();

    $environment->prepare();

    $this->assertContains('docker pull ' . escapeshellarg('my/image:1'), $this->dockerCommands);
  }

  public function testPrepareThrowsWhenPullFails(): void {
    $this->dockerHandler = static fn(string $command): array => [1, 'no such image'];
    $environment = $this->environment();

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage("could not pull the docker image 'my/image:1'");

    $environment->prepare();
  }

  public function testPrepareBuildsRunImageWhenSetupIsGiven(): void {
    $environment = $this->environment(setup: 'RUN apt-get install -y php');
    $environment->prepare();

    $built = array_values(array_filter($this->dockerCommands, static fn(string $c): bool => str_contains($c, ' build ')));
    $this->assertCount(1, $built);
    $this->assertStringContainsString('-t ' . escapeshellarg('skilltest-run-testrun'), $built[0]);
    // The generated Dockerfile and its context directory are cleaned up.
    $this->assertSame([], glob($this->workspaceBase . '/build-*') ?: []);
  }

  public function testPrepareThrowsWhenBuildFails(): void {
    $this->dockerHandler = static fn(string $command): array => str_contains($command, ' build ') ? [1, 'boom'] : [0, ''];
    $environment = $this->environment(setup: 'RUN false');

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage("could not build the docker run image from 'my/image:1'");

    $environment->prepare();
  }

  public function testExecRunsCommandInsideContainer(): void {
    $environment = $this->environment(cpus: 1.5, memory_mb: 512, env: ['ANTHROPIC_API_KEY' => 'sk-x']);
    $environment->prepare();
    $workspace = $this->workspace($environment);

    $environment->exec([7 => [$workspace, 'cat seed.txt']]);

    $command = $this->poolCommands[0];
    $this->assertStringStartsWith('docker run --rm --name ', $command);
    $this->assertStringContainsString('skilltest-testrun-', $command);
    $this->assertStringContainsString('--label ' . escapeshellarg('skilltest.run=testrun'), $command);
    $this->assertStringContainsString('--cpus=1.5', $command);
    $this->assertStringContainsString('--memory=512m', $command);
    $this->assertStringContainsString('-e ANTHROPIC_API_KEY', $command);
    $this->assertStringNotContainsString('CLAUDE_CODE_OAUTH_TOKEN', $command);
    $this->assertStringContainsString('-v ' . escapeshellarg($workspace->agentDir() . ':/work') . ' -w /work', $command);
    $this->assertStringContainsString(escapeshellarg('my/image:1') . ' sh -c ' . escapeshellarg('cat seed.txt'), $command);

    $environment->cleanup($workspace);
    $environment->teardown();
  }

  public function testExecPreservesKeysAndPassesStdoutThrough(): void {
    $environment = $this->environment();
    $environment->prepare();
    $one = $this->workspace($environment);
    $two = $this->workspace($environment);

    $this->poolHandler = static fn(string $command): array => str_contains($command, 'one') ? [0, 'first', 5] : [0, 'second', 6];

    $outcomes = $environment->exec(['a' => [$one, 'echo one'], 'b' => [$two, 'echo two']]);

    $this->assertSame(['a', 'b'], array_keys($outcomes));
    $this->assertSame([0, 'first', 5], $outcomes['a']);
    $this->assertSame([0, 'second', 6], $outcomes['b']);
  }

  public function testExecOmitsLimitAndCredentialFlagsWhenUnset(): void {
    $environment = $this->environment();
    $environment->prepare();
    $workspace = $this->workspace($environment);

    $environment->exec([0 => [$workspace, 'true']]);

    $command = $this->poolCommands[0];
    $this->assertStringNotContainsString('--cpus', $command);
    $this->assertStringNotContainsString('--memory', $command);
    $this->assertStringNotContainsString('-e ', $command);
  }

  public function testExecKillsContainerAndDiagnosesOnTimeout(): void {
    $this->poolHandler = static fn(string $command): array => [ProcessPool::TIMEOUT_EXIT, '', 300000];
    $environment = $this->environment();
    $environment->prepare();
    $workspace = $this->workspace($environment);

    $outcomes = $environment->exec([0 => [$workspace, 'sleep 999']]);

    $this->assertSame(ProcessPool::TIMEOUT_EXIT, $outcomes[0][0]);
    $this->assertStringContainsString('timed out and its container was killed', $outcomes[0][1]);
    $killed = array_values(array_filter($this->dockerCommands, static fn(string $c): bool => str_contains($c, 'rm -f') && str_contains($c, 'skilltest-testrun-')));
    $this->assertCount(1, $killed);
  }

  public function testExecDiagnosesSilentFailure(): void {
    $this->poolHandler = static fn(string $command): array => [125, '', 4];
    $environment = $this->environment();
    $environment->prepare();
    $workspace = $this->workspace($environment);

    $outcomes = $environment->exec([0 => [$workspace, 'true']]);

    $this->assertStringContainsString('the container exited with code 125 and produced no output', $outcomes[0][1]);
  }

  public function testCleanupRemovesWorkspace(): void {
    $environment = $this->environment();
    $environment->prepare();
    $workspace = $this->workspace($environment);
    $path = $workspace->path();

    $environment->cleanup($workspace);

    $this->assertDirectoryDoesNotExist($path);
  }

  public function testRetentionKeepsWorkspacesAndRecordsPaths(): void {
    $environment = $this->environment(keep: TRUE);
    $environment->prepare();
    $workspace = $this->workspace($environment);

    $environment->cleanup($workspace);

    $this->assertDirectoryExists($workspace->path());
    $this->assertSame([$workspace->path()], $environment->keptWorkspaces());
  }

  public function testKeptWorkspacesEmptyByDefault(): void {
    $this->assertSame([], $this->environment()->keptWorkspaces());
  }

  public function testTeardownSweepsContainersRemovesImageAndBase(): void {
    $this->dockerHandler = static fn(string $command): array => str_contains($command, 'ps -aq') ? [0, "cid1\ncid2\n"] : [0, ''];
    $environment = $this->environment(setup: 'RUN true');
    $environment->prepare();

    $environment->teardown();

    $swept = array_values(array_filter($this->dockerCommands, static fn(string $c): bool => str_contains($c, 'rm -f') && str_contains($c, 'cid1')));
    $this->assertCount(1, $swept);
    $this->assertStringContainsString(escapeshellarg('cid1') . ' ' . escapeshellarg('cid2'), $swept[0]);
    $this->assertContains('docker rmi -f ' . escapeshellarg('skilltest-run-testrun'), $this->dockerCommands);
    $this->assertDirectoryDoesNotExist($this->workspaceBase);
  }

  public function testTeardownWithNoContainersOrBuiltImageKeepsNonEmptyBase(): void {
    $environment = $this->environment();
    $environment->prepare();
    $workspace = $this->workspace($environment);

    $environment->teardown();

    $this->assertDirectoryExists($this->workspaceBase);
    $this->assertDirectoryExists($workspace->path());
    $this->assertNotContains('docker rmi -f ' . escapeshellarg('skilltest-run-testrun'), $this->dockerCommands);

    $environment->cleanup($workspace);
  }

  public function testSetupCleansUpHalfBuiltWorkspaceOnFailure(): void {
    $environment = $this->environment();
    $environment->prepare();

    try {
      $environment->setup('alpha', 'skills/alpha', ['fixture' => 'fixtures/missing.txt', 'repos' => [], 'workdir' => NULL]);
      $this->fail('Expected a ConfigException for the missing fixture.');
    }
    catch (ConfigException $config_exception) {
      $this->assertStringContainsString("fixture 'fixtures/missing.txt' was not found", $config_exception->getMessage());
    }

    $this->assertSame([], glob($this->workspaceBase . '/ws-*') ?: []);
  }

  public function testHookRunnerRunsHookInsideContainer(): void {
    $environment = $this->environment(env: ['CLAUDE_CODE_OAUTH_TOKEN' => 'tok']);
    $environment->prepare();

    $runner = $environment->hookRunner();
    [$exit_code] = $runner('php reset.php', $this->root);

    $this->assertSame(0, $exit_code);
    $hook = array_values(array_filter($this->dockerCommands, static fn(string $c): bool => str_contains($c, 'reset.php')));
    $this->assertCount(1, $hook);
    $this->assertStringContainsString('docker run --rm --name ', $hook[0]);
    $this->assertStringContainsString('skilltest-testrun-', $hook[0]);
    $this->assertStringContainsString('--label ' . escapeshellarg('skilltest.run=testrun'), $hook[0]);
    $this->assertStringContainsString('-e CLAUDE_CODE_OAUTH_TOKEN', $hook[0]);
    $this->assertStringContainsString('-v ' . escapeshellarg($this->root . ':/work') . ' -w /work', $hook[0]);
    $this->assertStringContainsString('sh -c ' . escapeshellarg('php reset.php'), $hook[0]);
  }

  public function testHookRunnerRemovesContainerOnTimeout(): void {
    $this->dockerHandler = static fn(string $command): array => str_contains($command, 'run --rm') ? [ProcessPool::TIMEOUT_EXIT, ''] : [0, ''];
    $environment = $this->environment();
    $environment->prepare();

    [$exit_code] = ($environment->hookRunner())('sleep 999', $this->root);

    $this->assertSame(ProcessPool::TIMEOUT_EXIT, $exit_code);
    $killed = array_values(array_filter($this->dockerCommands, static fn(string $c): bool => str_contains($c, 'rm -f') && str_contains($c, 'skilltest-testrun-')));
    $this->assertCount(1, $killed);
  }

  /**
   * Builds a docker environment over the temp repo with capturing seams.
   *
   * @param string $setup
   *   The docker setup steps.
   * @param float|null $cpus
   *   The cpu limit.
   * @param int|null $memory_mb
   *   The memory limit.
   * @param array<string, string> $env
   *   The environment map for credential pass-through.
   * @param bool $keep
   *   Whether workspaces are retained.
   * @param string|null $workspace_base
   *   A workspace base override.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\DockerEnvironment
   *   The environment under test.
   */
  protected function environment(string $setup = '', ?float $cpus = NULL, ?int $memory_mb = NULL, array $env = [], bool $keep = FALSE, ?string $workspace_base = NULL): DockerEnvironment {
    $config = new DockerConfig('my/image:1', $setup, $cpus, $memory_mb);

    $pool = function (array $commands): array {
      $results = [];
      foreach ($commands as $key => [$command]) {
        $this->poolCommands[] = $command;
        $results[$key] = ($this->poolHandler)($command);
      }

      return $results;
    };

    $docker = function (string $command): array {
      $this->dockerCommands[] = $command;

      return ($this->dockerHandler)($command);
    };

    return new DockerEnvironment($this->root, 1, 300.0, $config, 'docker', $env, $pool, $docker, NULL, $workspace_base ?? $this->workspaceBase, $keep, 'testrun');
  }

  /**
   * Assembles a fixture-only workspace through the environment.
   *
   * @param \AlexSkrypnyk\SkillTest\Live\DockerEnvironment $environment
   *   The environment under test.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\TrialWorkspace
   *   The assembled workspace.
   */
  protected function workspace(DockerEnvironment $environment): TrialWorkspace {
    return $environment->setup('alpha', 'skills/alpha', ['fixture' => 'fixtures/seed.txt', 'repos' => [], 'workdir' => NULL]);
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
