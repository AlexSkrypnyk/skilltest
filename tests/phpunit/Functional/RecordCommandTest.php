<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\PhpunitHelpers\Traits\ApplicationTrait;
use AlexSkrypnyk\SkillTest\Command\RecordCommand;
use AlexSkrypnyk\SkillTest\Command\RunCommand;
use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use AlexSkrypnyk\SkillTest\Live\AgentPreflight;
use AlexSkrypnyk\SkillTest\Live\LlmSuite;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class RecordCommandTest.
 *
 * Functional test for the record command: the full live stack driven against a
 * stub agent, so recording, fixture writing, the overwrite guard, redaction,
 * grading against the written file, and the preconditions are exercised without
 * spending a token. The recorded fixture is then fed to the deterministic run
 * to prove the two suites share one fixture.
 */
#[CoversClass(RecordCommand::class)]
#[Group('command')]
final class RecordCommandTest extends TestCase {

  use ApplicationTrait;

  /**
   * A credential environment variable whose value must never be persisted.
   */
  protected const string SECRET_ENV = 'ANTHROPIC_API_KEY';

  /**
   * The credential value the stub agent leaks and the redactor must scrub.
   */
  protected const string SECRET = 'sk-fake-credential-value';

  /**
   * The base contract every helper eval declares.
   */
  protected const string CONTRACT = "contract:\n  tools:\n    allowed: [Bash]\n    required: [Bash]\n  commands:\n    required:\n      builds: '\\bharness\\s+build\\b'\n    forbidden:\n      no push: '\\bgit\\s+push\\b'\n";

  /**
   * The default single-task llm block.
   */
  protected const string ONE_TASK = "  tasks:\n    - name: invoked\n      prompt: Build the thing\n";

  /**
   * The stream-json a passing stub agent emits.
   */
  protected const string PASS_STREAM = '{"type":"tool_use","name":"Bash","input":{"command":"harness build"}}' . "\n" . '{"type":"result","num_turns":3,"total_cost_usd":0.01,"usage":{"input_tokens":100,"output_tokens":50}}' . "\n";

  /**
   * The stream-json a contract-violating stub agent emits.
   */
  protected const string FAIL_STREAM = '{"type":"tool_use","name":"Bash","input":{"command":"git push origin main"}}' . "\n" . '{"type":"result","num_turns":2,"total_cost_usd":0.01}' . "\n";

  /**
   * The stream-json a passing stub agent that also leaks a secret emits.
   */
  protected const string LEAK_STREAM = '{"type":"tool_use","name":"Bash","input":{"command":"harness build"}}' . "\n" . '{"type":"result","note":"token ' . self::SECRET . '"}' . "\n";

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
    putenv(self::SECRET_ENV . '=' . self::SECRET);
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

  public function testRecordsFixtureAndPassesWhenContractHolds(): void {
    $root = $this->realRepo();
    $this->useAgent($this->stub($root, 'pass', self::PASS_STREAM));
    $fixture = $root . '/skills/alpha/fixtures/transcript.jsonl';
    $this->assertFileDoesNotExist($fixture);

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha'], 0);

    $this->assertFileExists($fixture);
    $this->assertSame(self::PASS_STREAM, (string) file_get_contents($fixture));
    $this->assertStringContainsString("Recorded skills/alpha/fixtures/transcript.jsonl (skill 'alpha', task 'invoked', model 'claude-haiku-4-5').", $output);
    $this->assertStringContainsString('Contract holds', $output);
    $this->assertStringContainsString('Review the fixture diff before committing.', $output);
    $this->assertSame([], glob($root . '/.skilltest/tmp/ws-*') ?: [], 'The trial workspace should be cleaned up.');
  }

  public function testDefaultsToFixturesPathAndHintsWhenTranscriptUnset(): void {
    $root = $this->realRepo(transcript: FALSE);
    $this->useAgent($this->stub($root, 'pass', self::PASS_STREAM));

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha'], 0);

    $this->assertFileExists($root . '/skills/alpha/fixtures/transcript.jsonl');
    $this->assertStringContainsString("set 'deterministic.transcript: fixtures/transcript.jsonl'", $output);
  }

