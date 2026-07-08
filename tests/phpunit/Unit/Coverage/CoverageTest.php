<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Coverage;

use AlexSkrypnyk\SkillTest\Config\EffectiveConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedSkill;
use AlexSkrypnyk\SkillTest\Config\RepoConfig;
use AlexSkrypnyk\SkillTest\Coverage\Coverage;
use AlexSkrypnyk\SkillTest\Coverage\CoverageRow;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class CoverageTest.
 *
 * Unit test for the coverage grid and gate computation.
 */
#[CoversClass(Coverage::class)]
final class CoverageTest extends TestCase {

  public function testRowsSortedWithEveryStatus(): void {
    $root = vfsStream::setup('root', NULL, [
      'skills' => ['covered' => ['fixtures' => ['t.jsonl' => '{}']]],
    ])->url();

    $covered = $this->skill($root, 'skills/covered', [
      'skill' => 'covered',
      'deterministic' => ['transcript' => 'fixtures/t.jsonl'],
      'llm' => ['tasks' => [['name' => 'a'], ['name' => 'b']]],
    ]);

    $coverage = new Coverage($this->config(
      $root,
      [$covered],
      ['skills/uncovered', 'skills/legacy'],
      ['paths' => ['exclude' => [['skill' => 'legacy', 'reason' => 'not testable']]]],
    ));

    $this->assertSame(['skills/covered', 'skills/legacy', 'skills/uncovered'], array_map(static fn(CoverageRow $row): string => $row->path, $coverage->rows));

    $this->assertTrue($coverage->rows[0]->eval);
    $this->assertTrue($coverage->rows[0]->transcript);
    $this->assertSame(2, $coverage->rows[0]->tasks);
    $this->assertSame(CoverageRow::STATUS_COVERED, $coverage->rows[0]->status());

    $this->assertSame('legacy', $coverage->rows[1]->skill);
    $this->assertTrue($coverage->rows[1]->excluded);
    $this->assertSame('not testable', $coverage->rows[1]->reason);
    $this->assertSame(CoverageRow::STATUS_EXCLUDED, $coverage->rows[1]->status());

    $this->assertSame(CoverageRow::STATUS_UNCOVERED, $coverage->rows[2]->status());
  }

  public function testViolationsAndSummary(): void {
    $root = vfsStream::setup('root')->url();

    $coverage = new Coverage($this->config(
      $root,
      [$this->skill($root, 'skills/covered', ['skill' => 'covered'])],
      ['skills/uncovered', 'skills/legacy'],
      ['paths' => ['exclude' => [['skill' => 'legacy', 'reason' => 'not testable']]]],
    ));

    $violations = $coverage->violations();
    $this->assertCount(1, $violations);
    $this->assertSame('uncovered', $violations[0]->skill);

    $this->assertSame(['total' => 3, 'covered' => 1, 'excluded' => 1, 'uncovered' => 1], $coverage->summary());
  }

  public function testTranscriptMissingOrUndeclaredIsFalse(): void {
    $root = vfsStream::setup('root')->url();

    $declared_absent = $this->skill($root, 'skills/a', ['skill' => 'a', 'deterministic' => ['transcript' => 'fixtures/missing.jsonl']]);
    $undeclared = $this->skill($root, 'skills/b', ['skill' => 'b']);
    $absolute = $this->skill($root, 'skills/c', ['skill' => 'c', 'deterministic' => ['transcript' => '/nonexistent/abs.jsonl']]);

    $coverage = new Coverage($this->config($root, [$declared_absent, $undeclared, $absolute], []));

    $this->assertFalse($coverage->rows[0]->transcript);
    $this->assertFalse($coverage->rows[1]->transcript);
    $this->assertFalse($coverage->rows[2]->transcript);
  }

  public function testExcludeEntryWithoutSkillIsIgnored(): void {
    $root = vfsStream::setup('root')->url();

    $coverage = new Coverage($this->config(
      $root,
      [],
      ['skills/uncovered'],
      ['paths' => ['exclude' => [['reason' => 'orphan']]]],
    ));

    $this->assertCount(1, $coverage->violations());
    $this->assertFalse($coverage->rows[0]->excluded);
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
   *
   * @return \AlexSkrypnyk\SkillTest\Config\LoadedSkill
   *   The loaded skill.
   */
  protected function skill(string $root, string $dir, array $eval): LoadedSkill {
    $file = $root . '/' . $dir . '/eval.yaml';
    $effective = EffectiveConfig::resolve(RepoConfig::fromArray([]), $eval, [], basename($dir), $dir);

    return new LoadedSkill($file, $eval, $effective);
  }

  /**
   * Builds a loaded configuration from skills and uncovered directories.
   *
   * @param string $root
   *   The repository root URL.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill[] $skills
   *   The loaded skills.
   * @param string[] $without_eval
   *   The discovered directories that lack an `eval.yaml`.
   * @param array<mixed> $repo_data
   *   The raw repo config.
   *
   * @return \AlexSkrypnyk\SkillTest\Config\LoadedConfig
   *   The loaded configuration.
   */
  protected function config(string $root, array $skills, array $without_eval, array $repo_data = []): LoadedConfig {
    $repo = RepoConfig::fromArray($repo_data);
    $repo_file = $repo_data === [] ? '' : $root . '/skilltest.yml';

    return new LoadedConfig($repo, $repo_data, $repo_file, $skills, $without_eval);
  }

}
