<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Structure;

use AlexSkrypnyk\SkillTest\Config\EffectiveConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedSkill;
use AlexSkrypnyk\SkillTest\Config\RepoConfig;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Structure\StructureChecker;
use AlexSkrypnyk\SkillTest\Structure\StructureResult;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class StructureCheckerTest.
 *
 * Unit test for the deterministic structure group: each check passes on a
 * well-formed skill and fails on a targeted broken one, and suppression is
 * honoured only with a written reason.
 */
#[CoversClass(StructureChecker::class)]
final class StructureCheckerTest extends TestCase {

  /**
   * A well-formed SKILL.md that passes every check.
   */
  protected const string CLEAN = "---\nname: foo\ndescription: A clean well-formed skill for tests.\n---\n# Foo\n\nBody.\n";

  /**
   * The repo config that enables the command-reference check.
   *
   * @var array<string, mixed>
   */
  protected const array RESOLVE_REPO = ['commands' => ['resolve' => ['binary' => 'bin/harness', 'list-args' => ['list', '--json']]]];

  #[DataProvider('dataProviderSingleCheck')]
  public function testSingleCheck(string $skill_md, string $check_id, string $status, string $message_substring): void {
    $result = $this->only($this->results($this->dir($skill_md), []), $check_id);

    $this->assertSame($status, $result->status, $result->message);
    $this->assertStringContainsString($message_substring, $result->message);
  }

