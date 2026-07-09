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

  #[DataProvider('dataProviderModelsPrecedence')]
  public function testModelsPrecedence(array $repo, array $eval, array $cli, array $expected): void {
    $config = EffectiveConfig::resolve(RepoConfig::fromArray($repo), $eval, $cli, 'name', 'skills/name');

    $this->assertSame($expected, $config->models);
  }

  public static function dataProviderModelsPrecedence(): \Iterator {
    yield 'empty everywhere' => [[], [], [], []];
    yield 'repo ladder' => [['models' => ['ladder' => ['haiku', 'sonnet']]], [], [], ['haiku', 'sonnet']];
    yield 'repo default only' => [['models' => ['default' => 'sonnet']], [], [], ['sonnet']];
    yield 'eval list beats repo' => [['models' => ['ladder' => ['haiku']]], ['llm' => ['models' => ['opus']]], [], ['opus']];
    yield 'eval ladder keyword expands' => [['models' => ['ladder' => ['haiku', 'sonnet']]], ['llm' => ['models' => 'ladder']], [], ['haiku', 'sonnet']];
    yield 'cli beats eval' => [['models' => ['ladder' => ['sonnet']]], ['llm' => ['models' => ['haiku']]], ['models' => 'opus,gpt'], ['opus', 'gpt']];
    yield 'cli ladder keyword expands' => [['models' => ['ladder' => ['a', 'b']]], [], ['models' => 'ladder'], ['a', 'b']];
    yield 'cli trims spaces and empties' => [[], [], ['models' => 'opus, , sonnet'], ['opus', 'sonnet']];
  }

  #[DataProvider('dataProviderJudgeModelPrecedence')]
  public function testJudgeModelPrecedence(array $repo, array $cli, ?string $expected): void {
    $config = EffectiveConfig::resolve(RepoConfig::fromArray($repo), [], $cli, 'name', 'skills/name');

    $this->assertSame($expected, $config->judgeModel);
  }

  public static function dataProviderJudgeModelPrecedence(): \Iterator {
    yield 'nothing configured' => [[], [], NULL];
    yield 'explicit models.judge' => [['models' => ['judge' => 'haiku']], [], 'haiku'];
    yield 'defaults to ladder weakest' => [['models' => ['ladder' => ['haiku', 'sonnet']]], [], 'haiku'];
    yield 'defaults to repo default' => [['models' => ['default' => 'sonnet']], [], 'sonnet'];
    yield 'judge beats the ladder' => [['models' => ['judge' => 'haiku', 'ladder' => ['sonnet', 'opus']]], [], 'haiku'];
    yield 'cli beats everything' => [['models' => ['judge' => 'haiku', 'ladder' => ['sonnet']]], ['judge-model' => 'opus'], 'opus'];
    yield 'cli over a bare ladder' => [['models' => ['ladder' => ['haiku']]], ['judge-model' => 'opus'], 'opus'];
  }

  #[DataProvider('dataProviderUnknownPolicy')]
  public function testUnknownPolicy(array $eval, string $expected): void {
    $config = EffectiveConfig::resolve(RepoConfig::fromArray([]), $eval, [], 'name', 'skills/name');

    $this->assertSame($expected, $config->judgeUnknown);
  }

  public static function dataProviderUnknownPolicy(): \Iterator {
    yield 'unset defaults to fail' => [[], 'fail'];
    yield 'explicit fail' => [['llm' => ['judge' => ['unknown' => 'fail']]], 'fail'];
    yield 'explicit ignore' => [['llm' => ['judge' => ['unknown' => 'ignore']]], 'ignore'];
    yield 'unrecognised falls back to fail' => [['llm' => ['judge' => ['unknown' => 'maybe']]], 'fail'];
  }

  public function testDefaults(): void {
    $config = EffectiveConfig::resolve(RepoConfig::fromArray([]), [], [], 'foo', 'skills/foo');

    $this->assertSame('foo', $config->skill);
    $this->assertSame('skills/foo', $config->path);
    $this->assertSame([], $config->models);
    $this->assertEqualsWithDelta(0.8, $config->threshold, PHP_FLOAT_EPSILON);
    $this->assertSame(1, $config->trials);
    $this->assertNull($config->maxTurns);
    $this->assertSame('host', $config->environment);
    $this->assertNull($config->judgeModel);
    $this->assertSame('fail', $config->judgeUnknown);
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
    $this->assertEqualsWithDelta(0.9, $config->threshold, PHP_FLOAT_EPSILON);
    $this->assertSame(3, $config->trials);
    $this->assertSame(8, $config->maxTurns);
    $this->assertSame('docker', $config->environment);
    $this->assertSame('haiku', $config->judgeModel);
    $this->assertSame(['crit one'], $config->rubric);
    $this->assertSame('fixtures/transcript.jsonl', $config->transcript);
    $this->assertSame(['baseline', 'custom'], $config->security['packs']);
    $this->assertSame(['SECRET'], $config->security['forbidden-tokens']);
    $this->assertSame([
      'tools' => ['allowed' => ['Bash', 'Skill'], 'required' => ['Skill'], 'forbidden' => []],
      'commands' => ['required' => ['drives' => '\bharness\b'], 'forbidden' => ['raw git' => 'pack:git-mutations', 'broker bypass' => 'pack:gh-mutations']],
      'skills' => ['required' => ['harness:build'], 'forbidden' => []],
    ], $config->contract);
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

    $this->assertEqualsWithDelta(0.5, $config->threshold, PHP_FLOAT_EPSILON);
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

    $this->assertEqualsWithDelta(0.7, $config->threshold, PHP_FLOAT_EPSILON);
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

    $llm = $array['llm'];
    $this->assertIsArray($llm);
    $this->assertSame(['haiku'], $llm['models']);
    $this->assertEqualsWithDelta(0.8, $llm['threshold'], PHP_FLOAT_EPSILON);
    $this->assertSame(['model' => 'haiku', 'rubric' => [], 'unknown' => 'fail'], $llm['judge']);

    $models = $array['models'];
    $this->assertIsArray($models);
    $this->assertSame(['haiku' => 'claude-haiku'], $models['aliases']);

    $this->assertSame(['transcript' => NULL], $array['deterministic']);
    $this->assertArrayHasKey('contract', $array);
    $this->assertArrayHasKey('security', $array);
    $this->assertArrayHasKey('structure', $array);
  }

  public function testStructureResolvesSuppressAndParams(): void {
    $eval = [
      'structure' => [
        'suppress' => ['structure.name-matches-dir' => 'legacy directory name'],
        'params' => ['structure.description-length' => ['min' => 24, 'max' => 800]],
      ],
    ];
    $config = EffectiveConfig::resolve(RepoConfig::fromArray([]), $eval, [], 'foo', 'skills/foo');

    $this->assertSame(['structure.name-matches-dir' => 'legacy directory name'], $config->structure['suppress']);
    $this->assertSame(['structure.description-length' => ['min' => 24, 'max' => 800]], $config->structure['params']);
  }

  public function testStructureDefaultsToEmptyBlocks(): void {
    $config = EffectiveConfig::resolve(RepoConfig::fromArray([]), [], [], 'foo', 'skills/foo');

    $this->assertSame(['suppress' => [], 'params' => []], $config->structure);
  }

}
