<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Validation;

use AlexSkrypnyk\SkillTest\Config\EffectiveConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedSkill;
use AlexSkrypnyk\SkillTest\Config\RepoConfig;
use AlexSkrypnyk\SkillTest\Validation\ConfigValidator;
use AlexSkrypnyk\SkillTest\Validation\ValidationMessage;
use AlexSkrypnyk\SkillTest\Validation\ValidationResult;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class ConfigValidatorTest.
 *
 * Unit test for schema and coherence validation.
 */
#[CoversClass(ConfigValidator::class)]
final class ConfigValidatorTest extends TestCase {

  public function testCleanConfigHasNoFindings(): void {
    $root = $this->root([
      'hooks' => ['h.php' => '<?php'],
      'skills' => ['foo' => ['fixtures' => ['t.jsonl' => '{}']]],
    ]);

    $repo_data = [
      'aliases' => ['harness' => 'bin/harness'],
      'guards' => ['broker' => 'pack:gh-mutations'],
      'hooks' => [['script' => 'hooks/h.php', 'cases' => []]],
      'models' => ['aliases' => ['haiku' => 'claude-haiku'], 'ladder' => ['haiku'], 'default' => 'haiku', 'judge' => 'haiku'],
    ];
    $eval = [
      'skill' => 'foo',
      'contract' => [
        'tools' => ['allowed' => ['Bash'], 'required' => ['Bash'], 'forbidden' => ['Git']],
        'commands' => ['required' => ['a' => '\bfoo\b'], 'forbidden' => ['b' => 'pack:git-mutations']],
        'skills' => ['required' => ['x'], 'forbidden' => ['y']],
      ],
      'security' => ['packs' => ['baseline'], 'forbidden-tokens' => ['S']],
      'deterministic' => ['transcript' => 'fixtures/t.jsonl'],
      'llm' => ['judge' => ['rubric' => ['crit one']]],
    ];

    $result = $this->validate($root, $repo_data, ['foo' => $eval]);

    $this->assertFalse($result->hasErrors());
    $this->assertSame([], $result->warnings());
  }

  public function testUnknownKeysWarnButDoNotFail(): void {
    $root = $this->root();

    $result = $this->validate(
      $root,
      ['bogus' => 1, 'paths' => ['skills' => 'skills', 'nonsense' => 1]],
      ['foo' => ['extra' => 1, 'contract' => ['tools' => ['unknown' => []]]]],
    );

    $this->assertFalse($result->hasErrors());
    $rendered = $this->rendered($result->warnings());
    $this->assertContains($root . '/skilltest.yml: bogus - unknown key (ignored).', $rendered);
    $this->assertContains($root . '/skilltest.yml: paths.nonsense - unknown key (ignored).', $rendered);
    $this->assertContains($root . '/skills/foo/eval.yaml: extra - unknown key (ignored).', $rendered);
    $this->assertContains($root . '/skills/foo/eval.yaml: contract.tools.unknown - unknown key (ignored).', $rendered);
  }

  public function testRepoPatternErrors(): void {
    $root = $this->root();

    $result = $this->validate(
      $root,
      ['aliases' => ['bad' => '('], 'guards' => ['g1' => 'pack:nope', 'g2' => '[bad']],
      [],
    );

    $rendered = $this->rendered($result->errors());
    $this->assertContains($root . '/skilltest.yml: aliases.bad - pattern does not compile: (', $rendered);
    $this->assertContains($root . "/skilltest.yml: guards.g1 - unknown pattern pack 'nope'.", $rendered);
    $this->assertContains($root . '/skilltest.yml: guards.g2 - pattern does not compile: [bad', $rendered);
  }

  public function testHookErrors(): void {
    $root = $this->root(['hooks' => ['there.php' => '<?php']]);

    $result = $this->validate(
      $root,
      ['hooks' => [['cases' => []], ['script' => 'hooks/missing.php'], ['script' => 'hooks/there.php']]],
      [],
    );

    $rendered = $this->rendered($result->errors());
    $this->assertContains($root . '/skilltest.yml: hooks.0.script - hook is missing a script.', $rendered);
    $this->assertContains($root . '/skilltest.yml: hooks.1.script - hook script not found: hooks/missing.php', $rendered);
    $this->assertCount(2, $result->errors());
  }

