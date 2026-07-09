<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\PhpunitHelpers\Traits\ApplicationTrait;
use AlexSkrypnyk\SkillTest\Command\LlmCommand;
use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use AlexSkrypnyk\SkillTest\Live\AgentPreflight;
use AlexSkrypnyk\SkillTest\Live\DockerPreflight;
use AlexSkrypnyk\SkillTest\Live\LlmSuite;
use AlexSkrypnyk\SkillTest\Tests\Traits\ArrayPathTrait;
use AlexSkrypnyk\SkillTest\Tests\Traits\SchemaValidationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class LlmCommandTest.
 *
 * Functional test for the llm command: the full live stack driven against a
 * stub agent, so trials, gating, concurrency, preflight, persistence, and the
 * output contract are exercised without spending a token.
 */
#[CoversClass(LlmCommand::class)]
#[Group('command')]
final class LlmCommandTest extends TestCase {

  use ApplicationTrait;
  use ArrayPathTrait;
  use SchemaValidationTrait;

  /**
   * A credential environment variable whose value must never be persisted.
   */
  protected const string SECRET_ENV = 'ANTHROPIC_API_KEY';

  /**
   * The stream-json a passing stub agent emits.
   */
  protected const string PASS_STREAM = '{"type":"tool_use","name":"Bash","input":{"command":"harness build"}}' . "\n" . '{"type":"result","num_turns":4,"total_cost_usd":0.02,"usage":{"input_tokens":200,"output_tokens":90}}' . "\n";