  public static function dataProviderSingleCheck(): \Iterator {
    $desc = "description: A clean well-formed skill for tests.";

    yield 'frontmatter passes' => [self::CLEAN, StructureChecker::CHECK_FRONTMATTER, StructureResult::PASS, 'parses with a name and description'];
    yield 'frontmatter fails without a block' => ["# no frontmatter\nbody\n", StructureChecker::CHECK_FRONTMATTER, StructureResult::FAIL, 'no YAML frontmatter'];
    yield 'frontmatter fails when malformed' => ["---\nname: [unclosed\n---\nbody\n", StructureChecker::CHECK_FRONTMATTER, StructureResult::FAIL, 'does not parse'];
    yield 'frontmatter fails without a name' => ["---\n{$desc}\n---\nbody\n", StructureChecker::CHECK_FRONTMATTER, StructureResult::FAIL, "'name:' is missing"];
    yield 'frontmatter fails with an empty description' => ["---\nname: foo\ndescription: \"\"\n---\nbody\n", StructureChecker::CHECK_FRONTMATTER, StructureResult::FAIL, "'description:' is missing or empty"];

    yield 'name matches directory' => [self::CLEAN, StructureChecker::CHECK_NAME_MATCHES_DIR, StructureResult::PASS, "matches the directory 'foo'"];
    yield 'name mismatch fails' => ["---\nname: bar\n{$desc}\n---\nbody\n", StructureChecker::CHECK_NAME_MATCHES_DIR, StructureResult::FAIL, "does not match directory 'foo'"];
    yield 'name missing cannot match' => ["---\n{$desc}\n---\nbody\n", StructureChecker::CHECK_NAME_MATCHES_DIR, StructureResult::FAIL, 'cannot match the directory'];

    yield 'description within bounds' => [self::CLEAN, StructureChecker::CHECK_DESCRIPTION_LENGTH, StructureResult::PASS, 'is within'];
    yield 'description too short' => ["---\nname: foo\ndescription: short\n---\nbody\n", StructureChecker::CHECK_DESCRIPTION_LENGTH, StructureResult::FAIL, 'below the minimum'];
    yield 'description missing' => ["---\nname: foo\n---\nbody\n", StructureChecker::CHECK_DESCRIPTION_LENGTH, StructureResult::FAIL, 'is missing'];

    yield 'allowed-tools absent passes' => [self::CLEAN, StructureChecker::CHECK_ALLOWED_TOOLS_DECLARED, StructureResult::PASS, 'no tool restriction'];
    yield 'allowed-tools string passes' => ["---\nname: foo\n{$desc}\nallowed-tools: Read, Bash(git:*)\n---\nbody\n", StructureChecker::CHECK_ALLOWED_TOOLS_DECLARED, StructureResult::PASS, 'parses'];
    yield 'allowed-tools list passes' => ["---\nname: foo\n{$desc}\nallowed-tools:\n  - Read\n  - Bash\n---\nbody\n", StructureChecker::CHECK_ALLOWED_TOOLS_DECLARED, StructureResult::PASS, 'parses'];
    yield 'allowed-tools null fails' => ["---\nname: foo\n{$desc}\nallowed-tools:\n---\nbody\n", StructureChecker::CHECK_ALLOWED_TOOLS_DECLARED, StructureResult::FAIL, 'does not parse to a tool string or list'];
    yield 'allowed-tools map fails' => ["---\nname: foo\n{$desc}\nallowed-tools:\n  a: b\n---\nbody\n", StructureChecker::CHECK_ALLOWED_TOOLS_DECLARED, StructureResult::FAIL, 'does not parse'];
    yield 'allowed-tools list with a non-scalar item fails' => ["---\nname: foo\n{$desc}\nallowed-tools:\n  - Read\n  - nested: value\n---\nbody\n", StructureChecker::CHECK_ALLOWED_TOOLS_DECLARED, StructureResult::FAIL, 'does not parse'];

    yield 'no unrestricted bash passes when absent' => [self::CLEAN, StructureChecker::CHECK_NO_UNRESTRICTED_BASH, StructureResult::PASS, 'no unrestricted Bash'];
    yield 'restricted bash passes' => ["---\nname: foo\n{$desc}\nallowed-tools: Bash(git:*)\n---\nbody\n", StructureChecker::CHECK_NO_UNRESTRICTED_BASH, StructureResult::PASS, 'no unrestricted Bash'];
    yield 'unrestricted bash star fails' => ["---\nname: foo\n{$desc}\nallowed-tools: Bash(*)\n---\nbody\n", StructureChecker::CHECK_NO_UNRESTRICTED_BASH, StructureResult::FAIL, 'unrestricted Bash'];
    yield 'unrestricted bash colon-star fails' => ["---\nname: foo\n{$desc}\nallowed-tools: Bash(:*)\n---\nbody\n", StructureChecker::CHECK_NO_UNRESTRICTED_BASH, StructureResult::FAIL, 'unrestricted Bash'];

    yield 'no pre-model exec passes' => [self::CLEAN, StructureChecker::CHECK_NO_PRE_MODEL_EXEC, StructureResult::PASS, 'no pre-model command'];
    yield 'pre-model exec fails' => ["---\nname: foo\n{$desc}\n---\n# B\nContext: !`date`\n", StructureChecker::CHECK_NO_PRE_MODEL_EXEC, StructureResult::FAIL, 'pre-model dynamic-context'];
  }

  public function testDescriptionLengthParamsOverrideMax(): void {
    $eval = ['structure' => ['params' => ['structure.description-length' => ['max' => 10]]]];
    $result = $this->only($this->results($this->dir(self::CLEAN), $eval), StructureChecker::CHECK_DESCRIPTION_LENGTH);

    $this->assertSame(StructureResult::FAIL, $result->status);
    $this->assertStringContainsString('above the maximum of 10', $result->message);
  }

  public function testDescriptionLengthParamsOverrideMin(): void {
    $eval = ['structure' => ['params' => ['structure.description-length' => ['min' => 1]]]];
    $skill_md = "---\nname: foo\ndescription: short\n---\nbody\n";
    $result = $this->only($this->results($this->dir($skill_md), $eval), StructureChecker::CHECK_DESCRIPTION_LENGTH);

    $this->assertSame(StructureResult::PASS, $result->status);
  }

  public function testNoUnrestrictedBashReportsTheLine(): void {
    $skill_md = "---\nname: foo\ndescription: A clean well-formed skill for tests.\nallowed-tools: Bash(*)\n---\nbody\n";
    $result = $this->only($this->results($this->dir($skill_md), []), StructureChecker::CHECK_NO_UNRESTRICTED_BASH);

    $this->assertSame(4, $result->line);
    $this->assertStringContainsString('Bash(*)', $result->evidence);
  }

