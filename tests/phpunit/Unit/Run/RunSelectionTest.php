<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Run;

use AlexSkrypnyk\SkillTest\Config\EffectiveConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedSkill;
use AlexSkrypnyk\SkillTest\Config\RepoConfig;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Run\RunSelection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class RunSelectionTest.
 *
 * Unit test for the run selection validation and filtering.
 */
#[CoversClass(RunSelection::class)]
final class RunSelectionTest extends TestCase {

  public function testCreateAcceptsEveryGroup(): void {
    foreach (RunSelection::GROUPS as $group) {
      $selection = RunSelection::create([], $group, NULL);
      $this->assertSame($group, $selection->group);
    }
  }

  public function testCreateRejectsUnknownGroup(): void {
    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage("unknown group 'coverage'");

    RunSelection::create([], 'coverage', NULL);
  }

  public function testCreateRejectsUnknownCheckPrefix(): void {
    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage("unknown check id 'coverage.eval-exists'");

    RunSelection::create([], NULL, 'coverage.eval-exists');
  }

  public function testCreateRejectsCheckOutsideRequestedGroup(): void {
    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage("check 'structure.frontmatter' belongs to group 'structure', not 'security'");

    RunSelection::create([], 'security', 'structure.frontmatter');
  }

  public function testCreateAcceptsCheckMatchingRequestedGroup(): void {
    $selection = RunSelection::create([], 'transcript', 'contract.tools.required');

    $this->assertSame('contract.tools.required', $selection->check);
  }

  #[DataProvider('dataProviderOwnerGroup')]
  public function testOwnerGroup(string $check, ?string $expected): void {
    $this->assertSame($expected, RunSelection::ownerGroup($check));
  }

  /**
   * Data provider for testOwnerGroup.
   *
   * @return array<string, array{0: string, 1: string|null}>
   *   The cases.
   */
  public static function dataProviderOwnerGroup(): array {
    return [
      'structure' => ['structure.frontmatter', 'structure'],
      'security' => ['security.curl-pipe-shell', 'security'],
      'hooks' => ['hooks.reject-push', 'hooks'],
      'contract' => ['contract.commands.forbidden', 'transcript'],
      'custom check' => ['check.board-column', 'transcript'],
      'unknown' => ['coverage.eval-exists', NULL],
    ];
  }

  public function testRunsEveryGroupWithoutNarrowing(): void {
    $selection = RunSelection::create([], NULL, NULL);

    foreach (RunSelection::GROUPS as $group) {
      $this->assertTrue($selection->runs($group));
    }

    $this->assertTrue($selection->coverageGateRuns());
  }

  public function testGroupNarrowsToOneGroupAndDisablesGate(): void {
    $selection = RunSelection::create([], 'hooks', NULL);

    $this->assertTrue($selection->runs('hooks'));
    $this->assertFalse($selection->runs('structure'));
    $this->assertFalse($selection->runs('security'));
    $this->assertFalse($selection->runs('transcript'));
    $this->assertFalse($selection->coverageGateRuns());
  }

  public function testCheckNarrowsToItsOwningGroupAndDisablesGate(): void {
    $selection = RunSelection::create([], NULL, 'check.custom');

    $this->assertTrue($selection->runs('transcript'));
    $this->assertFalse($selection->runs('structure'));
    $this->assertFalse($selection->coverageGateRuns());
  }

  public function testMatchesIsExactWhenCheckSet(): void {
    $selection = RunSelection::create([], NULL, 'structure.frontmatter');

    $this->assertTrue($selection->matches('structure.frontmatter'));
    $this->assertFalse($selection->matches('structure.files-exist'));

    $open = RunSelection::create([], NULL, NULL);
    $this->assertTrue($open->matches('anything.at-all'));
  }

  public function testFilterWithoutGlobsReturnsSameConfig(): void {
    $config = $this->config([$this->skill('skills/alpha')], ['skills/orphan']);

    $this->assertSame($config, RunSelection::create([], NULL, NULL)->filter($config));
  }

  public function testFilterNarrowsSkillsAndUncoveredDirsByGlob(): void {
    $config = $this->config(
      [$this->skill('skills/alpha'), $this->skill('skills/beta')],
      ['skills/alpha-legacy', 'skills/gamma'],
    );

    $filtered = RunSelection::create(['alpha*'], NULL, NULL)->filter($config);

    $this->assertCount(1, $filtered->skills);
    $this->assertSame('alpha', $filtered->skills[0]->effective->skill);
    $this->assertSame(['skills/alpha-legacy'], $filtered->skillsWithoutEval);
    $this->assertSame($config->repo, $filtered->repo);
  }

  public function testFilterSupportsMultipleGlobs(): void {
    $config = $this->config(
      [$this->skill('skills/alpha'), $this->skill('skills/beta'), $this->skill('skills/gamma')],
      [],
    );

    $filtered = RunSelection::create(['alpha', 'gamma'], NULL, NULL)->filter($config);

    $this->assertSame(['alpha', 'gamma'], array_map(static fn(LoadedSkill $skill): string => $skill->effective->skill, $filtered->skills));
  }

  public function testFilterMatchingNothingYieldsEmptySelection(): void {
    $config = $this->config([$this->skill('skills/alpha')], ['skills/gamma']);

    $filtered = RunSelection::create(['nope'], NULL, NULL)->filter($config);

    $this->assertSame([], $filtered->skills);
    $this->assertSame([], $filtered->skillsWithoutEval);
  }

  /**
   * Builds a loaded skill for a directory.
   *
   * @param string $dir
   *   The skill directory, relative to the root.
   *
   * @return \AlexSkrypnyk\SkillTest\Config\LoadedSkill
   *   The loaded skill.
   */
  protected function skill(string $dir): LoadedSkill {
    $effective = EffectiveConfig::resolve(RepoConfig::fromArray([]), [], [], basename($dir), $dir);

    return new LoadedSkill($dir . '/eval.yaml', [], $effective);
  }

  /**
   * Builds a loaded configuration.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill[] $skills
   *   The loaded skills.
   * @param string[] $without_eval
   *   The discovered directories lacking an `eval.yaml`.
   *
   * @return \AlexSkrypnyk\SkillTest\Config\LoadedConfig
   *   The loaded configuration.
   */
  protected function config(array $skills, array $without_eval): LoadedConfig {
    return new LoadedConfig(RepoConfig::fromArray([]), [], '', $skills, $without_eval);
  }

}