  public function testModelAliasErrors(): void {
    $root = $this->root();

    $result = $this->validate(
      $root,
      ['models' => ['aliases' => ['haiku' => 'x'], 'ladder' => ['haiku', 'ghost'], 'default' => 'nope', 'judge' => 'haiku']],
      [],
    );

    $rendered = $this->rendered($result->errors());
    $this->assertContains($root . "/skilltest.yml: models.ladder.1 - undefined model alias 'ghost'.", $rendered);
    $this->assertContains($root . "/skilltest.yml: models.default - undefined model alias 'nope'.", $rendered);
    $this->assertCount(2, $result->errors());
  }

  public function testExcludeWithoutReasonFails(): void {
    $root = $this->root();

    $result = $this->validate($root, ['paths' => ['exclude' => [['skill' => 'legacy']]]], []);

    $rendered = $this->rendered($result->errors());
    $this->assertContains($root . "/skilltest.yml: paths.exclude.0 - excluded skill 'legacy' is missing a reason.", $rendered);
  }

  public function testExcludeWithoutSkillFails(): void {
    $root = $this->root();

    $result = $this->validate($root, ['paths' => ['exclude' => [['reason' => 'orphan']]]], []);

    $rendered = $this->rendered($result->errors());
    $this->assertContains($root . '/skilltest.yml: paths.exclude.0 - exclude entry is missing a skill name.', $rendered);
  }

  public function testExcludeWithReasonPasses(): void {
    $root = $this->root();

    $result = $this->validate($root, ['paths' => ['exclude' => [['skill' => 'legacy', 'reason' => 'not yet testable']]]], []);

    $this->assertFalse($result->hasErrors());
  }

  public function testDisjointToolsAndSkills(): void {
    $root = $this->root();

    $result = $this->validate($root, [], [
      'foo' => [
        'contract' => [
          'tools' => ['required' => ['Bash'], 'forbidden' => ['Bash']],
          'skills' => ['required' => ['x'], 'forbidden' => ['x']],
        ],
      ],
    ]);

    $rendered = $this->rendered($result->errors());
    $this->assertContains($root . "/skills/foo/eval.yaml: contract.tools - tool 'Bash' is in both required and forbidden.", $rendered);
    $this->assertContains($root . "/skills/foo/eval.yaml: contract.skills - skill 'x' is in both required and forbidden.", $rendered);
  }

  public function testDisjointCommands(): void {
    $root = $this->root();

    $result = $this->validate($root, [], [
      'foo' => [
        'contract' => [
          'commands' => [
            'required' => ['shared' => 'same', 'other' => '\bok\b'],
            'forbidden' => ['shared' => 'different', 'more' => 'same'],
          ],
        ],
      ],
    ]);

    $rendered = $this->rendered($result->errors());
    $this->assertContains($root . "/skills/foo/eval.yaml: contract.commands - command 'shared' is in both required and forbidden.", $rendered);
    $this->assertContains($root . "/skills/foo/eval.yaml: contract.commands - command pattern 'same' is in both required and forbidden.", $rendered);
  }

  public function testCommandPatternAndPackErrors(): void {
    $root = $this->root();

    $result = $this->validate($root, [], [
      'foo' => [
        'contract' => [
          'commands' => ['forbidden' => ['bad regex' => '(', 'bad pack' => 'pack:nope']],
        ],
      ],
    ]);

    $rendered = $this->rendered($result->errors());
    $this->assertContains($root . "/skills/foo/eval.yaml: contract.commands.forbidden.bad regex - pattern does not compile: (", $rendered);
    $this->assertContains($root . "/skills/foo/eval.yaml: contract.commands.forbidden.bad pack - unknown pattern pack 'nope'.", $rendered);
  }

  public function testUnknownSecurityPack(): void {
    $root = $this->root();

    $result = $this->validate($root, [], ['foo' => ['security' => ['packs' => ['bogus']]]]);

    $rendered = $this->rendered($result->errors());
    $this->assertContains($root . "/skills/foo/eval.yaml: security.packs - unknown security pack 'bogus'.", $rendered);
  }

