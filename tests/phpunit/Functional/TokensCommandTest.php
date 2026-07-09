<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\PhpunitHelpers\Traits\ApplicationTrait;
use AlexSkrypnyk\SkillTest\Command\TokensCommand;
use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class TokensCommandTest.
 *
 * Functional test for the tokens command: counting against a virtual tree,
 * comparing against a real git repository, and the gate semantics of
 * `--threshold` and `--strict`.
 */
#[CoversClass(TokensCommand::class)]
#[Group('command')]
final class TokensCommandTest extends TestCase {

  use ApplicationTrait;

  /**
   * A well-formed SKILL.md for the skill directory `foo`.
   */
  protected const string CLEAN_FOO = "---\nname: foo\ndescription: A clean well-formed skill for tests.\n---\n# Foo\n\nBody.\n";

  /**
   * The tiny vocabulary used to exercise the BPE path end to end.
   *
   * Tokens: `hello` and ` world`.
   */
  protected const string VOCAB = "aGVsbG8= 0\nIHdvcmxk 1\n";

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

  public function testCountTableListsMarkdownFilesUnderDirectory(): void {
    $root = $this->vfsRoot();
    $skill_tokens = (int) ceil(strlen(self::CLEAN_FOO) / 4);

    $output = $this->runTokens(['action' => 'count', 'targets' => ['skills'], '--dir' => $root], 0);

    $this->assertStringContainsString('TOKENS  PATH', $output);
    $this->assertStringContainsString(sprintf('%6d  skills/foo/SKILL.md', $skill_tokens), $output);
    $this->assertStringContainsString('skills/foo/references/guide.md', $output);
    $this->assertStringNotContainsString('eval.yaml', $output);
    $this->assertStringNotContainsString('script.sh', $output);
    $this->assertStringContainsString('2 file(s),', $output);
    $this->assertStringContainsString('(method: estimate).', $output);
  }

  public function testCountSortsByTokensDescending(): void {
    $root = $this->vfsRoot();

    $output = $this->runTokens(['action' => 'count', 'targets' => ['skills'], '--dir' => $root, '--sort' => 'tokens'], 0);

    $skill_position = strpos($output, 'skills/foo/SKILL.md');
    $guide_position = strpos($output, 'skills/foo/references/guide.md');

    $this->assertNotFalse($skill_position);
    $this->assertNotFalse($guide_position);
    $this->assertLessThan($guide_position, $skill_position, 'the larger file is listed first');
  }

  public function testCountAcceptsExplicitFileOfAnyType(): void {
    $root = $this->vfsRoot();

    $output = $this->runTokens(['action' => 'count', 'targets' => ['skills/foo/eval.yaml'], '--dir' => $root], 0);

    $this->assertStringContainsString('skills/foo/eval.yaml', $output);
    $this->assertStringContainsString('1 file(s),', $output);
  }

  public function testCountJsonEmitsFilesAndTotals(): void {
    $root = $this->vfsRoot();
    $skill_tokens = (int) ceil(strlen(self::CLEAN_FOO) / 4);

    $decoded = $this->decode($this->runTokens(['action' => 'count', 'targets' => ['skills'], '--dir' => $root, '--format' => 'json'], 0));

    $this->assertTrue($decoded['ok']);
    $this->assertSame('estimate', $decoded['method']);
    $this->assertIsArray($decoded['files']);
    $this->assertCount(2, $decoded['files']);
    $this->assertSame(['path' => 'skills/foo/SKILL.md', 'tokens' => $skill_tokens], $decoded['files'][0]);
    $this->assertIsArray($decoded['total']);
    $this->assertSame(2, $decoded['total']['files']);
  }

  public function testCountEmptyDirectoryReportsZeroFiles(): void {
    $root = vfsStream::setup('root', NULL, ['empty' => []])->url();

    $output = $this->runTokens(['action' => 'count', 'targets' => ['empty'], '--dir' => $root], 0);

    $this->assertStringContainsString('0 file(s), 0 token(s) total', $output);
  }

  public function testCountWithVocabUsesBpe(): void {
    $root = $this->vfsRoot();

    $output = $this->runTokens(['action' => 'count', 'targets' => ['note.md'], '--dir' => $root, '--vocab' => 'vocab.tiktoken'], 0);

    $this->assertStringContainsString('     2  note.md', $output);
    $this->assertStringContainsString('(method: bpe).', $output);
  }

