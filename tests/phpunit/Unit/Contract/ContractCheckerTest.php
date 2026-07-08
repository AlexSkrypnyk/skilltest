<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Contract;

use AlexSkrypnyk\SkillTest\Config\EffectiveConfig;
use AlexSkrypnyk\SkillTest\Config\RepoConfig;
use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Contract\ContractChecker;
use AlexSkrypnyk\SkillTest\Contract\Transcript;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class ContractCheckerTest.
 *
 * Unit test for the contract checker.
 */
#[CoversClass(ContractChecker::class)]
final class ContractCheckerTest extends TestCase {

  public function testEmptyContractYieldsNoResults(): void {
    $results = (new ContractChecker())->check($this->transcript(['git status']), []);

    $this->assertSame([], $results);
  }

  public function testRequiredToolPresentPasses(): void {
    $contract = ['tools' => ['required' => ['Bash']]];

    $result = $this->only((new ContractChecker())->check($this->transcript(['ls']), $contract));

    $this->assertSame('contract.tools.required', $result->id);
    $this->assertSame('Bash', $result->label);
    $this->assertTrue($result->pass);
    $this->assertSame('Bash', $result->evidence);
  }

  public function testRequiredToolAbsentFailsWithoutEvidence(): void {
    $contract = ['tools' => ['required' => ['Skill']]];

    $result = $this->only((new ContractChecker())->check($this->transcript(['ls']), $contract));

    $this->assertFalse($result->pass);
    $this->assertSame('', $result->evidence);
    $this->assertStringContainsString("required tool 'Skill' was never used.", $result->message);
  }

  public function testForbiddenToolPresentFailsWithEvidence(): void {
    $contract = ['tools' => ['forbidden' => ['Bash']]];

    $result = $this->only((new ContractChecker())->check($this->transcript(['ls']), $contract));

    $this->assertSame('contract.tools.forbidden', $result->id);
    $this->assertFalse($result->pass);
    $this->assertSame('Bash', $result->evidence);
  }

  public function testForbiddenToolAbsentPasses(): void {
    $contract = ['tools' => ['forbidden' => ['Skill']]];

    $result = $this->only((new ContractChecker())->check($this->transcript(['ls']), $contract));

    $this->assertTrue($result->pass);
    $this->assertSame('', $result->evidence);
  }

  public function testRequiredCommandMatchesAfterAliasNormalisation(): void {
    $contract = ['commands' => ['required' => ['drives workflow' => '\bharness\s+workflow\s+start\b']]];
    $checker = new ContractChecker(['harness' => '(?:php\s+)?(?:\S*/)?bin/harness\b']);

    $result = $this->only($checker->check($this->transcript(['php bin/harness workflow start']), $contract));

    $this->assertSame('contract.commands.required', $result->id);
    $this->assertSame('drives workflow', $result->label);
    $this->assertTrue($result->pass);
    $this->assertSame('harness workflow start', $result->evidence);
  }

  public function testRequiredCommandNoMatchFailsWithPatternInMessage(): void {
    $contract = ['commands' => ['required' => ['drives workflow' => '\bharness\s+workflow\s+start\b']]];

    $result = $this->only((new ContractChecker())->check($this->transcript(['ls']), $contract));

    $this->assertFalse($result->pass);
    $this->assertSame('', $result->evidence);
    $this->assertStringContainsString('drives workflow', $result->message);
    $this->assertStringContainsString('\bharness\s+workflow\s+start\b', $result->message);
  }

  public function testForbiddenCommandMatchFailsWithOffendingEvidence(): void {
    $contract = ['commands' => ['forbidden' => ['raw git' => 'pack:git-mutations']]];

    $result = $this->only((new ContractChecker())->check($this->transcript(['ls', 'git push origin main']), $contract));

    $this->assertSame('contract.commands.forbidden', $result->id);
    $this->assertFalse($result->pass);
    $this->assertSame('git push origin main', $result->evidence);
    $this->assertStringContainsString('raw git', $result->message);
  }

