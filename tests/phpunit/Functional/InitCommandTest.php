<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\PhpunitHelpers\Traits\ApplicationTrait;
use AlexSkrypnyk\SkillTest\Ai\PromptRunner;
use AlexSkrypnyk\SkillTest\Command\InitCommand;
use AlexSkrypnyk\SkillTest\Command\ValidateCommand;
use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class InitCommandTest.
 *
 * Functional test for the init command: template scaffolding that passes
 * validate, AI drafting with confidence flags, and the merge-safe apply.
 */
#[CoversClass(InitCommand::class)]
#[Group('command')]
final class InitCommandTest extends TestCase {

  use ApplicationTrait;

  /**
   * A real repository root the scaffold reads and writes under.
   */
  protected string $workdir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    putenv(ConfigLoader::ENV_CONFIG);

    $this->workdir = dirname(__DIR__, 3) . '/.artifacts/tmp/init-' . getmypid() . '-' . uniqid();
    mkdir($this->workdir, 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv(ConfigLoader::ENV_CONFIG);

    $this->remove($this->workdir);
    $this->applicationTearDown();

    parent::tearDown();
  }

  public function testTemplateWritesValidatingEval(): void {
    $dir = $this->skillDir('demo', "---\nname: demo\ndescription: A demo.\nallowed-tools: Bash, Skill\n---\ndo the demo");

    $output = $this->runInit(['path' => $dir], 0);

    $this->assertStringContainsString('Wrote', $output);
    $this->assertFileExists($dir . '/eval.yaml');

    $eval = (string) file_get_contents($dir . '/eval.yaml');
    $this->assertStringContainsString('skill: demo', $eval);
    $this->assertStringContainsString('# deterministic:', $eval);
    $this->assertStringContainsString('# TODO', $eval);

    $this->assertValidates($this->workdir);
  }

  public function testAiIncorporatesDraftWithConfidenceComments(): void {
    $dir = $this->skillDir('demo', "---\nname: demo\nallowed-tools: Bash\n---\ndo the demo end to end");
    $runner = $this->stubAgent((string) json_encode([
      'tasks' => [
        ['name' => 'invoked', 'prompt' => '/demo', 'confidence' => 'high'],
        ['name' => 'discovery', 'prompt' => 'do the whole thing', 'confidence' => 'low'],
      ],
      'commands' => [['label' => 'runs demo', 'pattern' => '\\bdemo\\b', 'confidence' => 'high']],
      'rubric' => [
        ['text' => 'Produces the artefact', 'confidence' => 'high'],
        ['text' => 'Cleans up after itself', 'confidence' => 'low'],
      ],
    ]));

    $output = $this->runInit(['path' => $dir, '--ai' => TRUE], 0, $runner);

    $this->assertStringContainsString('Wrote', $output);

    $eval = (string) file_get_contents($dir . '/eval.yaml');
    $this->assertStringContainsString('name: discovery  # review: low confidence', $eval);
    $this->assertStringContainsString("- 'Cleans up after itself'  # review: low confidence", $eval);
    $this->assertStringContainsString('runs demo', $eval);
    $this->assertMatchesRegularExpression('/^llm:$/m', $eval);

    $this->assertValidates($this->workdir);
  }

  public function testAiFallsBackToTemplateWhenAgentUnavailable(): void {
    $dir = $this->skillDir('demo', "---\nname: demo\n---\nbody");
    $runner = new PromptRunner(fn(string $command, string $cwd): array => [1, '']);

    $output = $this->runInit(['path' => $dir, '--ai' => TRUE], 0, $runner);

    $this->assertStringContainsString('AI drafting unavailable', $output);

    $eval = (string) file_get_contents($dir . '/eval.yaml');
    $this->assertStringContainsString('# llm:', $eval);

    $this->assertValidates($this->workdir);
  }

  public function testExistingFileIsPreservedAndPreviewed(): void {
    $dir = $this->skillDir('demo', "---\nname: demo\n---\nbody");
    file_put_contents($dir . '/eval.yaml', "old: content\n");

    $output = $this->runInit(['path' => $dir], 1);

    $this->assertStringContainsString('already exists; not overwritten', $output);
    $this->assertStringContainsString('Diff (- existing, + proposed):', $output);
    $this->assertStringContainsString('-old: content', $output);
    $this->assertStringContainsString('+skill: demo', $output);
    $this->assertSame("old: content\n", (string) file_get_contents($dir . '/eval.yaml'));
  }

