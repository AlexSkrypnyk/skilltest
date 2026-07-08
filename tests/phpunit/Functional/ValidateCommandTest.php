<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\PhpunitHelpers\Traits\ApplicationTrait;
use AlexSkrypnyk\SkillTest\Command\ValidateCommand;
use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class ValidateCommandTest.
 *
 * Functional test for the validate command.
 */
#[CoversClass(ValidateCommand::class)]
#[Group('command')]
final class ValidateCommandTest extends TestCase {

  use ApplicationTrait;

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

    $this->applicationTearDown();

    parent::tearDown();
  }

  public function testValidConfigPasses(): void {
    $root = vfsStream::setup('root', NULL, [
      'skilltest.yml' => "version: \"1\"\n",
      'skills' => ['foo' => ['SKILL.md' => 'x', 'eval.yaml' => "version: \"1\"\nskill: foo\n"]],
    ]);

    $output = $this->runValidate(['--dir' => $root->url()], 0);

    $this->assertStringContainsString('OK: validated 1 skill(s).', $output);
  }

  public function testDefaultsToCurrentDirectory(): void {
    $output = $this->runValidate([], 0);

    $this->assertStringContainsString('OK: validated 0 skill(s).', $output);
  }

  public function testMalformedYamlNamesFile(): void {
    $root = vfsStream::setup('root', NULL, ['skilltest.yml' => "foo: [bad\n"]);

    $output = $this->runValidate(['--dir' => $root->url()], 2);

    $this->assertStringContainsString('skilltest.yml', $output);
    $this->assertStringContainsString('malformed YAML', $output);
  }

  public function testUnknownSchemaMajorNamesFileAndKey(): void {
    $root = vfsStream::setup('root', NULL, [
      'skilltest.yml' => "version: \"1\"\n",
      'skills' => ['foo' => ['SKILL.md' => 'x', 'eval.yaml' => "version: \"2\"\n"]],
    ]);

    $output = $this->runValidate(['--dir' => $root->url()], 2);

    $this->assertStringContainsString('eval.yaml', $output);
    $this->assertStringContainsString('version', $output);
    $this->assertStringContainsString('skilltest migrate', $output);
  }

  public function testNonCompilingPatternFails(): void {
    $eval = "version: \"1\"\ncontract:\n  commands:\n    forbidden:\n      bad: '('\n";
    $output = $this->runValidate(['--dir' => $this->skill($eval)], 2);

    $this->assertStringContainsString('contract.commands.forbidden.bad', $output);
    $this->assertStringContainsString('does not compile', $output);
    $this->assertStringContainsString('FAILED: 1 error(s).', $output);
  }

  public function testUnresolvablePackFails(): void {
    $eval = "version: \"1\"\ncontract:\n  commands:\n    forbidden:\n      raw: pack:nope\n";
    $output = $this->runValidate(['--dir' => $this->skill($eval)], 2);

    $this->assertStringContainsString("unknown pattern pack 'nope'.", $output);
  }

  public function testMissingFixtureFails(): void {
    $eval = "version: \"1\"\ndeterministic:\n  transcript: fixtures/missing.jsonl\n";
    $output = $this->runValidate(['--dir' => $this->skill($eval)], 2);

    $this->assertStringContainsString('fixture not found: fixtures/missing.jsonl', $output);
  }

  public function testMissingHookScriptFails(): void {
    $root = vfsStream::setup('root', NULL, [
      'skilltest.yml' => "version: \"1\"\nhooks:\n  - script: hooks/missing.php\n",
    ]);

    $output = $this->runValidate(['--dir' => $root->url()], 2);

    $this->assertStringContainsString('hook script not found: hooks/missing.php', $output);
  }

  public function testUndefinedModelAliasFails(): void {
    $root = vfsStream::setup('root', NULL, [
      'skilltest.yml' => "version: \"1\"\nmodels:\n  ladder: [ghost]\n",
    ]);

    $output = $this->runValidate(['--dir' => $root->url()], 2);

    $this->assertStringContainsString("undefined model alias 'ghost'.", $output);
  }

  public function testSameEntryInRequiredAndForbiddenFails(): void {
    $eval = "version: \"1\"\ncontract:\n  tools:\n    required: [Bash]\n    forbidden: [Bash]\n";
    $output = $this->runValidate(['--dir' => $this->skill($eval)], 2);

    $this->assertStringContainsString("tool 'Bash' is in both required and forbidden.", $output);
  }

  public function testUnknownKeyWarnsButPasses(): void {
    $eval = "version: \"1\"\nskill: foo\nbogus: 1\n";
    $output = $this->runValidate(['--dir' => $this->skill($eval)], 0);

    $this->assertStringContainsString('WARNING', $output);
    $this->assertStringContainsString('bogus - unknown key (ignored).', $output);
    $this->assertStringContainsString('OK: validated 1 skill(s).', $output);
  }

  public function testShowConfigHuman(): void {
    $output = $this->runValidate(['--dir' => $this->skill("version: \"1\"\nskill: foo\n"), '--show-config' => TRUE], 0);

    $this->assertStringContainsString('# foo', $output);
    $this->assertStringContainsString('models', $output);
  }

  public function testShowConfigEvalBeatsRepo(): void {
    $root = $this->precedenceFixture();

    $output = $this->runValidate(['--dir' => $root, '--show-config' => TRUE, '--json' => TRUE], 0);

    $config = $this->decode($output);
    $this->assertSame(['haiku'], $config['config']['foo']['llm']['models']);
  }

  public function testShowConfigCliBeatsEval(): void {
    $root = $this->precedenceFixture();

    $output = $this->runValidate(['--dir' => $root, '--show-config' => TRUE, '--json' => TRUE, '--models' => 'opus'], 0);

    $config = $this->decode($output);
    $this->assertSame(['opus'], $config['config']['foo']['llm']['models']);
  }

  public function testJsonValid(): void {
    $root = vfsStream::setup('root', NULL, [
      'skills' => ['foo' => ['SKILL.md' => 'x', 'eval.yaml' => "version: \"1\"\nskill: foo\n"]],
    ]);

    $decoded = $this->decode($this->runValidate(['--dir' => $root->url(), '--json' => TRUE], 0));

    $this->assertTrue($decoded['ok']);
    $this->assertSame([], $decoded['errors']);
  }

  public function testJsonError(): void {
    $eval = "version: \"1\"\ncontract:\n  tools:\n    required: [Bash]\n    forbidden: [Bash]\n";
    $decoded = $this->decode($this->runValidate(['--dir' => $this->skill($eval), '--json' => TRUE], 2));

    $this->assertFalse($decoded['ok']);
    $this->assertStringContainsString('contract.tools', $decoded['errors'][0]['pointer']);
  }

  public function testJsonLoadError(): void {
    $root = vfsStream::setup('root', NULL, ['skilltest.yml' => "foo: [bad\n"]);

    $decoded = $this->decode($this->runValidate(['--dir' => $root->url(), '--json' => TRUE], 2));

    $this->assertFalse($decoded['ok']);
    $this->assertStringContainsString('skilltest.yml', $decoded['errors'][0]['file']);
  }

  /**
   * Runs the validate command and asserts the exit code.
   *
   * @param array<string, string|bool> $input
   *   The command input.
   * @param int $expected_exit
   *   The expected exit code.
   *
   * @return string
   *   The command standard output.
   */
  protected function runValidate(array $input, int $expected_exit): string {
    $this->applicationInitFromCommand(ValidateCommand::class);
    $this->applicationRun($input, [], $expected_exit !== 0);

    $this->assertSame($expected_exit, $this->applicationGetTester()->getStatusCode());

    return $this->applicationGetTester()->getDisplay();
  }

  /**
   * Sets up a root with a single skill carrying the given eval.
   *
   * @param string $eval
   *   The `eval.yaml` contents.
   *
   * @return string
   *   The root URL.
   */
  protected function skill(string $eval): string {
    return vfsStream::setup('root', NULL, [
      'skills' => ['foo' => ['SKILL.md' => 'x', 'eval.yaml' => $eval]],
    ])->url();
  }

  /**
   * Sets up a fixture where repo, eval, and CLI each set the model list.
   *
   * @return string
   *   The root URL.
   */
  protected function precedenceFixture(): string {
    return vfsStream::setup('root', NULL, [
      'skilltest.yml' => "version: \"1\"\nmodels:\n  aliases:\n    sonnet: claude-sonnet\n    haiku: claude-haiku\n    opus: claude-opus\n  ladder: [sonnet]\n",
      'skills' => ['foo' => ['SKILL.md' => 'x', 'eval.yaml' => "version: \"1\"\nskill: foo\nllm:\n  models: [haiku]\n"]],
    ])->url();
  }

  /**
   * Decodes a JSON command output.
   *
   * @param string $output
   *   The JSON output.
   *
   * @return array<string, mixed>
   *   The decoded payload.
   */
  protected function decode(string $output): array {
    $decoded = json_decode(trim($output), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertIsArray($decoded);

    return $decoded;
  }

}