  public function testMissingFixture(): void {
    $root = $this->root();

    $result = $this->validate($root, [], ['foo' => ['deterministic' => ['transcript' => 'fixtures/t.jsonl']]]);

    $rendered = $this->rendered($result->errors());
    $this->assertContains($root . '/skills/foo/eval.yaml: deterministic.transcript - fixture not found: fixtures/t.jsonl', $rendered);
  }

  public function testPresentFixturePasses(): void {
    $root = $this->root(['skills' => ['foo' => ['fixtures' => ['t.jsonl' => '{}']]]]);

    $result = $this->validate($root, [], ['foo' => ['deterministic' => ['transcript' => 'fixtures/t.jsonl']]]);

    $this->assertFalse($result->hasErrors());
  }

  public function testAbsoluteFixturePath(): void {
    $root = $this->root();

    $result = $this->validate($root, [], ['foo' => ['deterministic' => ['transcript' => '/nonexistent/abs.jsonl']]]);

    $rendered = $this->rendered($result->errors());
    $this->assertContains($root . '/skills/foo/eval.yaml: deterministic.transcript - fixture not found: /nonexistent/abs.jsonl', $rendered);
  }

  public function testRubricRequiredWhenJudgeDeclared(): void {
    $root = $this->root();

    $result = $this->validate($root, [], ['foo' => ['llm' => ['judge' => []]]]);

    $rendered = $this->rendered($result->errors());
    $this->assertContains($root . '/skills/foo/eval.yaml: llm.judge.rubric - rubric must not be empty when a judge is declared.', $rendered);
  }

  public function testRubricPresentPasses(): void {
    $root = $this->root();

    $result = $this->validate($root, [], ['foo' => ['llm' => ['judge' => ['rubric' => ['crit']]]]]);

    $this->assertFalse($result->hasErrors());
  }

  public function testNoJudgeNeedsNoRubric(): void {
    $root = $this->root();

    $result = $this->validate($root, [], ['foo' => ['llm' => ['tasks' => []]]]);

    $this->assertFalse($result->hasErrors());
  }

  public function testValidUnknownPolicyPasses(): void {
    $root = $this->root();

    $result = $this->validate($root, [], ['foo' => ['llm' => ['judge' => ['rubric' => ['crit'], 'unknown' => 'ignore']]]]);

    $this->assertFalse($result->hasErrors());
  }

  public function testInvalidUnknownPolicyFails(): void {
    $root = $this->root();

    $result = $this->validate($root, [], ['foo' => ['llm' => ['judge' => ['rubric' => ['crit'], 'unknown' => 'maybe']]]]);

    $rendered = $this->rendered($result->errors());
    $this->assertContains($root . "/skills/foo/eval.yaml: llm.judge.unknown - must be 'fail' or 'ignore'.", $rendered);
  }

  /**
   * Sets up a virtual filesystem and returns its root URL.
   *
   * @param array<mixed> $structure
   *   The virtual directory structure.
   *
   * @return string
   *   The root URL.
   */
  protected function root(array $structure = []): string {
    return vfsStream::setup('root', NULL, $structure)->url();
  }

  /**
   * Validates a repo config and set of skill evals.
   *
   * @param string $root
   *   The repository root URL.
   * @param array<mixed> $repo_data
   *   The raw repo config.
   * @param array<string, array<mixed>> $evals
   *   The skill evals keyed by skill name.
   *
   * @return \AlexSkrypnyk\SkillTest\Validation\ValidationResult
   *   The validation result.
   */
  protected function validate(string $root, array $repo_data, array $evals): ValidationResult {
    $repo = RepoConfig::fromArray($repo_data);
    $skills = [];

    foreach ($evals as $name => $eval) {
      $file = $root . '/skills/' . $name . '/eval.yaml';
      $skills[] = new LoadedSkill($file, $eval, EffectiveConfig::resolve($repo, $eval, [], $name, 'skills/' . $name));
    }

    $repo_file = $repo_data === [] ? '' : $root . '/skilltest.yml';

    return (new ConfigValidator($root))->validate(new LoadedConfig($repo, $repo_data, $repo_file, $skills));
  }

  /**
   * Renders findings to their string form for assertion.
   *
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationMessage[] $findings
   *   The findings to render.
   *
   * @return string[]
   *   The rendered findings.
   */
  protected function rendered(array $findings): array {
    return array_map(static fn(ValidationMessage $validation_message): string => $validation_message->render(), $findings);
  }

}