  public function testForceOverwritesExistingFile(): void {
    $dir = $this->skillDir('demo', "---\nname: demo\n---\nbody");
    file_put_contents($dir . '/eval.yaml', "old: content\n");

    $output = $this->runInit(['path' => $dir, '--force' => TRUE], 0);

    $this->assertStringContainsString('Wrote', $output);

    $eval = (string) file_get_contents($dir . '/eval.yaml');
    $this->assertStringNotContainsString('old: content', $eval);
    $this->assertStringContainsString('skill: demo', $eval);

    $this->assertValidates($this->workdir);
  }

  public function testMissingSkillFileErrors(): void {
    $dir = $this->workdir . '/no-skill';
    mkdir($dir, 0777, TRUE);

    $output = $this->runInit(['path' => $dir], 2);

    $this->assertStringContainsString('no SKILL.md', $output);
  }

  public function testSkillNameFallsBackToDirectory(): void {
    $dir = $this->skillDir('widget', "the body without frontmatter");

    $this->runInit(['path' => $dir], 0);

    $eval = (string) file_get_contents($dir . '/eval.yaml');
    $this->assertStringContainsString('skill: widget', $eval);

    $this->assertValidates($this->workdir);
  }

  /**
   * Creates a skill directory with the given SKILL.md contents.
   *
   * @param string $name
   *   The skill directory name under `skills/`.
   * @param string $skill_md
   *   The SKILL.md contents.
   *
   * @return string
   *   The absolute skill directory path.
   */
  protected function skillDir(string $name, string $skill_md): string {
    $dir = $this->workdir . '/skills/' . $name;
    mkdir($dir, 0777, TRUE);
    file_put_contents($dir . '/SKILL.md', $skill_md);

    return $dir;
  }

  /**
   * Builds a PromptRunner whose agent returns a fixed reply.
   *
   * @param string $reply
   *   The canned model reply.
   *
   * @return \AlexSkrypnyk\SkillTest\Ai\PromptRunner
   *   The stubbed runner.
   */
  protected function stubAgent(string $reply): PromptRunner {
    return new PromptRunner(fn(string $command, string $cwd): array => [0, $reply]);
  }

  /**
   * Runs the init command and asserts the exit code.
   *
   * @param array<string, string|bool> $input
   *   The command input.
   * @param int $expected_exit
   *   The expected exit code.
   * @param \AlexSkrypnyk\SkillTest\Ai\PromptRunner|null $runner
   *   An optional injected prompt runner.
   *
   * @return string
   *   The command output.
   */
  protected function runInit(array $input, int $expected_exit, ?PromptRunner $runner = NULL): string {
    $this->applicationInitFromCommand(new InitCommand($runner));
    $this->applicationRun($input, [], $expected_exit !== 0);

    $this->assertSame($expected_exit, $this->applicationGetTester()->getStatusCode());

    return $this->applicationGetTester()->getDisplay();
  }

  /**
   * Asserts a repository root passes the validate command cleanly.
   *
   * @param string $root
   *   The repository root.
   */
  protected function assertValidates(string $root): void {
    $this->applicationInitFromCommand(new ValidateCommand());
    $this->applicationRun(['--dir' => $root], [], FALSE);

    $tester = $this->applicationGetTester();
    $this->assertSame(0, $tester->getStatusCode(), 'Scaffolded eval.yaml should pass validate. Output: ' . $tester->getDisplay());
    $this->assertStringContainsString('OK: validated', $tester->getDisplay());
  }

  /**
   * Recursively removes a directory tree.
   *
   * @param string $dir
   *   The directory to remove.
   */
  protected function remove(string $dir): void {
    if (!is_dir($dir)) {
      // @codeCoverageIgnoreStart
      return;
      // @codeCoverageIgnoreEnd
    }

    foreach (scandir($dir) ?: [] as $item) {
      if ($item === '.' || $item === '..') {
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