  public function testContractViolationWritesFileAndExitsOne(): void {
    $root = $this->realRepo();
    $this->useAgent($this->stub($root, 'fail', self::FAIL_STREAM));
    $fixture = $root . '/skills/alpha/fixtures/transcript.jsonl';

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha'], 1);

    $this->assertFileExists($fixture);
    $this->assertSame(self::FAIL_STREAM, (string) file_get_contents($fixture));
    $this->assertStringContainsString('Contract failed', $output);
    $this->assertStringContainsString("contract.commands.forbidden FAIL - forbidden behaviour 'no push'", $output);
    $this->assertStringContainsString('written for inspection', $output);
  }

  public function testAgentNonZeroExitFailsButWritesFile(): void {
    $root = $this->realRepo();
    $this->useAgent($this->stub($root, 'broken', self::PASS_STREAM, 3));
    $fixture = $root . '/skills/alpha/fixtures/transcript.jsonl';

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha'], 1);

    $this->assertFileExists($fixture);
    $this->assertStringContainsString('live.agent FAIL - agent run exited with code 3', $output);
  }

  public function testTimeoutIsReportedAsAgentFailure(): void {
    $root = $this->realRepo();
    $this->useAgent($this->stub($root, 'hang', self::PASS_STREAM, 0, 5));
    putenv(LlmSuite::ENV_TIMEOUT . '=0.5');

    $started = microtime(TRUE);
    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha'], 1);

    $this->assertLessThan(4.0, microtime(TRUE) - $started);
    $this->assertStringContainsString('live.agent FAIL - agent run timed out', $output);
  }

  public function testCustomChecksAreAssertedAgainstTheFixture(): void {
    $root = $this->realRepo();
    file_put_contents($root . '/skills/alpha/eval.yaml', "  checks:\n    - name: board\n      run: " . $this->checkScript($root, 1) . "\n", FILE_APPEND);
    $this->useAgent($this->stub($root, 'pass', self::PASS_STREAM));

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha'], 1);

    $this->assertStringContainsString('check.board FAIL', $output);
  }

  public function testMalformedTaskInputsAreConfigError(): void {
    $root = $this->realRepo(tasks: "  tasks:\n    - name: invoked\n      prompt: Build it\n      inputs:\n        repos:\n          - dest: sub\n");
    $this->useAgent($this->stub($root, 'pass', self::PASS_STREAM));

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha'], 2);

    $this->assertStringContainsString("a repos entry requires a 'source'", $output);
  }

  public function testRefusesToOverwriteWithoutForce(): void {
    $root = $this->realRepo();
    $this->useAgent($this->stub($root, 'pass', self::PASS_STREAM));
    $fixture = $this->seedFixture($root, 'OLD CONTENT');

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha'], 2);

    $this->assertStringContainsString('fixture skills/alpha/fixtures/transcript.jsonl already exists; pass --force to overwrite.', $output);
    $this->assertSame('OLD CONTENT', (string) file_get_contents($fixture), 'The existing fixture must be left untouched.');
  }

  public function testForceOverwritesExistingFixture(): void {
    $root = $this->realRepo();
    $this->useAgent($this->stub($root, 'pass', self::PASS_STREAM));
    $fixture = $this->seedFixture($root, 'OLD CONTENT');

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha', '--force' => TRUE], 0);

    $this->assertSame(self::PASS_STREAM, (string) file_get_contents($fixture));
    $this->assertStringContainsString('Overwrote skills/alpha/fixtures/transcript.jsonl', $output);
  }

  public function testSelectsTaskByName(): void {
    $root = $this->realRepo(tasks: "  tasks:\n    - name: invoked\n      prompt: A\n    - name: discovery\n      prompt: B\n");
    $this->useAgent($this->stub($root, 'pass', self::PASS_STREAM));

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha', '--task' => 'discovery'], 0);

    $this->assertStringContainsString("task 'discovery'", $output);
  }

