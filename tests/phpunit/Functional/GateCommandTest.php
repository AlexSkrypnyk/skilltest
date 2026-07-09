<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\PhpunitHelpers\Traits\ApplicationTrait;
use AlexSkrypnyk\SkillTest\Command\GateCommand;
use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use AlexSkrypnyk\SkillTest\Tests\Traits\ResultsDocumentTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class GateCommandTest.
 *
 * Functional test for the gate command: aggregate regression, golden tasks read
 * from eval.yaml, the minimal-model climb, task-set drift policy, every output
 * format, and every configuration error path.
 */
#[CoversClass(GateCommand::class)]
#[Group('command')]
final class GateCommandTest extends TestCase {

  use ApplicationTrait;
  use ResultsDocumentTrait;

  /**
   * The temporary working directory.
   */
  protected string $tempDir = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    putenv(ConfigLoader::ENV_CONFIG);
    $this->tempDir = dirname(__DIR__, 3) . '/.artifacts/tmp/gatecmd-' . getmypid() . '-' . uniqid();
    mkdir($this->tempDir, 0777, TRUE);
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

  public function testEqualRunsPass(): void {
    $file = $this->writeDoc('run.json', [$this->ratedSkill(2, 0)]);

    $output = $this->runGate(['--current' => $file, '--baseline' => $file], 0);

    $this->assertStringContainsString('gate: PASS', $output);
    $this->assertStringContainsString('no regressions.', $output);
  }

  public function testRegressionBeyondThresholdFails(): void {
    $baseline = $this->writeDoc('base.json', [$this->ratedSkill(2, 0)]);
    $current = $this->writeDoc('curr.json', [$this->ratedSkill(1, 1)]);

    $output = $this->runGate(['--current' => $current, '--baseline' => $baseline], 1);

    $this->assertStringContainsString('gate: FAIL', $output);
    $this->assertStringContainsString('aggregate pass rate dropped', $output);
  }

  public function testRegressionWithinTolerancePasses(): void {
    $baseline = $this->writeDoc('base.json', [$this->ratedSkill(2, 0)]);
    $current = $this->writeDoc('curr.json', [$this->ratedSkill(1, 1)]);

    $output = $this->runGate(['--current' => $current, '--baseline' => $baseline, '--max-regression' => '50'], 0);

    $this->assertStringContainsString('gate: PASS', $output);
  }

  public function testGoldenFailureOutranksPassingAggregate(): void {
    // Baseline equals current, so nothing regresses; the golden task fails in
    // both, and the gate must still fail on the golden check alone.
    $doc = [$this->skill('alpha', llm: $this->llm([
      $this->multiModelTask('critical', [$this->modelEntry('haiku', [$this->trial(1, TRUE), $this->trial(2, TRUE), $this->trial(3, FALSE)])]),
    ], ['minimal_model' => NULL, 'threshold' => 0.8, 'trials' => 3]))];
    $file = $this->writeDoc('run.json', $doc);
    $this->goldenRepo();

    $output = $this->runGate(['--current' => $file, '--baseline' => $file, '--dir' => $this->tempDir], 1);

    $this->assertStringContainsString('gate: FAIL', $output);
    $this->assertStringContainsString("golden task 'alpha / critical' did not pass", $output);
  }

  public function testGoldenTaskPassingIsClean(): void {
    $doc = [$this->skill('alpha', llm: $this->llm([
      $this->multiModelTask('critical', [$this->modelEntry('haiku', [$this->trial(1, TRUE), $this->trial(2, TRUE), $this->trial(3, TRUE)])]),
    ], ['minimal_model' => 'haiku', 'threshold' => 0.8, 'trials' => 3]))];
    $file = $this->writeDoc('run.json', $doc);
    $this->goldenRepo();

    $output = $this->runGate(['--current' => $file, '--baseline' => $file, '--dir' => $this->tempDir], 0);

    $this->assertStringContainsString('gate: PASS', $output);
  }

  public function testMinimalModelClimbFails(): void {
    $baseline = $this->writeDoc('base.json', [$this->climbSkill('haiku')]);
    $current = $this->writeDoc('curr.json', [$this->climbSkill('sonnet')]);

    $output = $this->runGate(['--current' => $current, '--baseline' => $baseline, '--max-regression' => '100'], 1);

    $this->assertStringContainsString("minimal model for 'alpha' climbed the ladder haiku -> sonnet", $output);
  }

  public function testNewTaskFailPolicyFails(): void {
    $baseline = $this->writeDoc('base.json', [$this->taskSkill('base')]);
    $current = $this->writeDoc('curr.json', [$this->taskSkill('extra')]);

    $output = $this->runGate(['--current' => $current, '--baseline' => $baseline, '--max-regression' => '100', '--on-new-tasks' => 'fail'], 1);

    $this->assertStringContainsString("task 'alpha / extra' is new", $output);
  }

