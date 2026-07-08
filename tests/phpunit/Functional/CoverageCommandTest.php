<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\PhpunitHelpers\Traits\ApplicationTrait;
use AlexSkrypnyk\SkillTest\Command\CoverageCommand;
use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class CoverageCommandTest.
 *
 * Functional test for the coverage command.
 */
#[CoversClass(CoverageCommand::class)]
#[Group('command')]
final class CoverageCommandTest extends TestCase {

  use ApplicationTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    putenv(ConfigLoader::ENV_CONFIG);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv(ConfigLoader::ENV_CONFIG);

    $this->applicationTearDown();

    parent::tearDown();
  }

  public function testUncoveredSkillFailsGateNamingSkill(): void {
    $root = vfsStream::setup('root', NULL, [
      'skills' => ['lonely' => ['SKILL.md' => 'x']],
    ]);

    $output = $this->runCoverage(['--dir' => $root->url()], 1);

    $this->assertStringContainsString("coverage: skill 'lonely' has no eval.yaml and is not excluded (skills/lonely).", $output);
    $this->assertStringContainsString('uncovered', $output);
    $this->assertStringContainsString('1 skill(s): 0 covered, 0 excluded, 1 uncovered.', $output);
  }

  public function testExcludeWithReasonSuppressesAndShowsExcluded(): void {
    $root = vfsStream::setup('root', NULL, [
      'skilltest.yml' => "version: \"1\"\npaths:\n  exclude:\n    - skill: lonely\n      reason: not yet testable\n",
      'skills' => ['lonely' => ['SKILL.md' => 'x']],
    ]);

    $output = $this->runCoverage(['--dir' => $root->url()], 0);

    $this->assertStringContainsString('excluded', $output);
    $this->assertStringContainsString('not yet testable', $output);
    $this->assertStringContainsString('1 skill(s): 0 covered, 1 excluded, 0 uncovered.', $output);
  }

  public function testExcludeWithoutReasonIsConfigError(): void {
    $root = vfsStream::setup('root', NULL, [
      'skilltest.yml' => "version: \"1\"\npaths:\n  exclude:\n    - skill: lonely\n",
      'skills' => ['lonely' => ['SKILL.md' => 'x']],
    ]);

    $output = $this->runCoverage(['--dir' => $root->url()], 2);

    $this->assertStringContainsString("excluded skill 'lonely' is missing a reason.", $output);
  }

  public function testJsonEmitsPerSkillFields(): void {
    $root = vfsStream::setup('root', NULL, [
      'skills' => [
        'covered' => [
          'SKILL.md' => 'x',
          'eval.yaml' => "version: \"1\"\nskill: covered\ndeterministic:\n  transcript: fixtures/t.jsonl\nllm:\n  tasks:\n    - name: a\n    - name: b\n",
          'fixtures' => ['t.jsonl' => '{}'],
        ],
        'lonely' => ['SKILL.md' => 'x'],
      ],
    ]);

    $decoded = $this->decode($this->runCoverage(['--dir' => $root->url(), '--format' => 'json'], 1));

    $this->assertFalse($decoded['ok']);
    $this->assertSame(['total' => 2, 'covered' => 1, 'excluded' => 0, 'uncovered' => 1], $decoded['summary']);

    $by_skill = [];
    foreach ($decoded['skills'] as $skill) {
      $by_skill[$skill['skill']] = $skill;
    }

    $this->assertSame(['skill' => 'covered', 'path' => 'skills/covered', 'eval' => TRUE, 'transcript' => TRUE, 'tasks' => 2, 'excluded' => FALSE, 'reason' => NULL], $by_skill['covered']);
    $this->assertSame(['skill' => 'lonely', 'path' => 'skills/lonely', 'eval' => FALSE, 'transcript' => FALSE, 'tasks' => 0, 'excluded' => FALSE, 'reason' => NULL], $by_skill['lonely']);
  }

  public function testMultipleRootsAreDiscoveredAndMerged(): void {
    $root = vfsStream::setup('root', NULL, [
      'skilltest.yml' => "version: \"1\"\npaths:\n  skills: [a, b]\n",
      'a' => ['one' => ['SKILL.md' => 'x', 'eval.yaml' => "version: \"1\"\nskill: one\n"]],
      'b' => ['two' => ['SKILL.md' => 'x', 'eval.yaml' => "version: \"1\"\nskill: two\n"]],
    ]);

    $output = $this->runCoverage(['--dir' => $root->url()], 0);

    $this->assertStringContainsString('one', $output);
    $this->assertStringContainsString('two', $output);
    $this->assertStringContainsString('2 skill(s): 2 covered, 0 excluded, 0 uncovered.', $output);
  }

  public function testMarkdownFormatRendersPipeTable(): void {
    $root = vfsStream::setup('root', NULL, [
      'skills' => ['covered' => ['SKILL.md' => 'x', 'eval.yaml' => "version: \"1\"\nskill: covered\n"]],
    ]);

    $output = $this->runCoverage(['--dir' => $root->url(), '--format' => 'markdown'], 0);

    $this->assertStringContainsString('| Skill | Eval | Transcript | Tasks | Status | Reason |', $output);
    $this->assertStringContainsString('| --- | --- | --- | --- | --- | --- |', $output);
    $this->assertStringContainsString('| covered | yes | no | 0 | covered |', $output);
  }

  public function testMarkdownEscapesPipesAndNewlinesInFreeText(): void {
    $root = vfsStream::setup('root', NULL, [
      'skilltest.yml' => "version: \"1\"\npaths:\n  exclude:\n    - skill: lonely\n      reason: \"needs | work\\nlater\"\n",
      'skills' => ['lonely' => ['SKILL.md' => 'x']],
    ]);

    $output = $this->runCoverage(['--dir' => $root->url(), '--format' => 'markdown'], 0);

    $this->assertStringContainsString('needs \\| work later', $output);
  }

  public function testUnknownFormatIsError(): void {
    $root = vfsStream::setup('root', NULL, ['skills' => []]);

    $output = $this->runCoverage(['--dir' => $root->url(), '--format' => 'xml'], 2);

    $this->assertStringContainsString('unknown format', $output);
  }

  public function testLoadErrorIsConfigError(): void {
    $root = vfsStream::setup('root', NULL, ['skilltest.yml' => "foo: [bad\n"]);

    $output = $this->runCoverage(['--dir' => $root->url()], 2);

    $this->assertStringContainsString('malformed YAML', $output);
  }

  public function testJsonConfigErrorEmitsJson(): void {
    $root = vfsStream::setup('root', NULL, [
      'skilltest.yml' => "version: \"1\"\npaths:\n  exclude:\n    - skill: lonely\n",
      'skills' => ['lonely' => ['SKILL.md' => 'x']],
    ]);

    $decoded = $this->decode($this->runCoverage(['--dir' => $root->url(), '--format' => 'json'], 2));

    $this->assertFalse($decoded['ok']);
    $this->assertSame([], $decoded['skills']);
    $this->assertStringContainsString('missing a reason', $decoded['errors'][0]['message']);
  }

  public function testDefaultsToCurrentDirectory(): void {
    $output = $this->runCoverage([], 0);

    $this->assertStringContainsString('0 skill(s): 0 covered, 0 excluded, 0 uncovered.', $output);
  }

  /**
   * Runs the coverage command and asserts the exit code.
   *
   * @param array<string, string|bool> $input
   *   The command input.
   * @param int $expected_exit
   *   The expected exit code.
   *
   * @return string
   *   The command standard output.
   */
  protected function runCoverage(array $input, int $expected_exit): string {
    $this->applicationInitFromCommand(CoverageCommand::class);
    $this->applicationRun($input, [], $expected_exit !== 0);

    $this->assertSame($expected_exit, $this->applicationGetTester()->getStatusCode());

    return $this->applicationGetTester()->getDisplay();
  }

  /**
   * Decodes a JSON command output.
   *
   * @param string $output
   *   The JSON output.
   *
   * @return array<mixed>
   *   The decoded payload.
   */
  protected function decode(string $output): array {
    $decoded = json_decode(trim($output), TRUE, 512, JSON_THROW_ON_ERROR);

    if (!is_array($decoded)) {
      $this->fail('Expected JSON output to decode to an array.');
    }

    return $decoded;
  }

}
