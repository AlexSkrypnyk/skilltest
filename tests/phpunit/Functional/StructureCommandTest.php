<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\PhpunitHelpers\Traits\ApplicationTrait;
use AlexSkrypnyk\SkillTest\Command\StructureCommand;
use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class StructureCommandTest.
 *
 * Functional test for the structure command.
 */
#[CoversClass(StructureCommand::class)]
#[Group('command')]
final class StructureCommandTest extends TestCase {

  use ApplicationTrait;

  /**
   * A well-formed SKILL.md for the skill directory `foo`.
   */
  protected const string CLEAN_FOO = "---\nname: foo\ndescription: A clean well-formed skill for tests.\n---\n# Foo\n\nBody.\n";

  /**
   * An executable stub binary that prints a two-command list.
   */
  protected const string STUB_OK = "#!/bin/sh\necho '[\"build\",\"test\"]'\n";

  /**
   * An executable stub binary that fails to run.
   */
  protected const string STUB_FAIL = "#!/bin/sh\nexit 3\n";

  /**
   * A real temporary directory to clean up, when a test creates one.
   */
  protected string $tempDir = '';

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

    if ($this->tempDir !== '' && is_dir($this->tempDir)) {
      $this->remove($this->tempDir);
    }

    $this->applicationTearDown();