  public function testCountVocabUnreadableIsError(): void {
    $root = $this->vfsRoot();

    $output = $this->runTokens(['action' => 'count', 'targets' => ['note.md'], '--dir' => $root, '--vocab' => 'absent.tiktoken'], 2);

    $this->assertStringContainsString('cannot be read', $output);
  }

  public function testCountAcceptsPathThatExistsAsGiven(): void {
    $root = $this->vfsRoot();

    $output = $this->runTokens(['action' => 'count', 'targets' => [$root . '/note.md'], '--dir' => $root], 0);

    $this->assertStringContainsString('     3  note.md', $output);
    $this->assertStringContainsString('1 file(s),', $output);
  }

  public function testCountDefaultsToCurrentDirectory(): void {
    $output = $this->runTokens(['action' => 'count', 'targets' => ['README.md']], 0);

    $this->assertStringContainsString('README.md', $output);
    $this->assertStringContainsString('1 file(s),', $output);
  }

  public function testCountMissingPathIsError(): void {
    $root = $this->vfsRoot();

    $output = $this->runTokens(['action' => 'count', 'targets' => ['nowhere.md'], '--dir' => $root], 2);

    $this->assertStringContainsString("path 'nowhere.md' does not exist", $output);
  }

  public function testCountWithoutPathsIsError(): void {
    $root = $this->vfsRoot();

    $output = $this->runTokens(['action' => 'count', '--dir' => $root], 2);

    $this->assertStringContainsString('expects at least one path', $output);
  }

  #[DataProvider('dataProviderInvalidFlagsAreErrors')]
  public function testInvalidFlagsAreErrors(array $input, string $message): void {
    $input['--dir'] = vfsStream::setup('root')->url();

    $output = $this->runTokens($input, 2);

    $this->assertStringContainsString($message, $output);
  }

  public static function dataProviderInvalidFlagsAreErrors(): \Iterator {
    yield 'unknown action' => [['action' => 'destroy'], 'unknown action'];
    yield 'unknown format' => [['action' => 'count', 'targets' => ['x'], '--format' => 'xml'], 'unknown format'];
    yield 'unknown sort' => [['action' => 'count', 'targets' => ['x'], '--sort' => 'size'], 'unknown sort'];
    yield 'non-numeric threshold' => [['action' => 'compare', '--threshold' => 'lots'], 'threshold must be a non-negative number'];
    yield 'negative threshold' => [['action' => 'compare', '--threshold' => '-5'], 'threshold must be a non-negative number'];
    yield 'multiple refs' => [['action' => 'compare', 'targets' => ['main', 'develop']], 'at most one ref'];
  }

  public function testCompareCatchesSeededGrowthBeyondThreshold(): void {
    $root = $this->gitRepo(['skills/foo/SKILL.md' => $this->paddedSkill(400), 'skills/foo/eval.yaml' => "version: \"1\"\n"]);
    file_put_contents($root . '/skills/foo/SKILL.md', $this->paddedSkill(480));

    $output = $this->runTokens(['action' => 'compare', '--dir' => $root, '--threshold' => '10'], 1);

    $this->assertStringContainsString('skills/foo/SKILL.md 100 -> 120 token(s) (+20, +20.0%)', $output);
    $this->assertStringContainsString('FAIL skills/foo/SKILL.md grew 20.0% (100 -> 120 tokens), above the 10.0% threshold.', $output);
    $this->assertStringContainsString('compared against main:', $output);
    $this->assertStringContainsString('1 violation(s)', $output);
  }

  public function testCompareWithinThresholdPasses(): void {
    $root = $this->gitRepo(['skills/foo/SKILL.md' => $this->paddedSkill(400), 'skills/foo/eval.yaml' => "version: \"1\"\n"]);
    file_put_contents($root . '/skills/foo/SKILL.md', $this->paddedSkill(480));

    $output = $this->runTokens(['action' => 'compare', '--dir' => $root, '--threshold' => '25'], 0);

    $this->assertStringContainsString('0 violation(s)', $output);
  }

