<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Config;

use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedSkill;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class ConfigLoaderTest.
 *
 * Unit test for the configuration loader.
 */
#[CoversClass(ConfigLoader::class)]
#[CoversClass(LoadedConfig::class)]
#[CoversClass(LoadedSkill::class)]
final class ConfigLoaderTest extends TestCase {

  /**
   * The original SKILLTEST_CONFIG value, restored after each test.
   */
  protected string|false $originalConfigEnv = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->originalConfigEnv = getenv(ConfigLoader::ENV_CONFIG);
    putenv(ConfigLoader::ENV_CONFIG);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->originalConfigEnv === FALSE) {
      putenv(ConfigLoader::ENV_CONFIG);
    }
    else {
      putenv(ConfigLoader::ENV_CONFIG . '=' . $this->originalConfigEnv);
    }

    parent::tearDown();
  }

  public function testLoadFull(): void {
    $root = vfsStream::setup('root', NULL, [
      'skilltest.yml' => "version: \"1\"\nmodels:\n  ladder: [haiku]\n",
      'skills' => [
        'foo' => [
          'SKILL.md' => 'x',
          'eval.yaml' => "version: \"1\"\nskill: foo\n",
        ],
        'bare' => ['SKILL.md' => 'x'],
      ],
    ]);

    $loaded = (new ConfigLoader($root->url()))->load();

    $this->assertInstanceOf(LoadedConfig::class, $loaded);
    $this->assertSame($root->url() . '/skilltest.yml', $loaded->repoFile);
    $this->assertSame(['haiku'], $loaded->repo->ladder);
    $this->assertCount(1, $loaded->skills);

    $skill = $loaded->skills[0];
    $this->assertInstanceOf(LoadedSkill::class, $skill);
    $this->assertSame($root->url() . '/skills/foo/eval.yaml', $skill->file);
    $this->assertSame('foo', $skill->data['skill']);
    $this->assertSame('foo', $skill->effective->skill);
    $this->assertSame('skills/foo', $skill->effective->path);
  }

  public function testLoadWithoutRepoConfig(): void {
    $root = vfsStream::setup('root', NULL, [
      'skills' => ['foo' => ['SKILL.md' => 'x', 'eval.yaml' => "skill: foo\n"]],
    ]);

    $loaded = (new ConfigLoader($root->url()))->load();

    $this->assertSame('', $loaded->repoFile);
    $this->assertSame([], $loaded->repoData);
    $this->assertCount(1, $loaded->skills);
  }

  public function testEmptyRepoConfigFileIsDefaults(): void {
    $root = vfsStream::setup('root', NULL, ['skilltest.yml' => "\n"]);

    $loaded = (new ConfigLoader($root->url()))->load();

    $this->assertSame([], $loaded->repoData);
    $this->assertSame(['skills'], $loaded->repo->skillsPaths);
  }

  public function testCliOverridesReachEffectiveConfig(): void {
    $root = vfsStream::setup('root', NULL, [
      'skills' => ['foo' => ['SKILL.md' => 'x', 'eval.yaml' => "skill: foo\n"]],
    ]);

    $loaded = (new ConfigLoader($root->url()))->load(['models' => 'opus,sonnet']);

    $this->assertSame(['opus', 'sonnet'], $loaded->skills[0]->effective->models);
  }

  public function testConfigEnvOverride(): void {
    $root = vfsStream::setup('root', NULL, [
      'custom.yml' => "version: \"1\"\nmodels:\n  default: sonnet\n",
    ]);
    putenv(ConfigLoader::ENV_CONFIG . '=' . $root->url() . '/custom.yml');

    $loaded = (new ConfigLoader($root->url()))->load();

    $this->assertSame($root->url() . '/custom.yml', $loaded->repoFile);
    $this->assertSame('sonnet', $loaded->repo->defaultModel);
  }

  public function testMalformedRepoYaml(): void {
    $root = vfsStream::setup('root', NULL, ['skilltest.yml' => "foo: [unterminated\n"]);

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('malformed YAML');

    (new ConfigLoader($root->url()))->load();
  }

  public function testMalformedEvalYamlNamesFile(): void {
    $root = vfsStream::setup('root', NULL, [
      'skills' => ['foo' => ['SKILL.md' => 'x', 'eval.yaml' => "a: [bad\n"]],
    ]);

    try {
      (new ConfigLoader($root->url()))->load();
      $this->fail('Expected ConfigException.');
    }
    catch (ConfigException $config_exception) {
      $this->assertStringContainsString('skills/foo/eval.yaml', $config_exception->configFile());
    }
  }

  public function testUnknownRepoMajor(): void {
    $root = vfsStream::setup('root', NULL, ['skilltest.yml' => "version: \"2\"\n"]);

    try {
      (new ConfigLoader($root->url()))->load();
      $this->fail('Expected ConfigException.');
    }
    catch (ConfigException $config_exception) {
      $this->assertSame('version', $config_exception->pointer());
      $this->assertStringContainsString('skilltest migrate', $config_exception->getMessage());
    }
  }

  public function testInvalidVersion(): void {
    $root = vfsStream::setup('root', NULL, ['skilltest.yml' => "version: nope\n"]);

    try {
      (new ConfigLoader($root->url()))->load();
      $this->fail('Expected ConfigException.');
    }
    catch (ConfigException $config_exception) {
      $this->assertSame('version', $config_exception->pointer());
      $this->assertStringContainsString('Invalid schema version', $config_exception->getMessage());
    }
  }

  public function testNonMappingTopLevel(): void {
    $root = vfsStream::setup('root', NULL, ['skilltest.yml' => "just a scalar\n"]);

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('expected a mapping');

    (new ConfigLoader($root->url()))->load();
  }

  public function testTopLevelSequenceRejected(): void {
    $root = vfsStream::setup('root', NULL, ['skilltest.yml' => "- a\n- b\n"]);

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('expected a mapping');

    (new ConfigLoader($root->url()))->load();
  }

  public function testEmptyInlineMappingIsDefaults(): void {
    $root = vfsStream::setup('root', NULL, ['skilltest.yml' => "{}\n"]);

    $loaded = (new ConfigLoader($root->url()))->load();

    $this->assertSame([], $loaded->repoData);
    $this->assertSame(['skills'], $loaded->repo->skillsPaths);
  }

  public function testMissingConfigEnvOverrideFails(): void {
    $root = vfsStream::setup('root', NULL, []);
    putenv(ConfigLoader::ENV_CONFIG . '=' . $root->url() . '/absent.yml');

    try {
      (new ConfigLoader($root->url()))->load();
      $this->fail('Expected ConfigException.');
    }
    catch (ConfigException $config_exception) {
      $this->assertStringContainsString('absent.yml', $config_exception->configFile());
      $this->assertStringContainsString(ConfigLoader::ENV_CONFIG, $config_exception->getMessage());
    }
  }

  public function testUnquotedNumericVersionRejected(): void {
    $root = vfsStream::setup('root', NULL, ['skilltest.yml' => "version: 1\n"]);

    try {
      (new ConfigLoader($root->url()))->load();
      $this->fail('Expected ConfigException.');
    }
    catch (ConfigException $config_exception) {
      $this->assertSame('version', $config_exception->pointer());
      $this->assertStringContainsString('quoted string', $config_exception->getMessage());
    }
  }

}