  public function testNoUnrestrictedBashCatchesMultilineListForm(): void {
    $skill_md = "---\nname: foo\ndescription: A clean well-formed skill for tests.\nallowed-tools:\n  - Read\n  - Bash(*)\n---\nbody\n";
    $result = $this->only($this->results($this->dir($skill_md), []), StructureChecker::CHECK_NO_UNRESTRICTED_BASH);

    $this->assertSame(StructureResult::FAIL, $result->status);
    $this->assertSame('Bash(*)', $result->evidence);
    $this->assertSame(6, $result->line);
  }

  public function testNoUnrestrictedBashIgnoresBashInBody(): void {
    $skill_md = "---\nname: foo\ndescription: A clean well-formed skill for tests.\n---\n# Body\nExample: `allowed-tools: Bash(*)` grants everything.\n";
    $result = $this->only($this->results($this->dir($skill_md), []), StructureChecker::CHECK_NO_UNRESTRICTED_BASH);

    $this->assertSame(StructureResult::PASS, $result->status);
  }

  public function testPreModelExecReportsFileLineNotBodyLine(): void {
    $skill_md = "---\nname: foo\ndescription: A clean well-formed skill for tests.\n---\n# B\nContext: !`date`\n";
    $result = $this->only($this->results($this->dir($skill_md), []), StructureChecker::CHECK_NO_PRE_MODEL_EXEC);

    $this->assertSame(6, $result->line);
    $this->assertStringContainsString('!`date`', $result->evidence);
  }

  public function testFilesExistPassesForExistingReferences(): void {
    $skill_md = "---\nname: foo\ndescription: A clean well-formed skill for tests.\n---\n# Foo\nSee [guide](references/guide.md) and `scripts/build.sh` and `SKILL.md`.\n";
    $files = $this->dir($skill_md, ['references' => ['guide.md' => "g\n"], 'scripts' => ['build.sh' => "#!/bin/sh\n"]]);

    $result = $this->only($this->results($files, []), StructureChecker::CHECK_FILES_EXIST);

    $this->assertSame(StructureResult::PASS, $result->status);
  }

  public function testFilesExistResolvesDotSlashPrefix(): void {
    $skill_md = "---\nname: foo\ndescription: A clean well-formed skill for tests.\n---\nSee [readme](./references/guide.md).\n";
    $files = $this->dir($skill_md, ['references' => ['guide.md' => "g\n"]]);

    $result = $this->only($this->results($files, []), StructureChecker::CHECK_FILES_EXIST);

    $this->assertSame(StructureResult::PASS, $result->status);
  }

  public function testFilesExistFailsForMissingReference(): void {
    $skill_md = "---\nname: foo\ndescription: A clean well-formed skill for tests.\n---\nSee `references/missing.md`.\n";
    $result = $this->only($this->results($this->dir($skill_md), []), StructureChecker::CHECK_FILES_EXIST);

    $this->assertSame(StructureResult::FAIL, $result->status);
    $this->assertStringContainsString('references/missing.md', $result->message);
    $this->assertSame('references/missing.md', $result->evidence);
    $this->assertSame(5, $result->line);
  }

  public function testFilesExistIgnoresUrlsAnchorsCommandsAndAbsolutePaths(): void {
    $body = "URL [site](https://example.com/x). Anchor [top](#intro). Command `ahoy lint`. Absolute `/etc/hosts`. Word `git`.";
    $skill_md = "---\nname: foo\ndescription: A clean well-formed skill for tests.\n---\n{$body}\n";
    $result = $this->only($this->results($this->dir($skill_md), []), StructureChecker::CHECK_FILES_EXIST);

    $this->assertSame(StructureResult::PASS, $result->status);
  }