  public function testCompareNewFileIsExemptFromThreshold(): void {
    $root = $this->gitRepo(['skills/foo/SKILL.md' => $this->paddedSkill(400), 'skills/foo/eval.yaml' => "version: \"1\"\n"]);
    mkdir($root . '/skills/bar', 0777, TRUE);
    file_put_contents($root . '/skills/bar/SKILL.md', "---\nname: bar\ndescription: Another compare skill.\n---\nBody.\n");
    file_put_contents($root . '/skills/bar/eval.yaml', "version: \"1\"\n");

    $output = $this->runTokens(['action' => 'compare', '--dir' => $root, '--threshold' => '10'], 0);

    $this->assertStringContainsString('skills/bar/SKILL.md (new)', $output);
    $this->assertStringContainsString('1 new, 0 violation(s)', $output);
  }

  public function testCompareStrictEnforcesAbsoluteLimitOnNewFile(): void {
    $root = $this->gitRepo(['skills/foo/SKILL.md' => $this->paddedSkill(400), 'skills/foo/eval.yaml' => "version: \"1\"\n"]);
    mkdir($root . '/skills/bar', 0777, TRUE);
    file_put_contents($root . '/skills/bar/SKILL.md', "---\nname: bar\ndescription: Another compare skill.\n---\nBody.\n");
    file_put_contents($root . '/skills/bar/eval.yaml', "version: \"1\"\nstructure:\n  params:\n    structure.token-budget:\n      limit: 5\n");

    $output = $this->runTokens(['action' => 'compare', '--dir' => $root, '--threshold' => '10', '--strict' => TRUE], 1);

    $this->assertStringContainsString('above the absolute limit of 5.', $output);
    $this->assertStringContainsString('1 violation(s)', $output);
  }

  public function testCompareGrowthFromEmptyRefViolatesAnyThreshold(): void {
    $root = $this->gitRepo(['skills/foo/SKILL.md' => $this->paddedSkill(400), 'skills/foo/eval.yaml' => "version: \"1\"\n", 'skills/foo/references/notes.md' => '']);
    file_put_contents($root . '/skills/foo/references/notes.md', str_repeat('x', 40));

    $output = $this->runTokens(['action' => 'compare', '--dir' => $root, '--threshold' => '50'], 1);

    $this->assertStringContainsString('skills/foo/references/notes.md 0 -> 10 token(s) (+10, n/a)', $output);
    $this->assertStringContainsString('FAIL skills/foo/references/notes.md grew from 0 to 10 tokens, above the 50.0% threshold.', $output);
  }

  public function testCompareWithoutGateFlagsOnlyReports(): void {
    $root = $this->gitRepo(['skills/foo/SKILL.md' => $this->paddedSkill(400), 'skills/foo/eval.yaml' => "version: \"1\"\n"]);
    file_put_contents($root . '/skills/foo/SKILL.md', $this->paddedSkill(480));

    $output = $this->runTokens(['action' => 'compare', '--dir' => $root], 0);

    $this->assertStringContainsString('skills/foo/SKILL.md 100 -> 120 token(s)', $output);
    $this->assertStringContainsString('0 violation(s)', $output);
  }

  public function testCompareUnchangedFilesAreCountedButNotListed(): void {
    $root = $this->gitRepo(['skills/foo/SKILL.md' => $this->paddedSkill(400), 'skills/foo/eval.yaml' => "version: \"1\"\n", 'skills/foo/references/guide.md' => "A stable reference.\n"]);
    file_put_contents($root . '/skills/foo/SKILL.md', $this->paddedSkill(480));

    $output = $this->runTokens(['action' => 'compare', '--dir' => $root], 0);

    $this->assertStringNotContainsString('guide.md', $output);
    $this->assertStringContainsString('2 file(s) compared against main: 1 changed, 0 new, 0 violation(s)', $output);
  }

  public function testCompareJsonEmitsFilesViolationsAndSummary(): void {
    $root = $this->gitRepo(['skills/foo/SKILL.md' => $this->paddedSkill(400), 'skills/foo/eval.yaml' => "version: \"1\"\n"]);
    file_put_contents($root . '/skills/foo/SKILL.md', $this->paddedSkill(480));

    $decoded = $this->decode($this->runTokens(['action' => 'compare', '--dir' => $root, '--threshold' => '10', '--format' => 'json'], 1));

    $this->assertFalse($decoded['ok']);
    $this->assertSame('main', $decoded['ref']);
    $this->assertSame('estimate', $decoded['method']);
    $this->assertSame(10.0, $decoded['threshold']);
    $this->assertFalse($decoded['strict']);
    $this->assertSame(['path' => 'skills/foo/SKILL.md', 'tokens' => 120, 'ref_tokens' => 100, 'delta' => 20, 'growth_pct' => 20.0, 'new' => FALSE], $decoded['files'][0]);
    $this->assertIsArray($decoded['violations']);
    $this->assertCount(1, $decoded['violations']);
    $this->assertSame(['files' => 1, 'changed' => 1, 'new' => 0, 'violations' => 1], $decoded['summary']);
  }