  public function testForbiddenCommandNoMatchPasses(): void {
    $contract = ['commands' => ['forbidden' => ['raw git' => 'pack:git-mutations']]];

    $result = $this->only((new ContractChecker())->check($this->transcript(['git status']), $contract));

    $this->assertTrue($result->pass);
    $this->assertSame('', $result->evidence);
  }

  public function testRequiredSkillPresentPasses(): void {
    $contract = ['skills' => ['required' => ['harness:build-generic']]];

    $result = $this->only((new ContractChecker())->check($this->transcript([], ['harness:build-generic']), $contract));

    $this->assertSame('contract.skills.required', $result->id);
    $this->assertTrue($result->pass);
    $this->assertSame('harness:build-generic', $result->evidence);
  }

  public function testForbiddenSkillPresentFails(): void {
    $contract = ['skills' => ['forbidden' => ['lint']]];

    $result = $this->only((new ContractChecker())->check($this->transcript([], ['lint']), $contract));

    $this->assertSame('contract.skills.forbidden', $result->id);
    $this->assertFalse($result->pass);
    $this->assertSame('lint', $result->evidence);
  }

  public function testResultsAreOrderedToolsThenCommandsThenSkills(): void {
    $contract = [
      'tools' => ['required' => ['Bash']],
      'commands' => ['forbidden' => ['raw git' => 'pack:git-mutations']],
      'skills' => ['required' => ['lint']],
    ];

    $results = (new ContractChecker())->check($this->transcript(['git status'], ['lint']), $contract);

    $ids = array_map(static fn(CheckResult $result): string => $result->id, $results);
    $this->assertSame([
      'contract.tools.required',
      'contract.commands.forbidden',
      'contract.skills.required',
    ], $ids);
  }

  public function testRepoGuardOmittedFromEvalIsStillEnforced(): void {
    // The skill's own eval.yaml declares no forbidden commands; the repo guard
    // is folded in by EffectiveConfig and the checker must still enforce it.
    $repo = RepoConfig::fromArray(['guards' => ['broker bypass' => 'pack:gh-mutations']]);
    $effective = EffectiveConfig::resolve($repo, [], [], 'foo', 'skills/foo');

    $results = (new ContractChecker($repo->aliases))->check($this->transcript(['gh pr create -t x']), $effective->contract);

    $guard = $this->find($results, 'contract.commands.forbidden', 'broker bypass');
    $this->assertFalse($guard->pass);
    $this->assertSame('gh pr create -t x', $guard->evidence);
  }

  /**
   * Builds a transcript from Bash commands and Skill invocations.
   *
   * @param string[] $bash
   *   The Bash command strings, in order.
   * @param string[] $skills
   *   The Skill invocation names, in order.
   *
   * @return \AlexSkrypnyk\SkillTest\Contract\Transcript
   *   The synthesised transcript.
   */
  protected function transcript(array $bash = [], array $skills = []): Transcript {
    $lines = [];

    foreach ($bash as $command) {
      $lines[] = (string) json_encode(['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => $command]]);
    }

    foreach ($skills as $skill) {
      $lines[] = (string) json_encode(['type' => 'tool_use', 'name' => 'Skill', 'input' => ['skill' => $skill]]);
    }

    return new Transcript(implode("\n", $lines));
  }

  /**
   * Asserts a single result and returns it.
   *
   * @param \AlexSkrypnyk\SkillTest\Contract\CheckResult[] $results
   *   The results.
   *
   * @return \AlexSkrypnyk\SkillTest\Contract\CheckResult
   *   The sole result.
   */
  protected function only(array $results): CheckResult {
    $this->assertCount(1, $results);

    return $results[0];
  }

  /**
   * Finds the result with the given id and label.
   *
   * @param \AlexSkrypnyk\SkillTest\Contract\CheckResult[] $results
   *   The results to search.
   * @param string $id
   *   The check id.
   * @param string $label
   *   The check label.
   *
   * @return \AlexSkrypnyk\SkillTest\Contract\CheckResult
   *   The matching result.
   */
  protected function find(array $results, string $id, string $label): CheckResult {
    foreach ($results as $result) {
      if ($result->id === $id && $result->label === $label) {
        return $result;
      }
    }

    $this->fail(sprintf('No result with id "%s" and label "%s".', $id, $label));
  }

}