  public function testFilesExistFailsForParentDirectoryReference(): void {
    $skill_md = "---\nname: foo\ndescription: A clean well-formed skill for tests.\n---\nSee `../out.md` for details.\n";
    $result = $this->only($this->results($this->dir($skill_md), []), StructureChecker::CHECK_FILES_EXIST);

    $this->assertSame(StructureResult::FAIL, $result->status);
    $this->assertStringContainsString('escapes the skill directory', $result->message);
    $this->assertSame('../out.md', $result->evidence);
  }

  public function testContractCoherentPassesForCoherentEval(): void {
    $result = $this->only($this->results($this->dir(self::CLEAN), []), StructureChecker::CHECK_CONTRACT_COHERENT);

    $this->assertSame(StructureResult::PASS, $result->status);
  }

  public function testContractCoherentFailsAndCarriesTheError(): void {
    $eval = ['contract' => ['tools' => ['required' => ['Bash'], 'forbidden' => ['Bash']]]];
    $result = $this->only($this->results($this->dir(self::CLEAN), $eval), StructureChecker::CHECK_CONTRACT_COHERENT);

    $this->assertSame(StructureResult::FAIL, $result->status);
    $this->assertStringContainsString('1 coherence error', $result->message);
    $this->assertStringContainsString('both required and forbidden', $result->evidence);
  }

  public function testCommandRefsCheckIsAbsentWhenDisabled(): void {
    $checks = array_map(static fn(StructureResult $result): string => $result->check, $this->results($this->dir(self::CLEAN), []));

    $this->assertNotContains(StructureChecker::CHECK_COMMAND_REFS_RESOLVE, $checks);
  }

  public function testCommandRefsResolvePassesForKnownCommands(): void {
    $skill_md = "---\nname: foo\ndescription: A clean well-formed skill for tests.\n---\nRun `bin/harness build` then `harness test`.\n";
    $runner = fn(): array => [0, '["build","test"]'];

    $result = $this->only($this->results($this->dir($skill_md), [], self::RESOLVE_REPO, $runner), StructureChecker::CHECK_COMMAND_REFS_RESOLVE);

    $this->assertSame(StructureResult::PASS, $result->status);
  }

  public function testCommandRefsResolveFailsForUnknownCommand(): void {
    $skill_md = "---\nname: foo\ndescription: A clean well-formed skill for tests.\n---\nRun `harness deploy` now.\n";
    $runner = fn(): array => [0, '["build","test"]'];

    $result = $this->only($this->results($this->dir($skill_md), [], self::RESOLVE_REPO, $runner), StructureChecker::CHECK_COMMAND_REFS_RESOLVE);

    $this->assertSame(StructureResult::FAIL, $result->status);
    $this->assertStringContainsString("'deploy' is not a command", $result->message);
    $this->assertStringContainsString('harness deploy', $result->evidence);
  }

  public function testCommandRefsResolveScansBundledScriptsAndSkipsEval(): void {
    $skill_md = "---\nname: foo\ndescription: A clean well-formed skill for tests.\n---\n# Foo\n";
    $files = [
      'SKILL.md' => $skill_md,
      'eval.yaml' => "version: \"1\"\nnote: run bin/harness bogus\n",
      'run.sh' => "#!/bin/sh\nbin/harness deploy\n",
    ];
    $runner = fn(): array => [0, '["build"]'];

    $result = $this->only($this->results($files, [], self::RESOLVE_REPO, $runner), StructureChecker::CHECK_COMMAND_REFS_RESOLVE);

    $this->assertSame(StructureResult::FAIL, $result->status);
    $this->assertStringContainsString('run.sh', $result->file);
    $this->assertStringContainsString("'deploy'", $result->message);
  }

  public function testCommandRefsResolveThrowsWhenBinaryMissing(): void {
    $repo = ['commands' => ['resolve' => ['list-args' => ['list']]]];

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('names no binary');

    $this->results($this->dir(self::CLEAN), [], $repo, fn(): array => [0, '[]']);
  }

  public function testCommandRefsResolveThrowsWhenBinaryFails(): void {
    $runner = fn(): array => [1, ''];

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('failed (exit 1)');

    $this->results($this->dir(self::CLEAN), [], self::RESOLVE_REPO, $runner);
  }

