<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Config;

use AlexSkrypnyk\SkillTest\Config\DockerConfig;
use AlexSkrypnyk\SkillTest\Config\ExcludeEntry;
use AlexSkrypnyk\SkillTest\Config\RepoConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class RepoConfigTest.
 *
 * Unit test for the repo-level configuration value object.
 */
#[CoversClass(RepoConfig::class)]
#[CoversClass(ExcludeEntry::class)]
final class RepoConfigTest extends TestCase {

  public function testDefaults(): void {
    $repo = RepoConfig::fromArray([]);

    $this->assertSame(['skills'], $repo->skillsPaths);
    $this->assertSame('eval.yaml', $repo->evalFile);
    $this->assertSame([], $repo->excludes);
    $this->assertSame([], $repo->aliases);
    $this->assertSame([], $repo->guards);
    $this->assertSame([], $repo->hooks);
    $this->assertSame([], $repo->commandResolve);
    $this->assertSame([], $repo->modelAliases);
    $this->assertSame([], $repo->ladder);
    $this->assertNull($repo->defaultModel);
    $this->assertNull($repo->judgeModel);
    $this->assertSame('host', $repo->environment);
    $this->assertSame(DockerConfig::DEFAULT_IMAGE, $repo->docker->image);
    $this->assertSame('', $repo->docker->setup);
    $this->assertNull($repo->docker->cpus);
    $this->assertNull($repo->docker->memoryMb);
    $this->assertSame([], $repo->lifecycle);
    $this->assertSame([], $repo->report);
  }

  public function testFull(): void {
    $repo = RepoConfig::fromArray([
      'paths' => ['skills' => 'my-skills', 'eval-file' => 'eval.yml', 'exclude' => [['skill' => 'foo', 'reason' => 'legacy']]],
      'aliases' => ['harness' => 'bin/harness'],
      'commands' => ['resolve' => ['binary' => 'bin/harness', 'list-args' => ['list', '--json']]],
      'guards' => ['broker bypass' => 'pack:gh-mutations'],
      'hooks' => [['script' => 'hooks/x.php', 'cases' => []], 'not-an-array'],
      'models' => ['aliases' => ['haiku' => 'claude-haiku'], 'ladder' => ['haiku'], 'default' => 'haiku', 'judge' => 'haiku'],
      'llm' => ['environment' => 'docker', 'docker' => ['image' => 'my/image:2', 'cpus' => 2, 'memory-mb' => 1024], 'lifecycle' => ['before-run' => [['command' => 'php reset.php']]]],
      'report' => ['redact' => TRUE],
    ]);

    $this->assertSame(['my-skills'], $repo->skillsPaths);
    $this->assertSame('eval.yml', $repo->evalFile);
    $this->assertCount(1, $repo->excludes);
    $this->assertSame('foo', $repo->excludes[0]->skill);
    $this->assertSame('legacy', $repo->excludes[0]->reason);
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
    $this->assertSame('my/image:2', $repo->docker->image);
    $this->assertEqualsWithDelta(2.0, $repo->docker->cpus, PHP_FLOAT_EPSILON);
    $this->assertSame(1024, $repo->docker->memoryMb);
    $this->assertSame(['before-run' => [['command' => 'php reset.php']]], $repo->lifecycle);
    $this->assertSame(['redact' => TRUE], $repo->report);
  }

  public function testSkillsPathAsList(): void {
    $repo = RepoConfig::fromArray(['paths' => ['skills' => ['a', 'b']]]);

    $this->assertSame(['a', 'b'], $repo->skillsPaths);
  }

}
