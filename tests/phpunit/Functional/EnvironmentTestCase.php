<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\SkillTest\Live\EnvironmentInterface;
use AlexSkrypnyk\SkillTest\Live\TrialWorkspace;
use AlexSkrypnyk\SkillTest\Tests\Traits\DirectoryCleanupTrait;
use AlexSkrypnyk\SkillTest\Tests\Traits\GitFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class EnvironmentTestCase.
 *
 * The shared contract every {@see EnvironmentInterface} must satisfy, written
 * against the interface so a new implementation (e.g. docker) reuses it
 * verbatim by subclassing and supplying its own {@see createEnvironment}. It
 * asserts only what an environment guarantees regardless of where it runs:
 * setup assembles the workspace (fixture, skill, repos), exec runs each
 * command in its own workspace's context and returns keyed outcomes, and
 * cleanup removes the workspace. Implementation-specific details (where the
 * workspace base lives, how prepare/teardown manage it) belong in the concrete
 * subclass.
 */
#[Group('live')]
abstract class EnvironmentTestCase extends TestCase {

  use DirectoryCleanupTrait;
  use GitFixtureTrait;

  /**
   * The temporary base holding the repo root, source clone, and workspaces.
   */
  protected string $base;

  /**
   * The repository root the skill and fixtures live under.
   */
  protected string $root;

  /**
   * The base directory the environment assembles workspaces under.
   */
  protected string $workspaceBase;

  /**
   * Builds the environment under test over the shared temp repo.
   *
   * @param string $workspace_base
   *   The base directory workspaces must be assembled under.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\EnvironmentInterface
   *   The environment implementation to exercise.
   */
  abstract protected function createEnvironment(string $workspace_base): EnvironmentInterface;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->base = dirname(__DIR__, 3) . '/.artifacts/tmp/envtest-' . getmypid() . '-' . uniqid();
    $this->root = $this->base . '/root';
    $this->source = $this->base . '/source';
    $this->workspaceBase = $this->base . '/ws';

    mkdir($this->root . '/skills/alpha/fixtures', 0777, TRUE);
    file_put_contents($this->root . '/skills/alpha/SKILL.md', "---\nname: alpha\n---\n# Body\n");
    file_put_contents($this->root . '/skills/alpha/fixtures/seed.txt', 'seed');

    $this->initSource();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->remove($this->base);

    parent::tearDown();
  }

  public function testSetupExecCleanupBracketsTrial(): void {
    $environment = $this->createEnvironment($this->workspaceBase);
    $environment->prepare();

    $workspace = $environment->setup('alpha', 'skills/alpha', ['fixture' => 'fixtures/seed.txt', 'repos' => [], 'workdir' => NULL]);

    $this->assertInstanceOf(TrialWorkspace::class, $workspace);
    $this->assertFileExists($workspace->path() . '/seed.txt');
    $this->assertFileExists($workspace->path() . '/.claude/skills/alpha/SKILL.md');

    $outcomes = $environment->exec([7 => [$workspace, 'cat seed.txt']]);

    $this->assertArrayHasKey(7, $outcomes);
    $this->assertSame(0, $outcomes[7][0]);
    $this->assertSame('seed', $outcomes[7][1]);

    $environment->cleanup($workspace);
    $this->assertDirectoryDoesNotExist($workspace->path());

    $environment->teardown();
  }

  public function testExecRunsBatchPreservingKeys(): void {
    $environment = $this->createEnvironment($this->workspaceBase);
    $environment->prepare();

    $one = $environment->setup('alpha', 'skills/alpha', ['fixture' => NULL, 'repos' => [], 'workdir' => NULL]);
    $two = $environment->setup('alpha', 'skills/alpha', ['fixture' => NULL, 'repos' => [], 'workdir' => NULL]);

    try {
      $outcomes = $environment->exec(['a' => [$one, 'printf one'], 'b' => [$two, 'printf two']]);

      $this->assertSame(['a', 'b'], array_keys($outcomes));
      $this->assertSame('one', $outcomes['a'][1]);
      $this->assertSame('two', $outcomes['b'][1]);
    }
    finally {
      $environment->cleanup($one);
      $environment->cleanup($two);
      $environment->teardown();
    }
  }

  public function testExecRunsInTheWorkdirContext(): void {
    $environment = $this->createEnvironment($this->workspaceBase);
    $environment->prepare();

    $workspace = $environment->setup('alpha', 'skills/alpha', [
      'fixture' => NULL,
      'repos' => [['source' => $this->source, 'commit' => TrialWorkspace::DEFAULT_COMMIT, 'dest' => 'work']],
      'workdir' => 'work',
    ]);

    try {
      $this->assertSame($workspace->path() . '/work', $workspace->agentDir());

      $environment->exec([1 => [$workspace, 'printf proof > marker.txt']]);

      $this->assertFileExists($workspace->agentDir() . '/marker.txt');
      $this->assertSame('proof', (string) file_get_contents($workspace->agentDir() . '/marker.txt'));
    }
    finally {
      $environment->cleanup($workspace);
      $environment->teardown();
    }
  }

}
