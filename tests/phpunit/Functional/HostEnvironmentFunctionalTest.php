<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Live\EnvironmentInterface;
use AlexSkrypnyk\SkillTest\Live\HostEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Class HostEnvironmentFunctionalTest.
 *
 * Runs the shared environment contract against a real host environment, then
 * asserts the host-specific promises: workspaces live under the project's
 * `.skilltest/tmp/` scratch area, and prepare/teardown create and remove that
 * area without disturbing a concurrent run's live workspaces.
 */
#[CoversClass(HostEnvironment::class)]
#[Group('live')]
final class HostEnvironmentFunctionalTest extends EnvironmentTestCase {

  /**
   * {@inheritdoc}
   */
  protected function createEnvironment(string $workspace_base): EnvironmentInterface {
    return new HostEnvironment($this->root, 1, 300.0, NULL, NULL, $workspace_base);
  }

  public function testWorkspacesLiveUnderSkilltestTmp(): void {
    $environment = new HostEnvironment($this->root, 1, 300.0);
    $environment->prepare();

    $workspace = $environment->setup('alpha', 'skills/alpha', ['fixture' => NULL, 'repos' => [], 'workdir' => NULL]);

    try {
      $this->assertStringStartsWith($this->root . '/' . HostEnvironment::WORKSPACE_DIR . '/ws-', $workspace->path());
    }
    finally {
      $environment->cleanup($workspace);
      $environment->teardown();
    }
  }

  public function testPrepareCreatesBaseAndIsIdempotent(): void {
    $environment = $this->createEnvironment($this->workspaceBase);

    $environment->prepare();
    $environment->prepare();

    $this->assertDirectoryExists($this->workspaceBase);

    $environment->teardown();
  }

  public function testPrepareThrowsWhenTheBaseCannotBeCreated(): void {
    // A regular file where a directory parent is needed makes mkdir fail.
    $blocker = $this->base . '/blocker';
    file_put_contents($blocker, 'x');
    $environment = $this->createEnvironment($blocker . '/tmp');

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('could not create the workspace base directory');

    $environment->prepare();
  }

  public function testTeardownRemovesTheEmptyBase(): void {
    $environment = $this->createEnvironment($this->workspaceBase);
    $environment->prepare();
    $workspace = $environment->setup('alpha', 'skills/alpha', ['fixture' => NULL, 'repos' => [], 'workdir' => NULL]);
    $environment->cleanup($workspace);

    $environment->teardown();

    $this->assertDirectoryDoesNotExist($this->workspaceBase);
  }

  public function testTeardownKeepsNonEmptyBase(): void {
    $environment = $this->createEnvironment($this->workspaceBase);
    $environment->prepare();
    $workspace = $environment->setup('alpha', 'skills/alpha', ['fixture' => NULL, 'repos' => [], 'workdir' => NULL]);

    $environment->teardown();

    $this->assertDirectoryExists($this->workspaceBase);
    $this->assertDirectoryExists($workspace->path());

    $environment->cleanup($workspace);
  }

  public function testTeardownToleratesMissingBase(): void {
    $environment = $this->createEnvironment($this->workspaceBase);

    $environment->teardown();

    $this->assertDirectoryDoesNotExist($this->workspaceBase);
  }

  public function testSetupCleansUpHalfBuiltWorkspaceOnFailure(): void {
    $environment = $this->createEnvironment($this->workspaceBase);
    $environment->prepare();

    try {
      $environment->setup('alpha', 'skills/alpha', ['fixture' => 'fixtures/missing.txt', 'repos' => [], 'workdir' => NULL]);
      $this->fail('Expected a ConfigException for the missing fixture.');
    }
    catch (ConfigException $config_exception) {
      $this->assertStringContainsString("fixture 'fixtures/missing.txt' was not found", $config_exception->getMessage());
    }

    $this->assertSame([], glob($this->workspaceBase . '/ws-*') ?: [], 'A failed assembly must leave no workspace behind.');

    $environment->teardown();
  }

}