  public function testTokenBudgetPassesWithinDefaultBudget(): void {
    $result = $this->only($this->results($this->dir(self::CLEAN), []), StructureChecker::CHECK_TOKEN_BUDGET);

    $this->assertSame(StructureResult::PASS, $result->status);
    $this->assertStringContainsString('within the budget of 5000', $result->message);
  }

  public function testTokenBudgetFailsOverTheLimit(): void {
    $eval = ['structure' => ['params' => ['structure.token-budget' => ['limit' => 10, 'warn-at' => 5]]]];
    $result = $this->only($this->results($this->dir(self::CLEAN), $eval), StructureChecker::CHECK_TOKEN_BUDGET);

    $this->assertSame(StructureResult::FAIL, $result->status);
    $this->assertStringContainsString('above the limit of 10', $result->message);
    $this->assertStringContainsString('tokens (estimate)', $result->evidence);
    $this->assertTrue($result->failed());
  }

  public function testTokenBudgetWarnsWithinWarnAt(): void {
    $eval = ['structure' => ['params' => ['structure.token-budget' => ['limit' => 30, 'warn-at' => 20]]]];
    $result = $this->only($this->results($this->dir(self::CLEAN), $eval), StructureChecker::CHECK_TOKEN_BUDGET);

    $this->assertSame(StructureResult::WARN, $result->status);
    $this->assertStringContainsString('at or above the warn threshold of 20 (limit 30)', $result->message);
    $this->assertFalse($result->failed());
  }

  public function testTokenBudgetUsesVocabParam(): void {
    $root = vfsStream::setup('root', NULL, [
      'skills' => ['foo' => $this->dir(self::CLEAN)],
      'vocab.tiktoken' => "aA== 0\n",
    ])->url();
    $eval = ['structure' => ['params' => ['structure.token-budget' => ['vocab' => 'vocab.tiktoken']]]];
    $loaded = new LoadedConfig(RepoConfig::fromArray([]), [], '', [$this->skill($root, 'skills/foo', $eval)], []);

    $result = $this->only((new StructureChecker($root))->check($loaded), StructureChecker::CHECK_TOKEN_BUDGET);

    $this->assertSame(StructureResult::PASS, $result->status);
    $this->assertStringContainsString('(bpe)', $result->message);
  }

  public function testAdvisoryPassesForCleanSkill(): void {
    $advisories = $this->allOf($this->results($this->dir(self::CLEAN), []), StructureChecker::CHECK_ADVISORY);

    $this->assertCount(1, $advisories);
    $this->assertSame(StructureResult::PASS, $advisories[0]->status);
    $this->assertStringContainsString('no quality advisories', $advisories[0]->message);
  }

  public function testAdvisoryWarnsOnOverLongProcedure(): void {
    $steps = [];

    for ($index = 1; $index <= 21; $index++) {
      $steps[] = $index . '. Do the next thing.';
    }

    $skill_md = "---\nname: foo\ndescription: A clean well-formed skill for tests.\n---\n# Foo\n" . implode("\n", $steps) . "\n";
    $advisories = $this->allOf($this->results($this->dir($skill_md), []), StructureChecker::CHECK_ADVISORY);

    $this->assertCount(1, $advisories);
    $this->assertSame(StructureResult::WARN, $advisories[0]->status);
    $this->assertStringContainsString('over-long procedure of 21 numbered steps', $advisories[0]->message);
    $this->assertSame('21 numbered steps', $advisories[0]->evidence);
    $this->assertFalse($advisories[0]->failed());
  }

  public function testAdvisoryWarnsOnOverSpecificTriggerPhrasing(): void {
    $description = 'Triggers on "t1", "t2", "t3", "t4", "t5", "t6", "t7", "t8", "t9" and more.';
    $skill_md = "---\nname: foo\ndescription: {$description}\n---\n# Foo\n";
    $advisories = $this->allOf($this->results($this->dir($skill_md), []), StructureChecker::CHECK_ADVISORY);

    $this->assertCount(1, $advisories);
    $this->assertSame(StructureResult::WARN, $advisories[0]->status);
    $this->assertStringContainsString('enumerates 9 quoted trigger phrases', $advisories[0]->message);
  }