  /**
   * The stream-json a contract-violating stub agent emits.
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
    putenv(DockerPreflight::ENV_DOCKER);
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
    putenv(DockerPreflight::ENV_DOCKER);
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

  public function testThreeTrialsPassAndGate(): void {
    $root = $this->realRepo();
    $this->useAgent($this->passStub($root));

    $output = $this->runCommand(['--dir' => $root, '--trials' => '3'], 0);

    $this->assertStringContainsString('alpha invoked haiku PASS (pass_rate 1.00, 3/3 trials)', $output);
    $this->assertStringContainsString('3 trial(s)', $output);
    $this->assertSame([], glob($root . '/.skilltest/tmp/ws-*') ?: [], 'Trial workspaces should be cleaned up.');
  }

  public function testKeepWorkspacePreservesAndPrintsPaths(): void {
    $root = $this->realRepo();
    $this->useAgent($this->passStub($root));

    $output = $this->runCommand(['--dir' => $root, '--trials' => '2', '--keep-workspace' => TRUE], 0);

    $kept = glob($root . '/.skilltest/tmp/ws-*') ?: [];
    $this->assertCount(2, $kept, 'Both trial workspaces should be preserved.');
    $this->assertStringContainsString('workspace preserved: ' . $kept[0], $output);
  }

  public function testCacheReplaysIgnoringNewFailingAgent(): void {
    $root = $this->realRepo();
    $this->useAgent($this->passStub($root));

    $this->runCommand(['--dir' => $root, '--trials' => '1', '--cache' => TRUE], 0);

    // A second cached run with a now-failing agent still passes, proving the
    // agent was not re-executed.
    $this->applicationTearDown();
    $this->useAgent($this->stub($root, 'fail', self::FAIL_STREAM));

    $output = $this->runCommand(['--dir' => $root, '--trials' => '1', '--cache' => TRUE], 0);

    $this->assertStringContainsString('alpha invoked haiku PASS', $output);
    $this->assertStringContainsString('(cached)', $output);
  }

  public function testNoCacheReExecutesLive(): void {
    $root = $this->realRepo();
    $this->useAgent($this->passStub($root));

    $this->runCommand(['--dir' => $root, '--trials' => '1', '--cache' => TRUE], 0);

    $this->applicationTearDown();
    $this->useAgent($this->stub($root, 'fail', self::FAIL_STREAM));

    $output = $this->runCommand(['--dir' => $root, '--trials' => '1', '--no-cache' => TRUE], 1);

    $this->assertStringContainsString('alpha invoked haiku FAIL', $output);
    $this->assertStringNotContainsString('(cached)', $output);
  }

  public function testBeforeTaskHookFailureExitsTwo(): void {
    $root = $this->realRepo();
    $this->useAgent($this->passStub($root));
    file_put_contents($root . '/skilltest.yml', "version: \"1\"\nmodels:\n  aliases:\n    haiku: claude-haiku-4-5\n  default: haiku\nllm:\n  lifecycle:\n    before-task:\n      - command: exit 7\n        error-on-fail: true\n");

    $output = $this->runCommand(['--dir' => $root, '--trials' => '1'], 2);

    $this->assertStringContainsString("lifecycle before-task hook 'exit 7' failed with exit 7", $output);
  }

  public function testAfterRunHookFailureWarns(): void {
    $root = $this->realRepo();
    $this->useAgent($this->passStub($root));
    file_put_contents($root . '/skilltest.yml', "version: \"1\"\nmodels:\n  aliases:\n    haiku: claude-haiku-4-5\n  default: haiku\nllm:\n  lifecycle:\n    after-run:\n      - command: exit 4\n");

    $output = $this->runCommand(['--dir' => $root, '--trials' => '1'], 0);

    $this->assertStringContainsString("WARNING lifecycle after-run hook 'exit 4' failed", $output);
  }

  public function testFailingContractGatesNonZero(): void {
    $root = $this->realRepo();
    $this->useAgent($this->stub($root, 'fail', self::FAIL_STREAM));

    $output = $this->runCommand(['--dir' => $root, '--trials' => '3'], 1);

    $this->assertStringContainsString('alpha invoked haiku FAIL (pass_rate 0.00, 0/3 trials)', $output);
    $this->assertStringContainsString("contract.commands.forbidden FAIL - forbidden behaviour 'no push'", $output);
  }

  public function testParallelResultsMatchSerial(): void {
    $root = $this->realRepo();
    $this->useAgent($this->passStub($root));

    $serial = $this->model($this->decode($this->runCommand(['--dir' => $root, '--trials' => '3', '--parallel' => '1', '--json' => TRUE], 0)));

    $this->applicationTearDown();
    $parallel = $this->model($this->decode($this->runCommand(['--dir' => $root, '--trials' => '3', '--parallel' => '3', '--json' => TRUE], 0)));

    $this->assertSame($serial, $parallel);
  }

  public function testHangingStubTripsPerTrialTimeout(): void {
    $root = $this->realRepo();
    $this->useAgent($this->stub($root, 'hang', NULL, "sleep(5);"));
    putenv(LlmSuite::ENV_TIMEOUT . '=0.5');

    $started = microtime(TRUE);
    $output = $this->runCommand(['--dir' => $root, '--trials' => '1'], 1);

    $this->assertLessThan(4.0, microtime(TRUE) - $started);
    $this->assertStringContainsString('timed out', $output);
  }

  public function testMissingCredentialsIsConfigError(): void {
    $root = $this->realRepo();
    $this->useAgent($this->passStub($root));
    putenv(self::SECRET_ENV);
    putenv('HOME=' . $root . '/no-home');

    $output = $this->runCommand(['--dir' => $root], 2);

    $this->assertStringContainsString('no agent credentials found', $output);
  }

  public function testMissingBinaryIsConfigError(): void {
    $root = $this->realRepo();
    mkdir($root . '/empty-path', 0777, TRUE);
    putenv('PATH=' . $root . '/empty-path');

    $output = $this->runCommand(['--dir' => $root], 2);

    $this->assertStringContainsString("the 'claude' agent was not found on PATH", $output);
  }

  public function testParallelBelowOneIsConfigError(): void {
    $root = $this->realRepo();
    $this->useAgent($this->passStub($root));

    $output = $this->runCommand(['--dir' => $root, '--parallel' => '0'], 2);

    $this->assertStringContainsString('--parallel must be at least 1', $output);
  }

  public function testParallelNonIntegerIsConfigError(): void {
    $root = $this->realRepo();
    $this->useAgent($this->passStub($root));

    $output = $this->runCommand(['--dir' => $root, '--parallel' => 'abc'], 2);

    $this->assertStringContainsString('--parallel must be an integer', $output);
  }

  public function testDockerEnvironmentRunsTrialThroughContainer(): void {
    $root = $this->realRepo();
    $this->useDocker($this->dockerStub($root, 'ok', self::PASS_STREAM));

    $output = $this->runCommand(['--dir' => $root, '--env' => 'docker'], 0);

    $this->assertStringContainsString('alpha invoked haiku PASS', $output);
    $this->assertSame([], glob($root . '/.skilltest/tmp/ws-*') ?: [], 'Trial workspaces should be cleaned up.');
  }

  public function testDockerDaemonUnreachableIsConfigError(): void {
    $root = $this->realRepo();
    $this->useDocker($this->dockerStub($root, 'down', NULL, 1));

    $output = $this->runCommand(['--dir' => $root, '--env' => 'docker'], 2);

    $this->assertStringContainsString('Docker daemon is not reachable', $output);
  }

  public function testNoTasksIsConfigError(): void {
    $root = $this->realRepo(tasks: FALSE);
    $this->useAgent($this->passStub($root));

    $output = $this->runCommand(['--dir' => $root], 2);

    $this->assertStringContainsString('no llm tasks are declared', $output);
  }

  public function testSkillGlobMatchingNothingIsConfigError(): void {
    $root = $this->realRepo();
    $this->useAgent($this->passStub($root));

    $output = $this->runCommand(['--dir' => $root, '--skill' => ['nope-*']], 2);

    $this->assertStringContainsString('no skills matched --skill nope-*', $output);
  }

  public function testMalformedEvalIsConfigError(): void {
    $root = $this->realRepo();
    $this->useAgent($this->passStub($root));
    file_put_contents($root . '/skills/alpha/eval.yaml', "contract: [bad\n");

    $output = $this->runCommand(['--dir' => $root], 2);

    $this->assertStringContainsString('malformed YAML', $output);
  }

  public function testIncoherentEvalIsConfigError(): void {
    $root = $this->realRepo();
    $this->useAgent($this->passStub($root));
    file_put_contents($root . '/skills/alpha/eval.yaml', "version: \"1\"\ncontract:\n  tools:\n    required: [Bash]\n    forbidden: [Bash]\nllm:\n  tasks:\n    - name: invoked\n      prompt: go\n");

    $output = $this->runCommand(['--dir' => $root], 2);

    $this->assertStringContainsString("tool 'Bash' is in both required and forbidden", $output);
  }

  public function testJsonEmitsResultsDocument(): void {
    $root = $this->realRepo();
    $this->useAgent($this->passStub($root));

    $decoded = $this->decode($this->runCommand(['--dir' => $root, '--trials' => '2', '--json' => TRUE], 0));

    $this->assertSame('llm', $this->path($decoded, 'run', 'command'));
    $this->assertSame('alpha', $this->path($decoded, 'skills', 0, 'skill'));
    $this->assertSame('invoked', $this->path($decoded, 'skills', 0, 'llm', 'tasks', 0, 'task'));
    $this->assertCount(2, $this->pathArray($decoded, 'skills', 0, 'llm', 'tasks', 0, 'models', 0, 'trials'));
    $this->assertEqualsWithDelta(1.0, $this->path($decoded, 'skills', 0, 'llm', 'tasks', 0, 'models', 0, 'pass_rate'), 0.0001);
    $this->assertSame(2, $this->path($decoded, 'totals', 'trials'));
    $this->assertSame(400, $this->path($decoded, 'totals', 'tokens', 'in'));
    $this->assertSame('artifacts/alpha__invoked__haiku__t1.jsonl', $this->path($decoded, 'skills', 0, 'llm', 'tasks', 0, 'models', 0, 'trials', 0, 'transcript'));
  }

  public function testOutputDirPersistsSchemaValidResultsAndTranscripts(): void {
    $root = $this->realRepo();
    $this->useAgent($this->passStub($root));

    $this->runCommand(['--dir' => $root, '--trials' => '1', '--output-dir' => $root . '/runs'], 0);

    $results = glob($root . '/runs/*/results.json') ?: [];
    $this->assertCount(1, $results);
    $this->assertMatchesResultsSchema((string) file_get_contents($results[0]));

