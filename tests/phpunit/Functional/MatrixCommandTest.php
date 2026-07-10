<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\PhpunitHelpers\Traits\ApplicationTrait;
use AlexSkrypnyk\SkillTest\Command\MatrixCommand;
use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use AlexSkrypnyk\SkillTest\Live\AgentPreflight;
use AlexSkrypnyk\SkillTest\Live\LlmSuite;
use AlexSkrypnyk\SkillTest\Tests\Traits\ArrayPathTrait;
use AlexSkrypnyk\SkillTest\Tests\Traits\SchemaValidationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class MatrixCommandTest.
 *
 * Functional test for the matrix command driven against a model-branching stub
 * agent that passes on sonnet and opus and fails on haiku, so the ladder, the
 * minimal-model verdict, failure modes, stop-at-pass, and the estimate are all
 * exercised without spending a token.
 */
#[CoversClass(MatrixCommand::class)]
#[Group('command')]
final class MatrixCommandTest extends TestCase {

  use ApplicationTrait;
  use ArrayPathTrait;
  use SchemaValidationTrait;

  /**
   * A credential environment variable, present so the host preflight passes.
   */
  protected const string SECRET_ENV = 'ANTHROPIC_API_KEY';

  /**
   * The stream a passing trial emits: on-contract, no forbidden command.
   */
  protected const string PASS_STREAM = '{"type":"tool_use","name":"Bash","input":{"command":"harness build"}}' . "\n" . '{"type":"result","num_turns":4,"total_cost_usd":0.02,"usage":{"input_tokens":200,"output_tokens":90}}' . "\n";

  /**
   * The stream a failing trial emits: a forbidden push, no required build.
   */
  protected const string FAIL_STREAM = '{"type":"tool_use","name":"Bash","input":{"command":"git push origin main"}}' . "\n" . '{"type":"result","num_turns":2,"total_cost_usd":0.01,"usage":{"input_tokens":80,"output_tokens":30}}' . "\n";

  /**
   * The temporary repository root.
   */
  protected string $tempDir = '';

  /**
   * The captured original HOME, restored on teardown.
   */
  protected string|false $home = FALSE;

  /**
   * The captured original PATH, restored on teardown.
   */
  protected string|false $path = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->home = getenv('HOME');
    $this->path = getenv('PATH');

    putenv(ConfigLoader::ENV_CONFIG);
    putenv(AgentPreflight::ENV_AGENT);
    putenv(LlmSuite::ENV_TIMEOUT);
    putenv('CLAUDE_CODE_OAUTH_TOKEN');
    putenv(self::SECRET_ENV . '=sk-fake-credential-value');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv(ConfigLoader::ENV_CONFIG);
    putenv(AgentPreflight::ENV_AGENT);
    putenv(LlmSuite::ENV_TIMEOUT);
    putenv(self::SECRET_ENV);
    putenv('CLAUDE_CODE_OAUTH_TOKEN');
    putenv('HOME' . ($this->home === FALSE ? '' : '=' . $this->home));
    putenv('PATH' . ($this->path === FALSE ? '' : '=' . $this->path));

    if ($this->tempDir !== '' && is_dir($this->tempDir)) {
      $this->remove($this->tempDir);
    }

    $this->applicationTearDown();