  public function testAdvisoryWarnsOnTooManyReferenceFiles(): void {
    $references = [];

    for ($index = 1; $index <= 13; $index++) {
      $references['r' . $index . '.md'] = "reference\n";
    }

    $advisories = $this->allOf($this->results($this->dir(self::CLEAN, ['references' => $references]), []), StructureChecker::CHECK_ADVISORY);

    $this->assertCount(1, $advisories);
    $this->assertSame(StructureResult::WARN, $advisories[0]->status);
    $this->assertStringContainsString('ships 13 reference markdown files', $advisories[0]->message);
  }

  public function testAdvisoryEmitsOneWarningPerFinding(): void {
    $steps = [];

    for ($index = 1; $index <= 21; $index++) {
      $steps[] = $index . '. Do the next thing.';
    }

    $references = [];

    for ($index = 1; $index <= 13; $index++) {
      $references['r' . $index . '.md'] = "reference\n";
    }

    $skill_md = "---\nname: foo\ndescription: A clean well-formed skill for tests.\n---\n# Foo\n" . implode("\n", $steps) . "\n";
    $advisories = $this->allOf($this->results($this->dir($skill_md, ['references' => $references]), []), StructureChecker::CHECK_ADVISORY);

    $this->assertCount(2, $advisories);
    $this->assertSame(StructureResult::WARN, $advisories[0]->status);
    $this->assertSame(StructureResult::WARN, $advisories[1]->status);
    $this->assertStringContainsString('numbered steps', $advisories[0]->message);
    $this->assertStringContainsString('reference markdown files', $advisories[1]->message);
  }

  public function testSuppressionRendersSuppressedWithReason(): void {
    $eval = ['structure' => ['suppress' => ['structure.name-matches-dir' => 'ships under a shared directory name']]];
    $skill_md = "---\nname: bar\ndescription: A clean well-formed skill for tests.\n---\nbody\n";

    $result = $this->only($this->results($this->dir($skill_md), $eval), StructureChecker::CHECK_NAME_MATCHES_DIR);

    $this->assertSame(StructureResult::SUPPRESSED, $result->status);
    $this->assertSame('ships under a shared directory name', $result->reason);
    $this->assertFalse($result->failed());
  }

  public function testEmptyReasonDoesNotSuppress(): void {
    $eval = ['structure' => ['suppress' => ['structure.name-matches-dir' => '']]];
    $skill_md = "---\nname: bar\ndescription: A clean well-formed skill for tests.\n---\nbody\n";

    $result = $this->only($this->results($this->dir($skill_md), $eval), StructureChecker::CHECK_NAME_MATCHES_DIR);

    $this->assertSame(StructureResult::FAIL, $result->status);
  }

  public function testCleanSkillRunsEveryCheckInOrderAndPasses(): void {
    $results = $this->results($this->dir(self::CLEAN), []);
    $checks = array_map(static fn(StructureResult $result): string => $result->check, $results);

    $this->assertSame([
      StructureChecker::CHECK_FRONTMATTER,
      StructureChecker::CHECK_NAME_MATCHES_DIR,
      StructureChecker::CHECK_DESCRIPTION_LENGTH,
      StructureChecker::CHECK_ALLOWED_TOOLS_DECLARED,
      StructureChecker::CHECK_NO_UNRESTRICTED_BASH,
      StructureChecker::CHECK_NO_PRE_MODEL_EXEC,
      StructureChecker::CHECK_FILES_EXIST,
      StructureChecker::CHECK_TOKEN_BUDGET,
      StructureChecker::CHECK_CONTRACT_COHERENT,
      StructureChecker::CHECK_ADVISORY,
    ], $checks);

    foreach ($results as $result) {
      $this->assertSame(StructureResult::PASS, $result->status, $result->check . ': ' . $result->message);
    }
  }

