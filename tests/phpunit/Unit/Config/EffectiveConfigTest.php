<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Config;

use AlexSkrypnyk\SkillTest\Config\EffectiveConfig;
use AlexSkrypnyk\SkillTest\Config\RepoConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class EffectiveConfigTest.
 *
 * Unit test for the merged per-skill configuration and its precedence.
 */
#[CoversClass(EffectiveConfig::class)]
final class EffectiveConfigTest extends TestCase {

  #[DataProvider('dataProviderModels')]
  public function testModelsPrecedence(array $repo, array $eval, array $cli, array $expected): void {
    $config = EffectiveConfig::resolve(RepoConfig::fromArray($repo), $eval, $cli, 'name', 'skills/name');

    $this->assertSame($expected, $config->models);
  }

  public static function dataProviderModels(): \Iterator {
    yield 'empty everywhere' => [[], [], [], []];
    yield 'repo ladder' => [['models' => ['ladder' => ['haiku', 'sonnet']]], [], [], ['haiku', 'sonnet']];
    yield 'repo default only' => [['models' => ['default' => 'sonnet']], [], [], ['sonnet']];
    yield 'eval list beats repo' => [['models' => ['ladder' => ['haiku']]], ['llm' => ['models' => ['opus']]], [], ['opus']];
    yield 'eval ladder keyword expands' => [['models' => ['ladder' => ['haiku', 'sonnet']]], ['llm' => ['models' => 'ladder']], [], ['haiku', 'sonnet']];
    yield 'cli beats eval' => [['models' => ['ladder' => ['sonnet']]], ['llm' => ['models' => ['haiku']]], ['models' => 'opus,gpt'], ['opus', 'gpt']];
    yield 'cli ladder keyword expands' => [['models' => ['ladder' => ['a', 'b']]], [], ['models' => 'ladder'], ['a', 'b']];
    yield 'cli trims spaces and empties' => [[], [], ['models' => 'opus, , sonnet'], ['opus', 'sonnet']];
  }

  public function testDefaults(): void {
    $config = EffectiveConfig::resolve(RepoConfig::fromArray([]), [], [], 'foo', 'skills/foo');

    $this->assertSame('foo', $config->skill);
    $this->assertSame('skills/foo', $config->path);
    $this->assertSame([], $config->models);
    $this->assertSame(0.8, $config->threshold);
    $this->assertSame(1, $config->trials);
    $this->assertNull($config->maxTurns);
    $this->assertSame('host', $config->environment);
    $this->assertNull($config->judgeModel);
    $this->assertSame([], $config->rubric);
    $this->assertSame([], $config->tasks);
    $this->assertSame([], $config->checks);
    $this->assertSame([], $config->modelAliases);
    $this->assertNull($config->transcript);
    $this->assertSame(['baseline'], $config->security['packs']);
    $this->assertSame([], $config->security['forbidden-tokens']);
    $this->assertSame(['allowed' => [], 'required' => [], 'forbidden' => []], $config->contract['tools']);
    $this->assertSame(['required' => [], 'forbidden' => []], $config->contract['commands']);
    $this->assertSame(['required' => [], 'forbidden' => []], $config->contract['skills']);
  }

  public function testFullEval(): void {
    $repo = RepoConfig::fromArray([
      'guards' => ['broker bypass' => 'pack:gh-mutations'],
      'models' => ['aliases' => ['haiku' => 'claude-haiku'], 'judge' => 'haiku'],
      'llm' => ['environment' => 'docker'],
    ]);
    $eval = [
      'skill' => 'custom-name',
      'contract' => [
        'tools' => ['allowed' => ['Bash', 'Skill'], 'required' => ['Skill'], 'forbidden' => []],
        'commands' => ['required' => ['drives' => '\bharness\b'], 'forbidden' => ['raw git' => 'pack:git-mutations']],
        'skills' => ['required' => ['harness:build'], 'forbidden' => []],
      ],
      'security' => ['packs' => ['custom'], 'forbidden-tokens' => ['SECRET']],
      'deterministic' => ['transcript' => 'fixtures/transcript.jsonl'],
      'llm' => [
        'tasks' => [['name' => 't', 'prompt' => '/x'], 'not-an-array'],
        'max-turns' => 8,
        'trials' => 3,
        'threshold' => 0.9,
        'judge' => ['rubric' => ['crit one']],
        'checks' => [['name' => 'c', 'run' => 'php x.php']],
      ],
    ];

    $config = EffectiveConfig::resolve($repo, $eval, [], 'dir-name', 'skills/dir-name');

    $this->assertSame('custom-name', $config->skill);
    $this->assertSame(0.9, $config->threshold);
    $this->assertSame(3, $config->trials);
    $this->assertSame(8, $config->maxTurns);
    $this->assertSame('docker', $config->environment);
    $this->assertSame('haiku', $config->judgeModel);
    $this->assertSame(['crit one'], $config->rubric);
    $this->assertSame('fixtures/transcript.jsonl', $config->transcript);
    $this->assertSame(['baseline', 'custom'], $config->security['packs']);
    $this->assertSame(['SECRET'], $config->security['forbidden-tokens']);
    $this->assertSame(['Bash', 'Skill'], $config->contract['tools']['allowed']);
    $this->assertSame(['drives' => '\bharness\b'], $config->contract['commands']['required']);
    $this->assertSame(['raw git' => 'pack:git-mutations', 'broker bypass' => 'pack:gh-mutations'], $config->contract['commands']['forbidden']);
    $this->assertCount(1, $config->tasks);
    $this->assertCount(1, $config->checks);
  }

  public function testCliOverridesScalars(): void {
    $config = EffectiveConfig::resolve(
      RepoConfig::fromArray(['llm' => ['environment' => 'host']]),
      ['llm' => ['threshold' => 0.7, 'trials' => 2]],
      ['threshold' => 0.5, 'trials' => 9, 'env' => 'docker'],
      'name',
      'skills/name',
    );

    $this->assertSame(0.5, $config->threshold);
    $this->assertSame(9, $config->trials);
    $this->assertSame('docker', $config->environment);
  }

  public function testEvalScalarsBeatDefaults(): void {
    $config = EffectiveConfig::resolve(
      RepoConfig::fromArray([]),
      ['llm' => ['threshold' => 0.7, 'trials' => 2]],
      [],
      'name',
      'skills/name',
    );

    $this->assertSame(0.7, $config->threshold);
    $this->assertSame(2, $config->trials);
  }

  public function testBaselineNotDuplicated(): void {
    $config = EffectiveConfig::resolve(RepoConfig::fromArray([]), ['security' => ['packs' => ['baseline']]], [], 'name', 'skills/name');

    $this->assertSame(['baseline'], $config->security['packs']);
  }

  public function testToArray(): void {
    $repo = RepoConfig::fromArray(['models' => ['aliases' => ['haiku' => 'claude-haiku'], 'ladder' => ['haiku']]]);
    $config = EffectiveConfig::resolve($repo, [], [], 'foo', 'skills/foo');

    $array = $config->toArray();

    $this->assertSame('foo', $array['skill']);
    $this->assertSame('skills/foo', $array['path']);
    $this->assertSame(['haiku'], $array['llm']['models']);
    $this->assertSame(0.8, $array['llm']['threshold']);
    $this->assertSame(['model' => NULL, 'rubric' => []], $array['llm']['judge']);
    $this->assertSame(['haiku' => 'claude-haiku'], $array['models']['aliases']);
    $this->assertSame(['transcript' => NULL], $array['deterministic']);
    $this->assertArrayHasKey('contract', $array);
    $this->assertArrayHasKey('security', $array);
  }

}