    parent::tearDown();
  }

  public function testReportsPassRatesPerModelAndTheMinimalModel(): void {
    $root = $this->realRepo();
    $this->useAgent($this->modelAgent($root));

    $output = $this->runCommand(['--dir' => $root], 0);

    $this->assertMatchesRegularExpression('/haiku +3 +\S+ +\S+ +0\.00 +fail/', $output);
    $this->assertMatchesRegularExpression('/sonnet +3 +\S+ +\S+ +1\.00 +pass/', $output);
    $this->assertMatchesRegularExpression('/opus +3 +\S+ +\S+ +1\.00 +pass/', $output);
    $this->assertStringContainsString('minimal model: sonnet (threshold 0.80, 3 trials)', $output);
  }

  public function testInterpretReadsTheWeakestModel(): void {
    $root = $this->realRepo();
    $this->useAgent($this->modelAgent($root));

    $output = $this->runCommand(['--dir' => $root, '--interpret' => TRUE], 0);

    $this->assertStringContainsString("task 'invoked' on haiku in 'alpha'", $output);
    $this->assertStringContainsString("Strengthen the skill's guidance", $output);
  }

  public function testFailureModesNameFailedCheckIdsWithCounts(): void {
    $root = $this->realRepo();
    $this->useAgent($this->modelAgent($root));

    $output = $this->runCommand(['--dir' => $root], 0);

    $this->assertStringContainsString('haiku failure modes:', $output);
    $this->assertStringContainsString('contract.commands.forbidden (3x)', $output);
    $this->assertStringContainsString('contract.commands.required (3x)', $output);
  }

  public function testStopAtPassStopsAtTheFirstPassingModelAndSaysSo(): void {
    $root = $this->realRepo();
    $this->useAgent($this->modelAgent($root));

    $output = $this->runCommand(['--dir' => $root, '--trials' => '1', '--stop-at-pass' => TRUE], 0);

    $this->assertStringContainsString('stop-at-pass: climbed to the first supporting model per skill.', $output);
    $this->assertMatchesRegularExpression('/sonnet +1 +\S+ +\S+ +1\.00 +pass/', $output);
    // The climb stopped at sonnet, so opus was never run and never appears.
    $this->assertStringNotContainsString('opus', $output);
  }

  public function testEstimateRunsNothingAndPrintsThePlan(): void {
    $root = $this->realRepo();
    $this->useAgent($this->modelAgent($root));

    $output = $this->runCommand(['--dir' => $root, '--estimate' => TRUE], 0);

    $this->assertStringContainsString('matrix plan (nothing runs with --estimate):', $output);
    // One skill, one task, three ladder models, three trials: 9 trials.
    $this->assertMatchesRegularExpression('/alpha +1 +3 +3 +9/', $output);
    $this->assertStringContainsString('total trials: 9', $output);
    $this->assertStringContainsString('rough cost: ~$0.45', $output);
    $this->assertSame([], glob($root . '/.skilltest/tmp/ws-*') ?: [], 'No trial workspace should be assembled for an estimate.');
  }

  public function testEstimateNeedsNoAgentOrCredentials(): void {
    $root = $this->realRepo();
    // No agent on PATH and no credential: an estimate still answers, because it
    // returns before the host preflight.
    mkdir($root . '/empty-path', 0777, TRUE);
    putenv('PATH=' . $root . '/empty-path');
    putenv(self::SECRET_ENV);
    putenv('HOME=' . $root . '/no-home');

    $output = $this->runCommand(['--dir' => $root, '--estimate' => TRUE], 0);

    $this->assertStringContainsString('total trials: 9', $output);
  }

  public function testEstimateJsonEmitsPlanDocument(): void {
    $root = $this->realRepo();
    $this->useAgent($this->modelAgent($root));

    $decoded = $this->decode($this->runCommand(['--dir' => $root, '--estimate' => TRUE, '--json' => TRUE], 0));

    $this->assertTrue($decoded['estimate']);
    $this->assertSame(9, $decoded['total_trials']);
    $this->assertSame('alpha', $this->path($decoded, 'skills', 0, 'skill'));
    $this->assertSame(3, $this->path($decoded, 'skills', 0, 'models'));
  }

  public function testRepoGridRendersForMultipleSkills(): void {
    $root = $this->realRepo(skills: ['alpha', 'beta']);
    $this->useAgent($this->modelAgent($root));

    $output = $this->runCommand(['--dir' => $root, '--trials' => '1'], 0);

    $this->assertStringContainsString('all skills', $output);
    $this->assertMatchesRegularExpression('/alpha +0\.00 +1\.00 +1\.00 +sonnet/', $output);
    $this->assertMatchesRegularExpression('/beta +0\.00 +1\.00 +1\.00 +sonnet/', $output);
  }

  public function testMarkdownOutputIsValidGfm(): void {
    $root = $this->realRepo(skills: ['alpha', 'beta']);
    $this->useAgent($this->modelAgent($root));

    $output = $this->runCommand(['--dir' => $root, '--trials' => '1', '--format' => 'markdown'], 0);

    $this->assertStringContainsString('### alpha', $output);
    $this->assertStringContainsString('### all skills', $output);
    $this->assertStringContainsString('| model | trials | contract | judge | pass rate | verdict |', $output);
    $this->assertStringContainsString('| --- | --- | --- | --- | --- | --- |', $output);
    $this->assertStringContainsString('- haiku failure modes:', $output);
    // Every table row is a balanced pipe row: no empty `||` cells slipped in.
    $this->assertStringNotContainsString('||', $output);
  }

  public function testJudgeModelIsPinnedAndRecordedAcrossEveryRow(): void {
    $root = $this->realRepo(rubric: TRUE);
    $this->useAgent($this->modelAgent($root));

    $decoded = $this->decode($this->runCommand(['--dir' => $root, '--trials' => '1', '--json' => TRUE], 0));

    $models = $this->pathArray($decoded, 'skills', 0, 'llm', 'tasks', 0, 'models');
    $this->assertCount(3, $models);

    foreach ($models as $model) {
      $this->assertIsArray($model);
      $this->assertSame('claude-haiku-4-5', $this->path($model, 'trials', 0, 'judge_model'), 'The judge model is pinned across every ladder row.');
    }
  }

  public function testJsonEmitsSchemaValidMatrixDocument(): void {
    $root = $this->realRepo();
    $this->useAgent($this->modelAgent($root));

    $decoded = $this->decode($this->runCommand(['--dir' => $root, '--trials' => '2', '--json' => TRUE], 0));

    $this->assertMatchesResultsSchema((string) json_encode($decoded));
    $this->assertSame('matrix', $this->path($decoded, 'run', 'command'));
    $this->assertSame('sonnet', $this->path($decoded, 'skills', 0, 'llm', 'verdict', 'minimal_model'));
    $this->assertCount(2, $this->pathArray($decoded, 'skills', 0, 'llm', 'tasks', 0, 'models', 0, 'trials'));
  }

  public function testOutputDirPersistsResultsAndTranscripts(): void {
    $root = $this->realRepo();
    $this->useAgent($this->modelAgent($root));

    $this->runCommand(['--dir' => $root, '--trials' => '1', '--output-dir' => $root . '/runs'], 0);

    $results = glob($root . '/runs/*/results.json') ?: [];
    $this->assertCount(1, $results);
    $this->assertMatchesResultsSchema((string) file_get_contents($results[0]));
  }

  public function testOutputFilePersistsResults(): void {
    $root = $this->realRepo();
    $this->useAgent($this->modelAgent($root));
    $file = $root . '/matrix.json';

    $this->runCommand(['--dir' => $root, '--trials' => '1', '--output' => $file], 0);

    $this->assertFileExists($file);
    $this->assertMatchesResultsSchema((string) file_get_contents($file));
  }

  public function testUnknownFormatIsConfigError(): void {
    $root = $this->realRepo();
    $this->useAgent($this->modelAgent($root));

    $output = $this->runCommand(['--dir' => $root, '--format' => 'xml'], 2);

    $this->assertStringContainsString('unknown format; expected one of: text, markdown', $output);
  }

  public function testParallelNonIntegerIsConfigError(): void {
    $root = $this->realRepo();
    $this->useAgent($this->modelAgent($root));

    $output = $this->runCommand(['--dir' => $root, '--parallel' => 'abc'], 2);

    $this->assertStringContainsString('--parallel must be an integer', $output);
  }

  public function testParallelBelowOneIsConfigError(): void {
    $root = $this->realRepo();
    $this->useAgent($this->modelAgent($root));

    $output = $this->runCommand(['--dir' => $root, '--parallel' => '0'], 2);

    $this->assertStringContainsString('--parallel must be at least 1', $output);
  }

  public function testDockerEnvironmentIsRejected(): void {
    $root = $this->realRepo();
    $this->useAgent($this->modelAgent($root));

    $output = $this->runCommand(['--dir' => $root, '--env' => 'docker'], 2);

    $this->assertStringContainsString('docker environment is not yet implemented', $output);
  }

  public function testMissingAgentIsConfigError(): void {
    $root = $this->realRepo();
    mkdir($root . '/empty-path', 0777, TRUE);
    putenv('PATH=' . $root . '/empty-path');

    $output = $this->runCommand(['--dir' => $root], 2);

    $this->assertStringContainsString("the 'claude' agent was not found on PATH", $output);
  }

  public function testNoSkillsMatchedIsConfigError(): void {
    $root = $this->realRepo();
    $this->useAgent($this->modelAgent($root));

    $output = $this->runCommand(['--dir' => $root, '--skill' => ['nope-*']], 2);

    $this->assertStringContainsString('no skills matched --skill nope-*', $output);
  }

  public function testValidationWarningGoesToStderr(): void {
    $root = $this->realRepo();
    $this->useAgent($this->modelAgent($root));
    file_put_contents($root . '/skills/alpha/eval.yaml', "mystery: true\n", FILE_APPEND);

    $output = $this->runCommand(['--dir' => $root, '--trials' => '1'], 0);

    $this->assertStringContainsString('WARNING', $output);
    $this->assertStringContainsString('mystery', $output);
  }

  public function testIncoherentEvalIsConfigError(): void {
    $root = $this->realRepo();
    $this->useAgent($this->modelAgent($root));
    file_put_contents($root . '/skills/alpha/eval.yaml', "version: \"1\"\ncontract:\n  tools:\n    required: [Bash]\n    forbidden: [Bash]\nllm:\n  tasks:\n    - name: invoked\n      prompt: go\n");

    $output = $this->runCommand(['--dir' => $root], 2);

    $this->assertStringContainsString("tool 'Bash' is in both required and forbidden", $output);
  }

  public function testNoLlmTasksIsConfigError(): void {
    $root = $this->realRepo(tasks: FALSE);
    $this->useAgent($this->modelAgent($root));

    $output = $this->runCommand(['--dir' => $root], 2);

    $this->assertStringContainsString('no llm tasks are declared', $output);
  }

  public function testMalformedEvalIsConfigError(): void {
    $root = $this->realRepo();
    $this->useAgent($this->modelAgent($root));
    file_put_contents($root . '/skills/alpha/eval.yaml', "contract: [bad\n");

    $output = $this->runCommand(['--dir' => $root, '--json' => TRUE], 2);

    $decoded = $this->decode($output);
    $this->assertFalse($decoded['ok']);
  }

  /**
   * Builds a real fixture repository with a ladder and one or more skills.
   *
   * @param string[] $skills
   *   The skill names to create.
   * @param bool $rubric
   *   Whether each skill declares a judge rubric.
   * @param bool $tasks
   *   Whether each skill declares an llm task.
   *
   * @return string
   *   The repository root.
   */
  protected function realRepo(array $skills = ['alpha'], bool $rubric = FALSE, bool $tasks = TRUE): string {
    $this->tempDir = dirname(__DIR__, 3) . '/.artifacts/tmp/matrixcmd-' . getmypid() . '-' . uniqid();
    mkdir($this->tempDir, 0777, TRUE);

    file_put_contents($this->tempDir . '/skilltest.yml', "version: \"1\"\nmodels:\n  aliases:\n    haiku: claude-haiku-4-5\n    sonnet: claude-sonnet-5\n    opus: claude-opus-4-8\n  ladder: [haiku, sonnet, opus]\n  default: sonnet\n  judge: haiku\n");

    $eval = "version: \"1\"\ncontract:\n  tools:\n    allowed: [Bash]\n    required: [Bash]\n  commands:\n    required:\n      builds: '\\bharness\\s+build\\b'\n    forbidden:\n      no push: '\\bgit\\s+push\\b'\n";
    if ($tasks) {
      $eval .= "llm:\n";
      if ($rubric) {
        $eval .= "  judge:\n    rubric:\n      - names the change\n";
      }
      $eval .= "  tasks:\n    - name: invoked\n      prompt: Build the thing\n";
    }

    foreach ($skills as $skill) {
      mkdir($this->tempDir . '/skills/' . $skill, 0777, TRUE);
      file_put_contents($this->tempDir . '/skills/' . $skill . '/SKILL.md', "---\nname: " . $skill . "\ndescription: A clean well-formed skill for tests.\n---\n# Body\n");
      file_put_contents($this->tempDir . '/skills/' . $skill . '/eval.yaml', $eval);
    }

    return $this->tempDir;
  }

  /**
   * Writes a stub agent that passes unless its --model contains "haiku".
   *
   * @param string $root
   *   The repository root the stub lives under.
   *
   * @return string
   *   The `php <path>` command prefix.
   */
  protected function modelAgent(string $root): string {
    file_put_contents($root . '/pass-stream.txt', self::PASS_STREAM);
    file_put_contents($root . '/fail-stream.txt', self::FAIL_STREAM);

    $path = $root . '/model-agent.php';
    $body = "<?php\n"
      . '$model = "";' . "\n"
      . '$args = $_SERVER["argv"];' . "\n"
      . 'foreach ($args as $i => $a) { if ($a === "--model") { $model = $args[$i + 1] ?? ""; } }' . "\n"
      . '$file = (strpos($model, "haiku") === false) ? ' . var_export($root . '/pass-stream.txt', TRUE) . ' : ' . var_export($root . '/fail-stream.txt', TRUE) . ";\n"
      . 'readfile($file);' . "\n"
      . "exit(0);\n";
    file_put_contents($path, $body);

    return 'php ' . escapeshellarg($path);
  }

  /**
   * Points the agent seam at a stub command.
   *
   * @param string $command
   *   The stub command prefix.
   */
  protected function useAgent(string $command): void {
    putenv(AgentPreflight::ENV_AGENT . '=' . $command);
  }

  /**
   * Runs the matrix command and asserts the exit code.
   *
   * @param array<string, string|bool|string[]> $input
   *   The command input.
   * @param int $expected_exit
   *   The expected exit code.
   *
   * @return string
   *   The combined command output.
   */
  protected function runCommand(array $input, int $expected_exit): string {
    $this->applicationInitFromCommand(MatrixCommand::class);
    $this->applicationGetTester()->run($input, ['capture_stderr_separately' => TRUE]);

    $this->assertSame($expected_exit, $this->applicationGetTester()->getStatusCode());

    return $this->applicationGetTester()->getDisplay() . $this->applicationGetTester()->getErrorOutput();
  }

  /**
   * Decodes the JSON standard output of a command run.
   *
   * @param string $output
   *   The combined output; only stdout carries the JSON.
   *
   * @return array<mixed>
   *   The decoded payload.
   */
  protected function decode(string $output): array {
    $stdout = $this->applicationGetTester()->getDisplay();
    $decoded = json_decode(trim($stdout === '' ? $output : $stdout), TRUE, 512, JSON_THROW_ON_ERROR);

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
      // @codeCoverageIgnoreStart
      return;
      // @codeCoverageIgnoreEnd
    }

    foreach (scandir($dir) ?: [] as $item) {
      if ($item === '.') {
        continue;
      }
      if ($item === '..') {
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
