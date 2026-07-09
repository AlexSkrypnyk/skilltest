<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Live\TrialWorkspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class TrialWorkspaceFunctionalTest.
 *
 * Assembles and tears down a real workspace: a copied fixture, a detached
 * git worktree at the declared dest, the installed skill, and full cleanup
 * including the worktree bookkeeping.
 */
#[CoversClass(TrialWorkspace::class)]
#[Group('live')]
final class TrialWorkspaceFunctionalTest extends TestCase {

  /**
   * The temporary base holding the repo root, source clone, and workspace.
   */
  protected string $base;

  /**
   * The repository root the skill and fixtures live under.
   */
  protected string $root;

  /**
   * The git clone used as a repo source.
   */
  protected string $source;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->base = dirname(__DIR__, 3) . '/.artifacts/tmp/trialws-' . getmypid() . '-' . uniqid();
    $this->root = $this->base . '/root';
    $this->source = $this->base . '/source';

    mkdir($this->root . '/skills/alpha/fixtures/tree', 0777, TRUE);
    file_put_contents($this->root . '/skills/alpha/SKILL.md', "---\nname: alpha\n---\n# Body\n");
    file_put_contents($this->root . '/skills/alpha/fixtures/seed.txt', 'seed');
    file_put_contents($this->root . '/skills/alpha/fixtures/tree/nested.txt', 'nested');

    $this->initSource();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->remove($this->base);

    parent::tearDown();
  }

  public function testAssemblesFixtureFileReposSkillAndWorkdir(): void {
    $workspace = $this->workspace('ws', [
      'fixture' => 'fixtures/seed.txt',
      'repos' => [['source' => $this->source, 'commit' => TrialWorkspace::DEFAULT_COMMIT, 'dest' => 'checkout']],
      'workdir' => 'checkout',
    ]);

    $workspace->assemble();

    $this->assertFileExists($workspace->path() . '/seed.txt');
    $this->assertFileExists($workspace->path() . '/checkout/hello.txt');
    $this->assertFileExists($workspace->path() . '/checkout/.git');
    $this->assertFileExists($workspace->path() . '/.claude/skills/alpha/SKILL.md');
    $this->assertSame($workspace->path() . '/checkout', $workspace->agentDir());
  }

  public function testAssemblesFixtureDirectoryContents(): void {
    $workspace = $this->workspace('ws-dir', ['fixture' => 'fixtures/tree', 'repos' => [], 'workdir' => NULL]);

    $workspace->assemble();

    $this->assertFileExists($workspace->path() . '/nested.txt');
    $this->assertSame($workspace->path(), $workspace->agentDir());
  }

  public function testCleanupRemovesWorkspaceAndWorktree(): void {
    $workspace = $this->workspace('ws-clean', [
      'fixture' => NULL,
      'repos' => [['source' => $this->source, 'commit' => TrialWorkspace::DEFAULT_COMMIT, 'dest' => 'checkout']],
      'workdir' => NULL,
    ]);
    $workspace->assemble();
    $this->assertDirectoryExists($workspace->path() . '/checkout');

    $workspace->cleanup();

    $this->assertDirectoryDoesNotExist($workspace->path());
    $this->assertStringNotContainsString('checkout', $this->git('worktree list', $this->source));
  }

  public function testCleanupWithoutAssembleIsSafe(): void {
    $workspace = $this->workspace('ws-never', ['fixture' => NULL, 'repos' => [], 'workdir' => NULL]);

    $workspace->cleanup();

    $this->assertDirectoryDoesNotExist($workspace->path());
  }

  public function testMissingFixtureThrows(): void {
    $workspace = $this->workspace('ws-missing', ['fixture' => 'fixtures/nope.txt', 'repos' => [], 'workdir' => NULL]);

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage("fixture 'fixtures/nope.txt' was not found.");

    $workspace->assemble();
  }

  public function testWorktreeFailureThrows(): void {
    $workspace = $this->workspace('ws-bad', [
      'fixture' => NULL,
      'repos' => [['source' => $this->source, 'commit' => 'no-such-ref-xyz', 'dest' => 'checkout']],
      'workdir' => NULL,
    ]);

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('could not create a worktree');

    $workspace->assemble();
  }

  /**
   * Builds a workspace for a set of parsed inputs.
   *
   * @param string $name
   *   The workspace subdirectory name.
   * @param array{fixture: ?string, repos: array<int, array{source: string, commit: string, dest: string}>, workdir: ?string} $inputs
   *   The parsed inputs.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\TrialWorkspace
   *   The workspace.
   */
  protected function workspace(string $name, array $inputs): TrialWorkspace {
    return new TrialWorkspace($this->base . '/' . $name, $this->root, 'alpha', 'skills/alpha', $inputs);
  }

  /**
   * Initialises the git source clone with one commit.
   */
  protected function initSource(): void {
    mkdir($this->source, 0777, TRUE);
    file_put_contents($this->source . '/hello.txt', 'hi');
    $this->git('init', $this->source);
    $this->git('config user.email test@example.com', $this->source);
    $this->git('config user.name Test', $this->source);
    $this->git('add -A', $this->source);
    $this->git('commit -m seed', $this->source);
  }

  /**
   * Runs a git command in a directory and returns its output.
   *
   * @param string $args
   *   The git arguments.
   * @param string $cwd
   *   The working directory.
   *
   * @return string
   *   The combined command output.
   */
  protected function git(string $args, string $cwd): string {
    $output = [];
    exec('git -C ' . escapeshellarg($cwd) . ' ' . $args . ' 2>&1', $output);

    return implode("\n", $output);
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

      if (is_dir($path) && !is_link($path)) {
        $this->remove($path);

        continue;
      }

      unlink($path);
    }

    rmdir($dir);
  }

}