  public function testCompareExplicitUnknownRefIsError(): void {
    $root = $this->gitRepo(['skills/foo/SKILL.md' => self::CLEAN_FOO, 'skills/foo/eval.yaml' => "version: \"1\"\n"]);

    $output = $this->runTokens(['action' => 'compare', 'targets' => ['bogus-ref'], '--dir' => $root], 2);

    $this->assertStringContainsString("git ref 'bogus-ref' does not resolve", $output);
  }

  public function testCompareWithoutResolvableRefIsError(): void {
    $this->tempDir = dirname(__DIR__, 3) . '/.artifacts/tmp/tokenscmd-' . getmypid() . '-' . uniqid();
    mkdir($this->tempDir . '/skills/foo', 0777, TRUE);
    file_put_contents($this->tempDir . '/skills/foo/SKILL.md', self::CLEAN_FOO);
    file_put_contents($this->tempDir . '/skills/foo/eval.yaml', "version: \"1\"\n");
    $this->git('init --initial-branch=main --quiet');

    $output = $this->runTokens(['action' => 'compare', '--dir' => $this->tempDir], 2);

    $this->assertStringContainsString("neither 'origin/main' nor 'main' resolves", $output);
    $this->assertStringContainsString('pass an explicit ref', $output);
  }

  /**
   * Builds the virtual tree used by the count tests.
   *
   * @return string
   *   The virtual root URL.
   */
  protected function vfsRoot(): string {
    return vfsStream::setup('root', NULL, [
      'skills' => [
        'foo' => [
          'SKILL.md' => self::CLEAN_FOO,
          'eval.yaml' => "version: \"1\"\n",
          'references' => ['guide.md' => "Short.\n"],
          'script.sh' => "#!/bin/sh\n",
        ],
      ],
      'vocab.tiktoken' => self::VOCAB,
      'note.md' => 'hello world',
    ])->url();
  }

  /**
   * Builds a real git repository seeded with one committed tree.
   *
   * @param array<string, string> $files
   *   The files to write and commit, keyed by repo-relative path.
   *
   * @return string
   *   The repository root.
   */
  protected function gitRepo(array $files): string {
    $this->tempDir = dirname(__DIR__, 3) . '/.artifacts/tmp/tokenscmd-' . getmypid() . '-' . uniqid();
    mkdir($this->tempDir, 0777, TRUE);

    foreach ($files as $path => $content) {
      $absolute = $this->tempDir . '/' . $path;

      if (!is_dir(dirname($absolute))) {
        mkdir(dirname($absolute), 0777, TRUE);
      }

      file_put_contents($absolute, $content);
    }

    $this->git('init --initial-branch=main --quiet');
    $this->git('add -A');
    $this->git('-c user.email=test@example.com -c user.name=Test commit -m seed --quiet');

    return $this->tempDir;
  }

  /**
   * Runs a git subcommand in the temporary repository, asserting success.
   *
   * @param string $args
   *   The git arguments.
   */
  protected function git(string $args): void {
    exec('git -C ' . escapeshellarg($this->tempDir) . ' ' . $args . ' 2>/dev/null', $output, $exit_code);

    $this->assertSame(0, $exit_code, 'git ' . $args);
  }

  /**
   * Builds a SKILL.md padded to an exact byte length.
   *
   * A length that is a multiple of four keeps the estimated token count a
   * round number, so growth percentages in assertions stay exact.
   *
   * @param int $bytes
   *   The exact content length in bytes.
   *
   * @return string
   *   The SKILL.md content.
   */
  protected function paddedSkill(int $bytes): string {
    $header = "---\nname: foo\ndescription: A clean skill for compare tests.\n---\n# Foo\n";

    return $header . str_repeat('x', $bytes - strlen($header) - 1) . "\n";
  }

  /**
   * Runs the tokens command and asserts the exit code.
   *
   * @param array<string, mixed> $input
   *   The command input.
   * @param int $expected_exit
   *   The expected exit code.
   *
   * @return string
   *   The command standard output.
   */
  protected function runTokens(array $input, int $expected_exit): string {
    $this->applicationInitFromCommand(TokensCommand::class);
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