  public function testUnknownTaskIsConfigError(): void {
    $root = $this->realRepo();
    $this->useAgent($this->stub($root, 'pass', self::PASS_STREAM));

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha', '--task' => 'nope'], 2);

    $this->assertStringContainsString("skill 'alpha' has no task named 'nope'", $output);
  }

  public function testNoTasksIsConfigError(): void {
    $root = $this->realRepo(tasks: '');
    $this->useAgent($this->stub($root, 'pass', self::PASS_STREAM));

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha'], 2);

    $this->assertStringContainsString("skill 'alpha' declares no llm tasks to record", $output);
  }

  public function testTaskWithoutNameIsConfigError(): void {
    $root = $this->realRepo(tasks: "  tasks:\n    - prompt: no name here\n");
    $this->useAgent($this->stub($root, 'pass', self::PASS_STREAM));

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha'], 2);

    $this->assertStringContainsString("an llm task requires a 'name'", $output);
  }

  public function testTaskWithoutPromptIsConfigError(): void {
    $root = $this->realRepo(tasks: "  tasks:\n    - name: invoked\n");
    $this->useAgent($this->stub($root, 'pass', self::PASS_STREAM));

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha'], 2);

    $this->assertStringContainsString("llm task 'invoked' requires a 'prompt'", $output);
  }

  public function testModelOverrideResolvesAlias(): void {
    $root = $this->realRepo();
    $this->useAgent($this->stub($root, 'pass', self::PASS_STREAM));

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha', '--model' => 'haiku'], 0);

    $this->assertStringContainsString("model 'claude-haiku-4-5'", $output);
  }

  public function testNoModelConfiguredIsConfigError(): void {
    $root = $this->realRepo(models: '');
    $this->useAgent($this->stub($root, 'pass', self::PASS_STREAM));

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha'], 2);

    $this->assertStringContainsString('no model configured; set models.default or pass --model', $output);
  }

  public function testMissingSkillOptionIsConfigError(): void {
    $root = $this->realRepo();
    $this->useAgent($this->stub($root, 'pass', self::PASS_STREAM));

    $output = $this->runRecord(['--dir' => $root], 2);

    $this->assertStringContainsString('the --skill option is required', $output);
  }

