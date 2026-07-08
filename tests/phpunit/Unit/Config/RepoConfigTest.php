<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Config;

use AlexSkrypnyk\SkillTest\Config\RepoConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class RepoConfigTest.
 *
 * Unit test for the repo-level configuration value object.
 */
#[CoversClass(RepoConfig::class)]
final class RepoConfigTest extends TestCase {

  public function testDefaults(): void {
    $repo = RepoConfig::fromArray([]);

    $this->assertSame(['skills'], $repo->skillsPaths);
    $this->assertSame('eval.yaml', $repo->evalFile);
    $this->assertSame([], $repo->exclude);
    $this->assertSame([], $repo->aliases);
    $this->assertSame([], $repo->guards);
    $this->assertSame([], $repo->hooks);
    $this->assertSame([], $repo->commandResolve);
    $this->assertSame([], $repo->modelAliases);
    $this->assertSame([], $repo->ladder);
    $this->assertNull($repo->defaultModel);
    $this->assertNull($repo->judgeModel);
    $this->assertSame('host', $repo->environment);
    $this->assertSame([], $repo->report);
  }

  public function testFull(): void {
    $repo = RepoConfig::fromArray([
      'paths' => ['skills' => 'my-skills', 'eval-file' => 'eval.yml', 'exclude' => ['foo']],
      'aliases' => ['harness' => 'bin/harness'],
      'commands' => ['resolve' => ['binary' => 'bin/harness', 'list-args' => ['list', '--json']]],
      'guards' => ['broker bypass' => 'pack:gh-mutations'],
      'hooks' => [['script' => 'hooks/x.php', 'cases' => []], 'not-an-array'],
      'models' => ['aliases' => ['haiku' => 'claude-haiku'], 'ladder' => ['haiku'], 'default' => 'haiku', 'judge' => 'haiku'],
      'llm' => ['environment' => 'docker'],
      'report' => ['redact' => TRUE],
    ]);

    $this->assertSame(['my-skills'], $repo->skillsPaths);
    $this->assertSame('eval.yml', $repo->evalFile);
    $this->assertSame(['foo'], $repo->exclude);
    $this->assertSame(['harness' => 'bin/harness'], $repo->aliases);
    $this->assertSame(['broker bypass' => 'pack:gh-mutations'], $repo->guards);
    $this->assertCount(1, $repo->hooks);
    $this->assertSame(['script' => 'hooks/x.php', 'cases' => []], $repo->hooks[0]);
    $this->assertSame(['binary' => 'bin/harness', 'list-args' => ['list', '--json']], $repo->commandResolve);
    $this->assertSame(['haiku' => 'claude-haiku'], $repo->modelAliases);
    $this->assertSame(['haiku'], $repo->ladder);
    $this->assertSame('haiku', $repo->defaultModel);
    $this->assertSame('haiku', $repo->judgeModel);
    $this->assertSame('docker', $repo->environment);
    $this->assertSame(['redact' => TRUE], $repo->report);
  }

  public function testSkillsPathAsList(): void {
    $repo = RepoConfig::fromArray(['paths' => ['skills' => ['a', 'b']]]);

    $this->assertSame(['a', 'b'], $repo->skillsPaths);
  }

}