  public function testRemovedTaskWarnPolicyStillPasses(): void {
    $baseline = $this->writeDoc('base.json', [$this->taskSkill('gone')]);
    $current = $this->writeDoc('curr.json', [$this->taskSkill('base')]);

    $output = $this->runGate(['--current' => $current, '--baseline' => $baseline, '--max-regression' => '100', '--on-new-tasks' => 'allow', '--on-removed-tasks' => 'warn'], 0);

    $this->assertStringContainsString('gate: PASS', $output);
    $this->assertStringContainsString("task 'alpha / gone' was removed", $output);
  }

  public function testJsonFormat(): void {
    $baseline = $this->writeDoc('base.json', [$this->ratedSkill(2, 0)]);
    $current = $this->writeDoc('curr.json', [$this->ratedSkill(1, 1)]);

    $output = $this->runGate(['--current' => $current, '--baseline' => $baseline, '--format' => 'json'], 1);

    $decoded = $this->decodeArray(trim($output));
    $this->assertSame('fail', $decoded['gate']);
    $this->assertStringContainsString('"category": "regression"', $output);
  }

  public function testMarkdownFormat(): void {
    $baseline = $this->writeDoc('base.json', [$this->ratedSkill(2, 0)]);
    $current = $this->writeDoc('curr.json', [$this->ratedSkill(1, 1)]);

    $output = $this->runGate(['--current' => $current, '--baseline' => $baseline, '--format' => 'markdown'], 1);

    $this->assertStringContainsString('### skilltest gate: FAIL', $output);
    $this->assertStringContainsString('| Severity | Category | Finding |', $output);
  }

  public function testGithubActionsFormat(): void {
    $baseline = $this->writeDoc('base.json', [$this->ratedSkill(2, 0)]);
    $current = $this->writeDoc('curr.json', [$this->ratedSkill(1, 1)]);

    $output = $this->runGate(['--current' => $current, '--baseline' => $baseline, '--format' => 'github-actions'], 1);

    $this->assertStringContainsString('::error title=skilltest gate::', $output);
    $this->assertStringContainsString('::notice title=skilltest gate::gate failed', $output);
  }

  public function testGoldenBestEffortWhenNoConfig(): void {
    // No skilltest.yml in --dir: the golden set is empty and the gate still
    // compares the two files.
    $file = $this->writeDoc('run.json', [$this->ratedSkill(2, 0)]);

    $output = $this->runGate(['--current' => $file, '--baseline' => $file, '--dir' => $this->tempDir . '/empty'], 0);

    $this->assertStringContainsString('gate: PASS', $output);
  }

  public function testGoldenConfigLoadFailureWarnsAndStillCompares(): void {
    // A malformed config in --dir must not abort the gate: the golden set is
    // empty and the two files are still compared.
    mkdir($this->tempDir . '/broken', 0777, TRUE);
    file_put_contents($this->tempDir . '/broken/skilltest.yml', "version: [bad\n");
    $file = $this->writeDoc('run.json', [$this->ratedSkill(2, 0)]);

    $output = $this->runGate(['--current' => $file, '--baseline' => $file, '--dir' => $this->tempDir . '/broken'], 0);

    $this->assertStringContainsString('WARNING could not load config for golden tasks', $output);
    $this->assertStringContainsString('gate: PASS', $output);
  }

  public function testMissingCurrentIsConfigError(): void {
    $file = $this->writeDoc('run.json', [$this->ratedSkill(1, 0)]);

    $output = $this->runGate(['--baseline' => $file], 2);

    $this->assertStringContainsString('the --current option is required', $output);
  }

  public function testMissingBaselineIsConfigError(): void {
    $file = $this->writeDoc('run.json', [$this->ratedSkill(1, 0)]);

    $output = $this->runGate(['--current' => $file], 2);

    $this->assertStringContainsString('the --baseline option is required', $output);
  }

  public function testMissingFileIsConfigError(): void {
    $file = $this->writeDoc('run.json', [$this->ratedSkill(1, 0)]);

    $output = $this->runGate(['--current' => $this->tempDir . '/nope.json', '--baseline' => $file], 2);

    $this->assertStringContainsString('results file not found', $output);
  }

  public function testWrongMajorIsConfigError(): void {
    $good = $this->writeDoc('good.json', [$this->ratedSkill(1, 0)]);
    $bad = $this->writeDoc('bad.json', [$this->ratedSkill(1, 0)], '2');

    $output = $this->runGate(['--current' => $bad, '--baseline' => $good], 2);

    $this->assertStringContainsString("schema version '2'", $output);
  }

  public function testUnknownFormatIsConfigError(): void {
    $file = $this->writeDoc('run.json', [$this->ratedSkill(1, 0)]);

    $output = $this->runGate(['--current' => $file, '--baseline' => $file, '--format' => 'xml'], 2);

    $this->assertStringContainsString('unknown format', $output);
  }