  public function testUnknownSkillIsConfigError(): void {
    $root = $this->realRepo();
    $this->useAgent($this->stub($root, 'pass', self::PASS_STREAM));

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'ghost'], 2);

    $this->assertStringContainsString("no skill named 'ghost' with an eval.yaml was found", $output);
  }

  public function testDockerEnvironmentIsRejected(): void {
    $root = $this->realRepo(docker: TRUE);
    $this->useAgent($this->stub($root, 'pass', self::PASS_STREAM));

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha'], 2);

    $this->assertStringContainsString('docker environment is not yet implemented', $output);
  }

  public function testMissingCredentialsIsConfigError(): void {
    $root = $this->realRepo();
    $this->useAgent($this->stub($root, 'pass', self::PASS_STREAM));
    putenv(self::SECRET_ENV);
    putenv('HOME=' . $root . '/no-home');

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha'], 2);

    $this->assertStringContainsString('no agent credentials found', $output);
  }

  public function testMissingBinaryIsConfigError(): void {
    $root = $this->realRepo();
    mkdir($root . '/empty-path', 0777, TRUE);
    putenv('PATH=' . $root . '/empty-path');

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha'], 2);

    $this->assertStringContainsString("the 'claude' agent was not found on PATH", $output);
  }

  public function testSecretIsRedactedFromFixture(): void {
    $root = $this->realRepo();
    $this->useAgent($this->stub($root, 'leak', self::LEAK_STREAM));

    $this->runRecord(['--dir' => $root, '--skill' => 'alpha'], 0);

    $content = (string) file_get_contents($root . '/skills/alpha/fixtures/transcript.jsonl');
    $this->assertStringNotContainsString(self::SECRET, $content);
    $this->assertStringContainsString('[REDACTED]', $content);
  }

  public function testDisabledRedactionWarns(): void {
    $root = $this->realRepo(redact: FALSE);
    $this->useAgent($this->stub($root, 'pass', self::PASS_STREAM));

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha'], 0);

    $this->assertStringContainsString('WARNING redaction disabled', $output);
  }

  public function testMalformedEvalIsConfigError(): void {
    $root = $this->realRepo();
    $this->useAgent($this->stub($root, 'pass', self::PASS_STREAM));
    file_put_contents($root . '/skills/alpha/eval.yaml', "contract: [bad\n");

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha'], 2);

    $this->assertStringContainsString('malformed YAML', $output);
  }

  public function testIncoherentEvalStillBlocksDespiteMissingFixture(): void {
    $root = $this->realRepo();
    $this->useAgent($this->stub($root, 'pass', self::PASS_STREAM));
    file_put_contents($root . '/skills/alpha/eval.yaml', "version: \"1\"\ndeterministic:\n  transcript: fixtures/transcript.jsonl\ncontract:\n  tools:\n    required: [Bash]\n    forbidden: [Bash]\nllm:\n" . self::ONE_TASK);

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha'], 2);

    $this->assertStringContainsString("tool 'Bash' is in both required and forbidden", $output);
    $this->assertStringNotContainsString('fixture not found', $output);
  }

  public function testValidationWarningGoesToStderr(): void {
    $root = $this->realRepo();
    $this->useAgent($this->stub($root, 'pass', self::PASS_STREAM));
    file_put_contents($root . '/skills/alpha/eval.yaml', "mystery: true\n", FILE_APPEND);

    $output = $this->runRecord(['--dir' => $root, '--skill' => 'alpha'], 0);

    $this->assertStringContainsString('WARNING', $output);
    $this->assertStringContainsString('mystery', $output);
  }

  public function testDefaultsToCurrentDirectory(): void {
    $this->useAgent('claude');

    $output = $this->runRecord(['--skill' => 'ghost'], 2);

    $this->assertStringContainsString("no skill named 'ghost'", $output);
  }

  public function testDeterministicRunConsumesRecordedFixture(): void {
    $root = $this->realRepo();
    $this->useAgent($this->stub($root, 'pass', self::PASS_STREAM));

    $this->runRecord(['--dir' => $root, '--skill' => 'alpha'], 0);
    $this->applicationTearDown();

    $output = $this->runRun(['--dir' => $root, '--skill' => ['alpha'], '--group' => 'transcript'], 0);
    $this->assertStringContainsString('alpha', $output);
  }

  public function testDeterministicRunFailsOnRecordedContractViolation(): void {
    $root = $this->realRepo();
    $this->useAgent($this->stub($root, 'fail', self::FAIL_STREAM));

    $this->runRecord(['--dir' => $root, '--skill' => 'alpha'], 1);
    $this->applicationTearDown();

    $output = $this->runRun(['--dir' => $root, '--skill' => ['alpha'], '--group' => 'transcript'], 1);
    $this->assertStringContainsString("contract.commands.forbidden", $output);
  }

  /**
   * Builds a real fixture repository with one skill and one llm task.
   *
   * @param bool $transcript
   *   Whether the eval declares `deterministic.transcript`.
   * @param string|null $tasks
   *   The llm tasks block, or NULL for the default single task; '' declares no
   *   tasks.
   * @param bool $redact
   *   Whether redaction stays enabled (FALSE writes `report.redact: false`).
   * @param string|null $models
   *   The repo models block, or NULL for the default aliases and default; ''
   *   declares no models at all.
   * @param bool $docker
   *   Whether the repo selects the docker environment.
   *
   * @return string
   *   The repository root.
   */
  protected function realRepo(bool $transcript = TRUE, ?string $tasks = NULL, bool $redact = TRUE, ?string $models = NULL, bool $docker = FALSE): string {
    $this->tempDir = dirname(__DIR__, 3) . '/.artifacts/tmp/recordcmd-' . getmypid() . '-' . uniqid();
    mkdir($this->tempDir . '/skills/alpha', 0777, TRUE);

    $repo = "version: \"1\"\n" . ($models ?? "models:\n  aliases:\n    haiku: claude-haiku-4-5\n  default: haiku\n");
    if ($docker) {
      $repo .= "llm:\n  environment: docker\n";
    }
    if (!$redact) {
      $repo .= "report:\n  redact: false\n";
    }
    file_put_contents($this->tempDir . '/skilltest.yml', $repo);

    file_put_contents($this->tempDir . '/skills/alpha/SKILL.md', "---\nname: alpha\ndescription: A clean well-formed skill for tests.\n---\n# Body\n");

    $eval = "version: \"1\"\n" . self::CONTRACT;
    if ($transcript) {
      $eval .= "deterministic:\n  transcript: fixtures/transcript.jsonl\n";
    }
    $eval .= "llm:\n" . ($tasks ?? self::ONE_TASK);
    file_put_contents($this->tempDir . '/skills/alpha/eval.yaml', $eval);

    return $this->tempDir;
  }

  /**
   * Pre-creates an existing fixture, returning its path.
   *
   * @param string $root
   *   The repository root.
   * @param string $content
   *   The content to seed the fixture with.
   *
   * @return string
   *   The fixture path.
   */
  protected function seedFixture(string $root, string $content): string {
    $dir = $root . '/skills/alpha/fixtures';
    mkdir($dir, 0777, TRUE);
    $path = $dir . '/transcript.jsonl';
    file_put_contents($path, $content);

    return $path;
  }

  /**
   * Writes a stub agent script and returns its command prefix.
   *
   * @param string $root
   *   The repository root the stub lives under.
   * @param string $name
   *   The stub filename stem.
   * @param string $stream
   *   The stream-json the stub emits.
   * @param int $exit
   *   The exit code the stub returns.
   * @param int $sleep
   *   Seconds to sleep before emitting, to force a timeout (0 for none).
   *
   * @return string
   *   The `php <path>` command prefix.
   */
  protected function stub(string $root, string $name, string $stream, int $exit = 0, int $sleep = 0): string {
    $stream_file = $root . '/' . $name . '-stream.txt';
    file_put_contents($stream_file, $stream);

    $body = "<?php\n";
    if ($sleep > 0) {
      $body .= 'sleep(' . $sleep . ");\n";
    }
    $body .= "readfile(" . var_export($stream_file, TRUE) . ");\nexit(" . $exit . ");\n";

    $path = $root . '/' . $name . '-agent.php';
    file_put_contents($path, $body);

    return 'php ' . escapeshellarg($path);
  }

  /**
   * Writes a custom check script that exits with the given code.
   *
   * @param string $root
   *   The repository root the script lives under.
   * @param int $exit
   *   The exit code the check returns.
   *
   * @return string
   *   The `php <path>` run command.
   */
  protected function checkScript(string $root, int $exit): string {
    $path = $root . '/check.php';
    file_put_contents($path, "<?php\nexit(" . $exit . ");\n");

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
   * Runs the record command and asserts the exit code.
   *
   * @param array<string, string|bool|string[]> $input
   *   The command input.
   * @param int $expected_exit
   *   The expected exit code.
   *
   * @return string
   *   The combined command output.
   */
  protected function runRecord(array $input, int $expected_exit): string {
    $this->applicationInitFromCommand(RecordCommand::class);
    $this->applicationGetTester()->run($input, ['capture_stderr_separately' => TRUE]);

    $this->assertSame($expected_exit, $this->applicationGetTester()->getStatusCode());

    return $this->applicationGetTester()->getDisplay() . $this->applicationGetTester()->getErrorOutput();
  }

  /**
   * Runs the deterministic run command and asserts the exit code.
   *
   * @param array<string, string|bool|string[]> $input
   *   The command input.
   * @param int $expected_exit
   *   The expected exit code.
   *
   * @return string
   *   The combined command output.
   */
  protected function runRun(array $input, int $expected_exit): string {
    $this->applicationInitFromCommand(RunCommand::class);
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