    $transcripts = glob($root . '/runs/*/artifacts/alpha__invoked__haiku__t1.jsonl') ?: [];
    $this->assertCount(1, $transcripts);
    $this->assertStringContainsString('harness build', (string) file_get_contents($transcripts[0]));
  }

  public function testSecretIsRedactedFromPersistedTranscripts(): void {
    $root = $this->realRepo();
    $secret = 'sk-fake-credential-value';
    $this->useAgent($this->stub($root, 'leak', '{"type":"result","note":"token ' . $secret . '"}'));

    $this->runCommand(['--dir' => $root, '--trials' => '1', '--output-dir' => $root . '/runs'], 1);

    $transcripts = glob($root . '/runs/*/artifacts/*.jsonl') ?: [];
    $content = (string) file_get_contents($transcripts[0]);
    $this->assertStringNotContainsString($secret, $content);
    $this->assertStringContainsString('[REDACTED]', $content);
  }

  public function testOutputFileAndDisabledRedactionWarns(): void {
    $root = $this->realRepo(redact: FALSE);
    $this->useAgent($this->passStub($root));
    $file = $root . '/results-out.json';

    $output = $this->runCommand(['--dir' => $root, '--trials' => '1', '--output' => $file], 0);

    $this->assertFileExists($file);
    $this->assertMatchesResultsSchema((string) file_get_contents($file));
    $this->assertStringContainsString('WARNING redaction disabled', $output);
  }

  public function testValidationWarningGoesToStderr(): void {
    $root = $this->realRepo();
    $this->useAgent($this->passStub($root));
    file_put_contents($root . '/skills/alpha/eval.yaml', "mystery: true\n", FILE_APPEND);

    $output = $this->runCommand(['--dir' => $root, '--trials' => '1'], 0);

    $this->assertStringContainsString('WARNING', $output);
    $this->assertStringContainsString('mystery', $output);
  }

  public function testDefaultsToCurrentDirectory(): void {
    $this->useAgent('claude');

    $output = $this->runCommand([], 2);

    $this->assertStringContainsString('no skills found under the configured skills paths', $output);
  }

  public function testJsonConfigErrorEmitsErrorDocument(): void {
    $root = $this->realRepo();
    $this->useDocker($this->dockerStub($root, 'down', NULL, 1));

    $decoded = $this->decode($this->runCommand(['--dir' => $root, '--env' => 'docker', '--json' => TRUE], 2));

    $this->assertFalse($decoded['ok']);
    $this->assertSame([], $decoded['skills']);
    $this->assertNotSame([], $decoded['errors']);
  }

  public function testQuietPrintsFailuresOnly(): void {
    $root = $this->realRepo();
    $this->useAgent($this->stub($root, 'fail', self::FAIL_STREAM));

    $output = $this->runCommand(['--dir' => $root, '--trials' => '1', '--quiet' => TRUE], 1);

    $this->assertStringContainsString('alpha invoked haiku FAIL', $output);
    $this->assertStringNotContainsString('verdict(s) across', $output);
  }

  /**
   * Extracts the first model's trials with volatile fields normalised.
   *
   * @param array<mixed> $decoded
   *   The decoded results document.
   *
   * @return array<mixed>
   *   The model row with each trial's duration zeroed.
   */
  protected function model(array $decoded): array {
    $model = $this->pathArray($decoded, 'skills', 0, 'llm', 'tasks', 0, 'models', 0);

    $trials = [];
    foreach ($this->pathArray($model, 'trials') as $trial) {
      $trial = is_array($trial) ? $trial : [];
      $trial['duration_ms'] = 0;
      $trials[] = $trial;
    }

    $model['trials'] = $trials;

    return $model;
  }

  /**
   * Builds a real fixture repository with one skill and one llm task.
   *
   * @param bool $tasks
   *   Whether to declare an llm task.
   * @param bool $redact
   *   Whether redaction stays enabled (FALSE writes `report.redact: false`).
   *
   * @return string
   *   The repository root.
   */
  protected function realRepo(bool $tasks = TRUE, bool $redact = TRUE): string {
    $this->tempDir = dirname(__DIR__, 3) . '/.artifacts/tmp/llmcmd-' . getmypid() . '-' . uniqid();
    mkdir($this->tempDir . '/skills/alpha', 0777, TRUE);

    $repo = "version: \"1\"\nmodels:\n  aliases:\n    haiku: claude-haiku-4-5\n  default: haiku\n";
    if (!$redact) {
      $repo .= "report:\n  redact: false\n";
    }
    file_put_contents($this->tempDir . '/skilltest.yml', $repo);

    file_put_contents($this->tempDir . '/skills/alpha/SKILL.md', "---\nname: alpha\ndescription: A clean well-formed skill for tests.\n---\n# Body\n");

    $eval = "version: \"1\"\ncontract:\n  tools:\n    allowed: [Bash]\n    required: [Bash]\n  commands:\n    required:\n      builds: '\\bharness\\s+build\\b'\n    forbidden:\n      no push: '\\bgit\\s+push\\b'\n";
    if ($tasks) {
      $eval .= "llm:\n  tasks:\n    - name: invoked\n      prompt: Build the thing\n";
    }
    file_put_contents($this->tempDir . '/skills/alpha/eval.yaml', $eval);

    return $this->tempDir;
  }

  /**
   * Writes a passing stub agent and returns its command prefix.
   *
   * @param string $root
   *   The repository root the stub lives under.
   *
   * @return string
   *   The `php <path>` command prefix.
   */
  protected function passStub(string $root): string {
    return $this->stub($root, 'pass', self::PASS_STREAM);
  }

  /**
   * Writes a stub agent script and returns its command prefix.
   *
   * @param string $root
   *   The repository root the stub lives under.
   * @param string $name
   *   The stub filename stem.
   * @param string|null $stream
   *   The stream-json to emit, or NULL to emit nothing.
   * @param string $extra
   *   Extra PHP to run before emitting (e.g. a sleep to force a timeout).
   *
   * @return string
   *   The `php <path>` command prefix.
   */
  protected function stub(string $root, string $name, ?string $stream, string $extra = ''): string {
    $path = $root . '/' . $name . '-agent.php';
    $body = "<?php\n" . $extra . "\n";

    if ($stream !== NULL) {
      $stream_file = $root . '/' . $name . '-stream.txt';
      file_put_contents($stream_file, $stream);
      $body .= 'readfile(' . var_export($stream_file, TRUE) . ");\n";
    }

    $body .= "exit(0);\n";
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
   * Writes a stub docker binary and returns its command prefix.
   *
   * The stub answers the daemon probe with the given exit and, for `run`,
   * emits the canned transcript to stdout, so the whole docker path is
   * exercised without a real daemon.
   *
   * @param string $root
   *   The repository root the stub lives under.
   * @param string $name
   *   The stub filename stem.
   * @param string|null $stream
   *   The stream-json a `run` emits, or NULL to emit nothing.
   * @param int $version_exit
   *   The `version` probe's exit code; non-zero marks the daemon down.
   *
   * @return string
   *   The `php <path>` command prefix.
   */
  protected function dockerStub(string $root, string $name, ?string $stream, int $version_exit = 0): string {
    $path = $root . '/' . $name . '-docker.php';
    $stream_file = $root . '/' . $name . '-docker-stream.txt';
    file_put_contents($stream_file, $stream ?? '');

    $body = "<?php\n";
    $body .= '$sub = $argv[1] ?? "";' . "\n";
    $body .= 'if ($sub === "version") { exit(' . $version_exit . "); }\n";
    $body .= 'if ($sub === "run") { readfile(' . var_export($stream_file, TRUE) . "); exit(0); }\n";
    $body .= "exit(0);\n";
    file_put_contents($path, $body);

    return 'php ' . escapeshellarg($path);
  }

  /**
   * Points the docker seam at a stub command.
   *
   * @param string $command
   *   The stub command prefix.
   */
  protected function useDocker(string $command): void {
    putenv(DockerPreflight::ENV_DOCKER . '=' . $command);
  }

  /**
   * Runs the llm command and asserts the exit code.
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
    $this->applicationInitFromCommand(LlmCommand::class);
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