  public function testEverySkillIsChecked(): void {
    $root = vfsStream::setup('root', NULL, [
      'skills' => [
        'foo' => $this->dir(self::CLEAN),
        'bar' => $this->dir("---\nname: bar\ndescription: Another clean skill used for tests.\n---\nbody\n"),
      ],
    ])->url();

    $skills = [
      $this->skill($root, 'skills/foo', []),
      $this->skill($root, 'skills/bar', []),
    ];

    $results = (new StructureChecker($root))->check(new LoadedConfig(RepoConfig::fromArray([]), [], '', $skills, []));
    $named = array_values(array_filter($results, static fn(StructureResult $result): bool => $result->check === StructureChecker::CHECK_FRONTMATTER));

    $this->assertSame(['foo', 'bar'], array_map(static fn(StructureResult $result): string => $result->skill, $named));
  }

  /**
   * Builds a skill directory tree with a SKILL.md and a minimal eval.yaml.
   *
   * @param string $skill_md
   *   The SKILL.md content.
   * @param array<string, mixed> $extra
   *   Extra files or directories in the skill directory.
   *
   * @return array<string, mixed>
   *   The vfs tree for the skill directory.
   */
  protected function dir(string $skill_md, array $extra = []): array {
    return ['SKILL.md' => $skill_md, 'eval.yaml' => "version: \"1\"\n"] + $extra;
  }

  /**
   * Runs the checker over a single skill at `skills/foo`.
   *
   * @param array<string, mixed> $skill_files
   *   The skill directory tree.
   * @param array<mixed> $eval
   *   The skill's parsed eval data.
   * @param array<string, mixed> $repo
   *   The repo config data.
   * @param \Closure|null $runner
   *   The injected command-list runner.
   *
   * @return \AlexSkrypnyk\SkillTest\Structure\StructureResult[]
   *   The results.
   */
  protected function results(array $skill_files, array $eval, array $repo = [], ?\Closure $runner = NULL): array {
    $root = vfsStream::setup('root', NULL, ['skills' => ['foo' => $skill_files]])->url();
    $loaded = new LoadedConfig(RepoConfig::fromArray($repo), $repo, $repo === [] ? '' : $root . '/skilltest.yml', [$this->skill($root, 'skills/foo', $eval, $repo)], []);

    return (new StructureChecker($root, $runner))->check($loaded);
  }

  /**
   * Builds a loaded skill rooted at a directory with the given eval data.
   *
   * @param string $root
   *   The repository root URL.
   * @param string $dir
   *   The skill directory, relative to the root.
   * @param array<mixed> $eval
   *   The parsed `eval.yaml`.
   * @param array<string, mixed> $repo
   *   The repo config data.
   *
   * @return \AlexSkrypnyk\SkillTest\Config\LoadedSkill
   *   The loaded skill.
   */
  protected function skill(string $root, string $dir, array $eval, array $repo = []): LoadedSkill {
    $file = $root . '/' . $dir . '/eval.yaml';
    $effective = EffectiveConfig::resolve(RepoConfig::fromArray($repo), $eval, [], basename($dir), $dir);

    return new LoadedSkill($file, $eval, $effective);
  }

  /**
   * Returns every result for a check id, in report order.
   *
   * @param \AlexSkrypnyk\SkillTest\Structure\StructureResult[] $results
   *   The results.
   * @param string $check_id
   *   The check id to find.
   *
   * @return \AlexSkrypnyk\SkillTest\Structure\StructureResult[]
   *   The matching results.
   */
  protected function allOf(array $results, string $check_id): array {
    return array_values(array_filter($results, static fn(StructureResult $result): bool => $result->check === $check_id));
  }

  /**
   * Returns the single result for a check id, failing when it is absent.
   *
   * @param \AlexSkrypnyk\SkillTest\Structure\StructureResult[] $results
   *   The results.
   * @param string $check_id
   *   The check id to find.
   *
   * @return \AlexSkrypnyk\SkillTest\Structure\StructureResult
   *   The matching result.
   */
  protected function only(array $results, string $check_id): StructureResult {
    foreach ($results as $result) {
      if ($result->check === $check_id) {
        return $result;
      }
    }

    $this->fail('No result for ' . $check_id);
  }

}