  public function testInvalidPolicyIsConfigError(): void {
    $file = $this->writeDoc('run.json', [$this->ratedSkill(1, 0)]);

    $output = $this->runGate(['--current' => $file, '--baseline' => $file, '--on-new-tasks' => 'skip'], 2);

    $this->assertStringContainsString('--on-new-tasks must be one of', $output);
  }

  public function testInvalidMaxRegressionIsConfigError(): void {
    $file = $this->writeDoc('run.json', [$this->ratedSkill(1, 0)]);

    $output = $this->runGate(['--current' => $file, '--baseline' => $file, '--max-regression' => 'lots'], 2);

    $this->assertStringContainsString('--max-regression must be a non-negative number', $output);
  }

  /**
   * A skill carrying a number of passing and failing structure checks.
   *
   * @param int $pass
   *   The number of passing checks.
   * @param int $fail
   *   The number of failing checks.
   *
   * @return array<string, mixed>
   *   The skill entry.
   */
  protected function ratedSkill(int $pass, int $fail): array {
    $structure = [];

    for ($i = 0; $i < $pass; $i++) {
      $structure[] = $this->check('structure.p' . $i, TRUE);
    }

    for ($i = 0; $i < $fail; $i++) {
      $structure[] = $this->check('structure.f' . $i, FALSE);
    }

    return $this->skill('alpha', structure: $structure);
  }

  /**
   * A skill over the haiku/sonnet ladder with a given minimal-model verdict.
   *
   * @param string $minimal
   *   The minimal-model alias in the verdict.
   *
   * @return array<string, mixed>
   *   The skill entry.
   */
  protected function climbSkill(string $minimal): array {
    return $this->skill('alpha', llm: $this->llm([
      $this->multiModelTask('invoked', [$this->modelEntry('haiku', [$this->trial(1, TRUE)]), $this->modelEntry('sonnet', [$this->trial(1, TRUE)])]),
    ], ['minimal_model' => $minimal, 'threshold' => 0.8, 'trials' => 1]));
  }

  /**
   * A skill carrying one passing single-model llm task.
   *
   * @param string $task
   *   The task name.
   *
   * @return array<string, mixed>
   *   The skill entry.
   */
  protected function taskSkill(string $task): array {
    return $this->skill('alpha', llm: $this->llm([
      $this->multiModelTask($task, [$this->modelEntry('haiku', [$this->trial(1, TRUE)])]),
    ], ['minimal_model' => 'haiku', 'threshold' => 0.8, 'trials' => 1]));
  }

  /**
   * Writes a full results document to a file under the temp directory.
   *
   * @param string $name
   *   The file name.
   * @param array<int, array<mixed>> $skills
   *   The skill entries.
   * @param string $version
   *   The schema version.
   *
   * @return string
   *   The file path.
   */
  protected function writeDoc(string $name, array $skills, string $version = '1'): string {
    $document = $this->document(skills: $skills);
    $document['version'] = $version;

    $path = $this->tempDir . '/' . $name;
    file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));

    return $path;
  }

  /**
   * Writes a repo whose skill declares a golden task named `critical`.
   */
  protected function goldenRepo(): void {
    mkdir($this->tempDir . '/skills/alpha', 0777, TRUE);
    file_put_contents($this->tempDir . '/skilltest.yml', "version: \"1\"\nmodels:\n  aliases:\n    haiku: claude-haiku-4-5\n  default: haiku\n");
    file_put_contents($this->tempDir . '/skills/alpha/SKILL.md', "---\nname: alpha\ndescription: A clean well-formed skill for tests.\n---\n# Body\n");
    file_put_contents($this->tempDir . '/skills/alpha/eval.yaml', "version: \"1\"\nllm:\n  tasks:\n    - name: ordinary\n      prompt: Do the ordinary thing\n    - name: critical\n      prompt: Do the critical thing\n      golden: true\n");
  }

  /**
   * Runs the gate command and asserts the exit code.
   *
   * @param array<string, string|bool> $input
   *   The command input.
   * @param int $expected_exit
   *   The expected exit code.
   *
   * @return string
   *   The combined command output.
   */
  protected function runGate(array $input, int $expected_exit): string {
    $this->applicationInitFromCommand(GateCommand::class);
    $this->applicationGetTester()->run($input, ['capture_stderr_separately' => TRUE]);

    $this->assertSame($expected_exit, $this->applicationGetTester()->getStatusCode());

    return $this->applicationGetTester()->getDisplay() . $this->applicationGetTester()->getErrorOutput();
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

      if (is_dir($path) && !is_link($path)) {
        $this->remove($path);

        continue;
      }

      unlink($path);
    }

    rmdir($dir);
  }

}