    parent::tearDown();
  }

  public function testMalformedSkillTripsEveryFailingCheck(): void {
    $skill_md = "---\nname: wrong-name\ndescription: short\nallowed-tools: Bash(*)\n---\n# Body\nSee `references/missing.md`.\n";
    $root = vfsStream::setup('root', NULL, ['skills' => ['evil' => ['SKILL.md' => $skill_md, 'eval.yaml' => "version: \"1\"\n"]]]);

    $output = $this->runStructure(['--dir' => $root->url()], 1);

    $this->assertStringContainsString('structure.name-matches-dir FAIL skills/evil/SKILL.md:1', $output);
    $this->assertStringContainsString('structure.description-length FAIL skills/evil/SKILL.md:1', $output);
    $this->assertStringContainsString('structure.no-unrestricted-bash FAIL skills/evil/SKILL.md:4', $output);
    $this->assertStringContainsString('structure.files-exist FAIL skills/evil/SKILL.md:7', $output);
    $this->assertStringContainsString('4 failed', $output);
  }

  public function testCleanSkillPassesTheWholeGroup(): void {
    $root = vfsStream::setup('root', NULL, ['skills' => ['foo' => ['SKILL.md' => self::CLEAN_FOO, 'eval.yaml' => "version: \"1\"\n"]]]);

    $output = $this->runStructure(['--dir' => $root->url()], 0);

    $this->assertStringContainsString('10 check(s) across 1 skill(s): 10 passed, 0 failed, 0 warned, 0 suppressed.', $output);
  }

  public function testRepoWithNoEvalYamlAnywhereIsStillChecked(): void {
    $skill_md = "---\nname: wrong-name\ndescription: A clean well-formed skill for tests.\n---\n# Body\n";
    $root = vfsStream::setup('root', NULL, [
      'skills' => [
        'alpha' => ['SKILL.md' => $skill_md],
        'beta' => ['SKILL.md' => "---\nname: beta\ndescription: A clean well-formed skill for tests.\n---\n# Body\n"],
      ],
    ]);

    $output = $this->runStructure(['--dir' => $root->url()], 1);

    $this->assertStringContainsString('structure.name-matches-dir FAIL skills/alpha/SKILL.md:1', $output);
    $this->assertStringContainsString('18 check(s) across 2 skill(s): 17 passed, 1 failed, 0 warned, 0 suppressed.', $output);
  }

  public function testCoherenceCheckIsOmittedOnlyForTheSkillWithoutAnEval(): void {
    $root = vfsStream::setup('root', NULL, [
      'skills' => [
        'bare' => ['SKILL.md' => "---\nname: bare\ndescription: A clean well-formed skill for tests.\n---\n# Body\n"],
        'foo' => ['SKILL.md' => self::CLEAN_FOO, 'eval.yaml' => "version: \"1\"\n"],
      ],
    ]);

    $decoded = $this->decode($this->runStructure(['--dir' => $root->url(), '--format' => 'json'], 0));

    $this->assertTrue($decoded['ok']);
    $this->assertSame(['checks' => 19, 'skills' => 2, 'passed' => 19, 'failed' => 0, 'warned' => 0, 'suppressed' => 0], $decoded['summary']);
    $this->assertSame(['foo'], $this->skillsWithCheck($decoded, 'structure.contract-coherent'));
    $this->assertSame(['bare', 'foo'], $this->skillsWithCheck($decoded, 'structure.frontmatter'));
  }

  public function testWarningsAreListedButDoNotFailTheGate(): void {
    $eval = "version: \"1\"\nstructure:\n  params:\n    structure.token-budget:\n      warn-at: 5\n";
    $root = vfsStream::setup('root', NULL, ['skills' => ['foo' => ['SKILL.md' => self::CLEAN_FOO, 'eval.yaml' => $eval]]]);

    $output = $this->runStructure(['--dir' => $root->url()], 0);

    $this->assertStringContainsString('structure.token-budget WARN', $output);
    $this->assertStringContainsString('at or above the warn threshold of 5', $output);
    $this->assertStringContainsString('9 passed, 0 failed, 1 warned, 0 suppressed.', $output);
  }

  public function testSuppressedCheckIsRenderedWithReason(): void {
    $skill_md = "---\nname: legacy-name\ndescription: A clean well-formed skill for tests.\n---\n# Body\n";
    $eval = "version: \"1\"\nstructure:\n  suppress:\n    structure.name-matches-dir: ships under a shared directory name\n";
    $root = vfsStream::setup('root', NULL, ['skills' => ['good' => ['SKILL.md' => $skill_md, 'eval.yaml' => $eval]]]);

    $output = $this->runStructure(['--dir' => $root->url()], 0);

    $this->assertStringContainsString('structure.name-matches-dir SUPPRESSED', $output);
    $this->assertStringContainsString('ships under a shared directory name', $output);
    $this->assertStringContainsString('9 passed, 0 failed, 0 warned, 1 suppressed.', $output);
  }

  public function testCoherenceSurfacesAsCheckFailureNotConfigError(): void {
    $eval = "version: \"1\"\ncontract:\n  tools:\n    required: [Bash]\n    forbidden: [Bash]\n";
    $root = vfsStream::setup('root', NULL, ['skills' => ['foo' => ['SKILL.md' => self::CLEAN_FOO, 'eval.yaml' => $eval]]]);

    $output = $this->runStructure(['--dir' => $root->url()], 1);

    $this->assertStringContainsString('structure.contract-coherent FAIL', $output);
    $this->assertStringContainsString('both required and forbidden', $output);
  }

  public function testJsonEmitsResultsAndSummary(): void {
    $skill_md = "---\nname: wrong-name\ndescription: A clean well-formed skill for tests.\n---\n# Body\n";
    $root = vfsStream::setup('root', NULL, ['skills' => ['foo' => ['SKILL.md' => $skill_md, 'eval.yaml' => "version: \"1\"\n"]]]);

    $decoded = $this->decode($this->runStructure(['--dir' => $root->url(), '--format' => 'json'], 1));

    $this->assertFalse($decoded['ok']);
    $this->assertSame(['checks' => 10, 'skills' => 1, 'passed' => 9, 'failed' => 1, 'warned' => 0, 'suppressed' => 0], $decoded['summary']);
    $this->assertSame('fail', $this->findResult($decoded, 'structure.name-matches-dir')['status']);
    $this->assertSame('pass', $this->findResult($decoded, 'structure.frontmatter')['status']);
  }

  public function testMarkdownRendersTableOfNotableResults(): void {
    $skill_md = "---\nname: wrong-name\ndescription: short\n---\n# Body\n";
    $root = vfsStream::setup('root', NULL, ['skills' => ['foo' => ['SKILL.md' => $skill_md, 'eval.yaml' => "version: \"1\"\n"]]]);

    $output = $this->runStructure(['--dir' => $root->url(), '--format' => 'markdown'], 1);

    $this->assertStringContainsString('| Check | Skill | Status | Detail | Evidence |', $output);
    $this->assertStringContainsString('| --- | --- | --- | --- | --- |', $output);
    $this->assertStringContainsString('structure.name-matches-dir', $output);
    $this->assertStringContainsString('structure.description-length', $output);
  }

  public function testUnknownFormatIsConfigError(): void {
    $root = vfsStream::setup('root', NULL, ['skills' => []]);

    $output = $this->runStructure(['--dir' => $root->url(), '--format' => 'xml'], 2);

    $this->assertStringContainsString('unknown format', $output);
  }

  public function testLoadErrorIsConfigError(): void {
    $root = vfsStream::setup('root', NULL, ['skilltest.yml' => "foo: [bad\n"]);

    $output = $this->runStructure(['--dir' => $root->url()], 2);

    $this->assertStringContainsString('malformed YAML', $output);
  }

  public function testConfigErrorEmitsJson(): void {
    $root = vfsStream::setup('root', NULL, ['skilltest.yml' => "foo: [bad\n"]);

    $decoded = $this->decode($this->runStructure(['--dir' => $root->url(), '--format' => 'json'], 2));

    $this->assertFalse($decoded['ok']);
    $this->assertSame([], $decoded['results']);
  }

  public function testDefaultsToCurrentDirectory(): void {
    $output = $this->runStructure([], 0);

    $this->assertStringContainsString('across 0 skill(s)', $output);
  }

  public function testCommandRefsResolveCatchesUnknownSubcommandAgainstRealBinary(): void {
    $skill_md = "---\nname: foo\ndescription: A clean well-formed skill for tests.\n---\n# Foo\nRun `broker deploy` now.\n";
    $root = $this->realRepo(self::STUB_OK, $skill_md);

    $output = $this->runStructure(['--dir' => $root], 1);

    $this->assertStringContainsString('structure.command-refs-resolve FAIL', $output);
    $this->assertStringContainsString("'deploy' is not a command", $output);
  }

  public function testCommandRefsResolvePassesForRealSubcommand(): void {
    $skill_md = "---\nname: foo\ndescription: A clean well-formed skill for tests.\n---\n# Foo\nRun `broker build` now.\n";
    $root = $this->realRepo(self::STUB_OK, $skill_md);

    $output = $this->runStructure(['--dir' => $root], 0);

    $this->assertStringContainsString('11 check(s) across 1 skill(s): 11 passed, 0 failed, 0 warned, 0 suppressed.', $output);
  }

  public function testCommandRefsResolveBinaryFailureIsConfigError(): void {
    $root = $this->realRepo(self::STUB_FAIL, self::CLEAN_FOO);

    $output = $this->runStructure(['--dir' => $root], 2);

    $this->assertStringContainsString('failed (exit 3)', $output);
  }

  /**
   * Builds a real repository with an executable stub binary and one skill.
   *
   * @param string $stub
   *   The stub binary content.
   * @param string $skill_md
   *   The skill's SKILL.md content.
   *
   * @return string
   *   The repository root.
   */
  protected function realRepo(string $stub, string $skill_md): string {
    $this->tempDir = dirname(__DIR__, 3) . '/.artifacts/tmp/structurecmd-' . getmypid() . '-' . uniqid();
    mkdir($this->tempDir . '/bin', 0777, TRUE);
    mkdir($this->tempDir . '/skills/foo', 0777, TRUE);
    file_put_contents($this->tempDir . '/skilltest.yml', "version: \"1\"\ncommands:\n  resolve:\n    binary: bin/broker\n    list-args: [list, --json]\n");
    file_put_contents($this->tempDir . '/bin/broker', $stub);
    chmod($this->tempDir . '/bin/broker', 0755);
    file_put_contents($this->tempDir . '/skills/foo/SKILL.md', $skill_md);
    file_put_contents($this->tempDir . '/skills/foo/eval.yaml', "version: \"1\"\n");

    return $this->tempDir;
  }

  /**
   * Runs the structure command and asserts the exit code.
   *
   * @param array<string, string|bool> $input
   *   The command input.
   * @param int $expected_exit
   *   The expected exit code.
   *
   * @return string
   *   The command standard output.
   */
  protected function runStructure(array $input, int $expected_exit): string {
    $this->applicationInitFromCommand(StructureCommand::class);
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

  /**
   * Extracts a single result by check id from a decoded structure payload.
   *
   * @param array<mixed> $decoded
   *   The decoded payload.
   * @param string $check
   *   The check id to find.
   *
   * @return array<mixed>
   *   The result row.
   */
  protected function findResult(array $decoded, string $check): array {
    $results = $decoded['results'] ?? NULL;

    foreach (is_array($results) ? $results : [] as $result) {
      if (is_array($result) && ($result['check'] ?? NULL) === $check) {
        return $result;
      }
    }

    $this->fail('No result for ' . $check);
  }

  /**
   * Lists the skills a check produced a result for, in report order.
   *
   * @param array<mixed> $decoded
   *   The decoded payload.
   * @param string $check
   *   The check id to look for.
   *
   * @return string[]
   *   The skill names.
   */
  protected function skillsWithCheck(array $decoded, string $check): array {
    $results = $decoded['results'] ?? NULL;
    $skills = [];

    foreach (is_array($results) ? $results : [] as $result) {
      if (!is_array($result)) {
        continue;
      }
      if (($result['check'] ?? NULL) !== $check) {
        continue;
      }

      $skill = $result['skill'] ?? NULL;

      if (!is_string($skill)) {
        $this->fail('Expected a skill name on the ' . $check . ' result.');
      }

      $skills[] = $skill;
    }

    return $skills;
  }

  /**
   * Recursively removes a directory tree.
   *
   * @param string $dir
   *   The directory to remove.
   */
  protected function remove(string $dir): void {
    if (!is_dir($dir)) {
      return;
    }

    foreach (scandir($dir) ?: [] as $item) {
      if ($item === '.') {
        continue;
      }
      if ($item === '..') {
        continue;
      }
      $path = $dir . '/' . $item;

      if (is_dir($path)) {
        $this->remove($path);

        continue;
      }

      unlink($path);
    }

    rmdir($dir);
  }

}
