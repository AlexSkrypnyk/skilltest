<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Run;

use AlexSkrypnyk\SkillTest\Config\EffectiveConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedSkill;
use AlexSkrypnyk\SkillTest\Config\RepoConfig;
use AlexSkrypnyk\SkillTest\Run\RunPlan;
use AlexSkrypnyk\SkillTest\Run\RunSelection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class RunPlanTest.
 *
 * Unit test for the `--list` plan enumeration.
 */
#[CoversClass(RunPlan::class)]
final class RunPlanTest extends TestCase {

  public function testFullPlanNamesEveryGroupAndTheGate(): void {
    $plan = (new RunPlan($this->config(), RunSelection::create([], NULL, NULL)))->describe();

    $this->assertSame(RunSelection::GROUPS, $plan['groups']);
    $this->assertTrue($plan['coverage']);
    $this->assertCount(2, $plan['skills']);

    $alpha = $plan['skills'][0];
    $this->assertSame('alpha', $alpha['skill']);
    $this->assertSame('skills/alpha', $alpha['path']);

    $structure = $alpha['groups']['structure'];
    $this->assertContains('structure.frontmatter', $structure);
    $this->assertNotContains('structure.command-refs-resolve', $structure);
    $this->assertContains('structure.name-matches-dir (suppressed: legacy dir)', $structure);

    $security = $alpha['groups']['security'];
    $this->assertContains('security.curl-pipe-shell', $security);
    $this->assertContains('security.forbidden-tokens', $security);

    $transcript = $alpha['groups']['transcript'];
    $this->assertSame([
      'contract.tools.required (tool Bash)',
      'contract.tools.forbidden (tool WebFetch)',
      'contract.skills.required (skill helper)',
      'contract.commands.required (builds the thing)',
      'contract.commands.forbidden (no pushes)',
      'check.board',
    ], $transcript);

    $beta = $plan['skills'][1];
    $this->assertSame(['(no transcript fixture declared)'], $beta['groups']['transcript']);
    $this->assertNotContains('security.forbidden-tokens', $beta['groups']['security']);

    $this->assertSame(['hooks.reject-push (2 case(s))'], $plan['hooks']);
  }

  public function testCommandRefsCheckListedWhenResolveConfigured(): void {
    $config = $this->config(['commands' => ['resolve' => ['binary' => 'bin/harness']]]);

    $plan = (new RunPlan($config, RunSelection::create([], 'structure', NULL)))->describe();

    $this->assertContains('structure.command-refs-resolve', $plan['skills'][0]['groups']['structure']);
    $this->assertSame(['structure'], $plan['groups']);
    $this->assertFalse($plan['coverage']);
    $this->assertSame([], $plan['hooks']);
    $this->assertArrayNotHasKey('security', $plan['skills'][0]['groups']);
    $this->assertArrayNotHasKey('transcript', $plan['skills'][0]['groups']);
  }

  public function testStructureCheckFilterNarrowsTheStructureList(): void {
    $plan = (new RunPlan($this->config(), RunSelection::create([], NULL, 'structure.frontmatter')))->describe();

    $this->assertSame(['structure.frontmatter'], $plan['skills'][0]['groups']['structure']);
    $this->assertSame(['structure.frontmatter'], $plan['skills'][1]['groups']['structure']);
  }

  public function testCheckFilterNarrowsEveryEnumeration(): void {
    $plan = (new RunPlan($this->config(), RunSelection::create([], NULL, 'contract.commands.required')))->describe();

    $this->assertSame(['transcript'], $plan['groups']);
    $this->assertSame(['contract.commands.required (builds the thing)'], $plan['skills'][0]['groups']['transcript']);
    $this->assertSame([], $plan['hooks']);
  }

  public function testHookCheckFilterKeepsOnlyMatchingHook(): void {
    $plan = (new RunPlan($this->config(), RunSelection::create([], NULL, 'hooks.reject-push')))->describe();

    $this->assertSame(['hooks'], $plan['groups']);
    $this->assertSame(['hooks.reject-push (2 case(s))'], $plan['hooks']);

    $other = (new RunPlan($this->config(), RunSelection::create([], NULL, 'hooks.other')))->describe();
    $this->assertSame([], $other['hooks']);
  }

  public function testScriptlessHookIsSkipped(): void {
    $config = $this->config(['hooks' => [['cases' => [['tool' => 'Bash', 'expect' => 'block']]]]]);

    $plan = (new RunPlan($config, RunSelection::create([], 'hooks', NULL)))->describe();

    $this->assertSame([], $plan['hooks']);
  }

  public function testEmptyScriptHookIsSkipped(): void {
    $config = $this->config(['hooks' => [['script' => '', 'cases' => [['tool' => 'Bash', 'expect' => 'block']]]]]);

    $plan = (new RunPlan($config, RunSelection::create([], 'hooks', NULL)))->describe();

    $this->assertSame([], $plan['hooks']);
  }

  /**
   * Builds a loaded configuration with two skills.
   *
   * @param array<mixed> $repo_extra
   *   Keys replacing parts of the repo data.
   *
   * @return \AlexSkrypnyk\SkillTest\Config\LoadedConfig
   *   The loaded configuration.
   */
  protected function config(array $repo_extra = []): LoadedConfig {
    $repo_data = array_replace([
      'hooks' => [['script' => 'hooks/reject-push.sh', 'cases' => [['tool' => 'Bash', 'expect' => 'block'], ['tool' => 'Bash', 'expect' => 'allow']]]],
    ], $repo_extra);

    $alpha = [
      'contract' => [
        'tools' => ['required' => ['Bash'], 'forbidden' => ['WebFetch']],
        'commands' => [
          'required' => ['builds the thing' => '\bharness\s+build\b'],
          'forbidden' => ['no pushes' => 'pack:git-mutations'],
        ],
        'skills' => ['required' => ['helper']],
      ],
      'security' => ['forbidden-tokens' => ['internal.example.com']],
      'structure' => ['suppress' => ['structure.name-matches-dir' => 'legacy dir']],
      'deterministic' => ['transcript' => 'fixtures/t.jsonl'],
      'llm' => ['checks' => [['name' => 'board', 'run' => 'php check.php'], ['run' => 'php nameless.php']]],
    ];

    return new LoadedConfig(
      RepoConfig::fromArray($repo_data),
      $repo_data,
      'skilltest.yml',
      [$this->skill('skills/alpha', $alpha), $this->skill('skills/beta', [])],
      [],
    );
  }

  /**
   * Builds a loaded skill for a directory with the given eval data.
   *
   * @param string $dir
   *   The skill directory, relative to the root.
   * @param array<mixed> $eval
   *   The parsed `eval.yaml`.
   *
   * @return \AlexSkrypnyk\SkillTest\Config\LoadedSkill
   *   The loaded skill.
   */
  protected function skill(string $dir, array $eval): LoadedSkill {
    $effective = EffectiveConfig::resolve(RepoConfig::fromArray([]), $eval, [], basename($dir), $dir);

    return new LoadedSkill($dir . '/eval.yaml', $eval, $effective);
  }

}
