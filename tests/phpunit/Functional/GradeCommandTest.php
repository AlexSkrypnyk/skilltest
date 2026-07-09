<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\PhpunitHelpers\Traits\ApplicationTrait;
use AlexSkrypnyk\SkillTest\Command\GradeCommand;
use AlexSkrypnyk\SkillTest\Command\RunCommand;
use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use AlexSkrypnyk\SkillTest\Live\AgentPreflight;
use AlexSkrypnyk\SkillTest\Live\LlmSuite;
use AlexSkrypnyk\SkillTest\Tests\Traits\ResultsDocumentTrait;
use AlexSkrypnyk\SkillTest\Tests\Traits\SchemaValidationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class GradeCommandTest.
 *
 * Functional test for the grade command: asserting a contract against an
 * arbitrary transcript, re-scoring a saved run against a tightened contract with
 * no agent execution, re-judging behind the opt-in flag, and every error path.
 */
#[CoversClass(GradeCommand::class)]
#[Group('command')]
final class GradeCommandTest extends TestCase {

  use ApplicationTrait;
  use ResultsDocumentTrait;
  use SchemaValidationTrait;

  /**
   * The base contract every helper eval declares.
   */
  protected const string CONTRACT = "contract:\n  tools:\n    allowed: [Bash]\n    required: [Bash]\n  commands:\n    forbidden:\n      no push: '\\bgit\\s+push\\b'\n";

  /**
   * A transcript that satisfies the contract.
   */
  protected const string PASS_TRANSCRIPT = '{"type":"tool_use","name":"Bash","input":{"command":"harness build"}}' . "\n" . '{"type":"result","result":"done"}' . "\n";

  /**
   * A transcript that violates the forbidden-command contract.
   */
  protected const string FAIL_TRANSCRIPT = '{"type":"tool_use","name":"Bash","input":{"command":"git push origin main"}}' . "\n" . '{"type":"result","result":"pushed"}' . "\n";

  /**
   * The temporary repository root.
   */
  protected string $tempDir = '';

  /**
   * The captured original HOME, restored on teardown.
   */
  protected string|false $home = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->home = getenv('HOME');
    putenv(ConfigLoader::ENV_CONFIG);
    putenv(AgentPreflight::ENV_AGENT);
    putenv(LlmSuite::ENV_TIMEOUT);
    putenv('ANTHROPIC_API_KEY');
    putenv('CLAUDE_CODE_OAUTH_TOKEN');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv(ConfigLoader::ENV_CONFIG);
    putenv(AgentPreflight::ENV_AGENT);
    putenv(LlmSuite::ENV_TIMEOUT);
    putenv('ANTHROPIC_API_KEY');
    putenv('CLAUDE_CODE_OAUTH_TOKEN');
    putenv('HOME' . ($this->home === FALSE ? '' : '=' . $this->home));

    if ($this->tempDir !== '' && is_dir($this->tempDir)) {
      $this->remove($this->tempDir);
    }

    $this->applicationTearDown();

    parent::tearDown();
  }

  public function testTranscriptContractHoldsPasses(): void {
    $root = $this->repo();
    $file = $this->write($root, 'pass.jsonl', self::PASS_TRANSCRIPT);

    $output = $this->runGrade(['--dir' => $root, '--transcript' => $file, '--skill' => 'alpha'], 0);

    $this->assertStringContainsString('Contract holds.', $output);
  }

  public function testTranscriptContractViolationFails(): void {
    $root = $this->repo();
    $file = $this->write($root, 'fail.jsonl', self::FAIL_TRANSCRIPT);

    $output = $this->runGrade(['--dir' => $root, '--transcript' => $file, '--skill' => 'alpha'], 1);

    $this->assertStringContainsString('Contract failed:', $output);
    $this->assertStringContainsString("contract.commands.forbidden FAIL - forbidden behaviour 'no push'", $output);
  }

  public function testTranscriptReproducesDeterministicGroupResult(): void {
    // The same transcript, graded by the deterministic transcript group and by
    // grade --transcript, must reach the same verdict.
    $root = $this->repo(transcript: 'fixtures/transcript.jsonl');
    $fixture = $this->write($root, 'skills/alpha/fixtures/transcript.jsonl', self::FAIL_TRANSCRIPT);

    $grade = $this->runGrade(['--dir' => $root, '--transcript' => $fixture, '--skill' => 'alpha'], 1);
    $this->applicationTearDown();
    $run = $this->runRun(['--dir' => $root, '--skill' => ['alpha'], '--group' => 'transcript'], 1);

    $this->assertStringContainsString('contract.commands.forbidden', $grade);
    $this->assertStringContainsString('contract.commands.forbidden', $run);
  }

  public function testTranscriptJsonEmitsResult(): void {
    $root = $this->repo();
    $file = $this->write($root, 'pass.jsonl', self::PASS_TRANSCRIPT);

    $output = $this->runGrade(['--dir' => $root, '--transcript' => $file, '--skill' => 'alpha', '--json' => TRUE], 0);

    $decoded = $this->decodeArray(trim($output));
    $this->assertTrue($decoded['ok']);
    $this->assertSame('alpha', $decoded['skill']);
  }

  public function testTranscriptRequiresSkill(): void {
    $root = $this->repo();
    $file = $this->write($root, 'pass.jsonl', self::PASS_TRANSCRIPT);

    $output = $this->runGrade(['--dir' => $root, '--transcript' => $file], 2);

    $this->assertStringContainsString('the --skill option is required with --transcript', $output);
  }

  public function testTranscriptUnknownSkillIsConfigError(): void {
    $root = $this->repo();
    $file = $this->write($root, 'pass.jsonl', self::PASS_TRANSCRIPT);

    $output = $this->runGrade(['--dir' => $root, '--transcript' => $file, '--skill' => 'ghost'], 2);

    $this->assertStringContainsString("no skill named 'ghost'", $output);
  }

  public function testTranscriptMissingFileIsConfigError(): void {
    $root = $this->repo();

    $output = $this->runGrade(['--dir' => $root, '--transcript' => $root . '/nope.jsonl', '--skill' => 'alpha'], 2);

    $this->assertStringContainsString('transcript file not found', $output);
  }

  public function testNoModeIsConfigError(): void {
    $root = $this->repo();

    $output = $this->runGrade(['--dir' => $root], 2);

    $this->assertStringContainsString('pass --transcript', $output);
  }

  public function testBothModesIsConfigError(): void {
    $root = $this->repo();
    $file = $this->write($root, 'pass.jsonl', self::PASS_TRANSCRIPT);

    $output = $this->runGrade(['--dir' => $root, '--transcript' => $file, '--results' => $file], 2);

    $this->assertStringContainsString('pass only one of', $output);
  }

  public function testMalformedConfigIsConfigError(): void {
    $root = $this->repo();
    file_put_contents($root . '/skilltest.yml', "version: [bad\n");
    $file = $this->write($root, 'pass.jsonl', self::PASS_TRANSCRIPT);

    $output = $this->runGrade(['--dir' => $root, '--transcript' => $file, '--skill' => 'alpha'], 2);

    $this->assertStringContainsString('malformed YAML', $output);
  }

  public function testResultsRescoreTightenedContractFlipsToFail(): void {
    // The saved trial passed, but its transcript pushed; the current contract
    // now forbids pushing, so re-scoring must fail it - with no agent run.
    $root = $this->repo();
    $file = $this->writeResults($root, [$this->trialRow(1, TRUE, 'artifacts/t1.jsonl')], ['artifacts/t1.jsonl' => self::FAIL_TRANSCRIPT]);

    $output = $this->runGrade(['--dir' => $root, '--results' => $file], 1);

    $this->assertStringContainsString('Re-scored 1 trial(s): 1 newly failing, 0 newly passing.', $output);
    $this->assertStringContainsString('verdict(s) fail after re-scoring', $output);
  }

  public function testResultsRescoreCleanStaysPassing(): void {
    $root = $this->repo();
    $file = $this->writeResults($root, [$this->trialRow(1, TRUE, 'artifacts/t1.jsonl')], ['artifacts/t1.jsonl' => self::PASS_TRANSCRIPT]);

    $output = $this->runGrade(['--dir' => $root, '--results' => $file], 0);

    $this->assertStringContainsString('0 newly failing', $output);
    $this->assertStringContainsString('All task-on-model verdicts pass.', $output);
  }

  public function testResultsJsonEmitsRescoredDocument(): void {
    $root = $this->repo();
    $file = $this->writeResults($root, [$this->trialRow(1, TRUE, 'artifacts/t1.jsonl')], ['artifacts/t1.jsonl' => self::FAIL_TRANSCRIPT]);

    $output = $this->runGrade(['--dir' => $root, '--results' => $file, '--json' => TRUE], 1);

    $this->assertMatchesResultsSchema(trim($output));
    $decoded = $this->decodeArray(trim($output));
    $this->assertFalse($this->documentTrialPass($decoded), 'The re-scored trial flipped to failing.');
    $this->assertNull($this->documentMinimalModel($decoded), 'No model supports the skill after the flip.');
  }

  public function testResultsSkillNotInConfigIsLeftUnchanged(): void {
    $root = $this->repo();
    $file = $this->writeResults($root, [$this->trialRow(1, TRUE, 'artifacts/t1.jsonl')], ['artifacts/t1.jsonl' => self::FAIL_TRANSCRIPT], skill: 'ghost');

    $output = $this->runGrade(['--dir' => $root, '--results' => $file], 0);

    $this->assertStringContainsString('Re-scored 0 trial(s)', $output);
    $this->assertStringContainsString("skill 'ghost' is not in the current config", $output);
  }

  public function testResultsTrialWithoutTranscriptIsNoted(): void {
    $root = $this->repo();
    $trial = ['trial' => 1, 'pass' => TRUE, 'contract' => [], 'judge' => []];
    $file = $this->writeResults($root, [$trial], []);

    $output = $this->runGrade(['--dir' => $root, '--results' => $file], 0);

    $this->assertStringContainsString('carries no transcript artifact', $output);
  }

  public function testResultsPreservesRuntimeFailure(): void {
    // The saved trial failed on a non-zero agent exit, which the transcript
    // cannot reproduce; a clean contract re-grade must not resurrect it.
    $root = $this->repo();
    $contract = [['check' => 'live.agent', 'label' => 'agent run', 'pass' => FALSE, 'evidence' => '', 'message' => 'agent run exited with code 3.']];
    $file = $this->writeResults($root, [$this->trialRow(1, FALSE, 'artifacts/t1.jsonl', $contract)], ['artifacts/t1.jsonl' => self::PASS_TRANSCRIPT]);

    $output = $this->runGrade(['--dir' => $root, '--results' => $file, '--json' => TRUE], 1);

    $decoded = $this->decodeArray(trim($output));
    $this->assertFalse($this->documentTrialPass($decoded), 'The runtime failure is preserved.');
  }

  public function testResultsMissingFileIsConfigError(): void {
    $root = $this->repo();

    $output = $this->runGrade(['--dir' => $root, '--results' => $root . '/nope.json'], 2);

    $this->assertStringContainsString('results file not found', $output);
  }

  public function testResultsMalformedJsonIsConfigError(): void {
    $root = $this->repo();
    $file = $root . '/broken.json';
    file_put_contents($file, '{not json');

    $output = $this->runGrade(['--dir' => $root, '--results' => $file], 2);

    $this->assertStringContainsString('not a JSON object', $output);
  }

  public function testResultsWrongMajorIsConfigError(): void {
    $root = $this->repo();
    $file = $this->writeResults($root, [$this->trialRow(1, TRUE, 'artifacts/t1.jsonl')], ['artifacts/t1.jsonl' => self::PASS_TRANSCRIPT], version: '2');

    $output = $this->runGrade(['--dir' => $root, '--results' => $file], 2);

    $this->assertStringContainsString("schema version '2'", $output);
  }

  public function testResultsJudgeReRunsRubric(): void {
    // Re-judging with a stub that fails the criterion must flip a trial that
    // held its contract but no longer clears the rubric.
    $root = $this->repo(rubric: TRUE);
    putenv('ANTHROPIC_API_KEY=sk-test');
    $this->useAgent($this->judgeStub($root, '{"criteria":[{"id":1,"pass":false}],"reasoning":"no"}'));
    $judge = [['criterion' => 1, 'pass' => TRUE, 'unknown' => FALSE]];
    $file = $this->writeResults($root, [$this->trialRow(1, TRUE, 'artifacts/t1.jsonl', [], $judge)], ['artifacts/t1.jsonl' => self::PASS_TRANSCRIPT]);

    $output = $this->runGrade(['--dir' => $root, '--results' => $file, '--judge' => TRUE], 1);

    $this->assertStringContainsString('1 newly failing', $output);
  }

  public function testResultsJudgeMissingCredentialsIsConfigError(): void {
    $root = $this->repo(rubric: TRUE);
    putenv('ANTHROPIC_API_KEY');
    putenv('HOME=' . $root . '/no-home');
    $this->useAgent('claude');
    $file = $this->writeResults($root, [$this->trialRow(1, TRUE, 'artifacts/t1.jsonl')], ['artifacts/t1.jsonl' => self::PASS_TRANSCRIPT]);

    $output = $this->runGrade(['--dir' => $root, '--results' => $file, '--judge' => TRUE], 2);

    $this->assertStringContainsString('no agent credentials found', $output);
  }

  /**
   * Builds a real fixture repository with one skill and one llm task.
   *
   * @param bool $rubric
   *   Whether the eval declares a judge rubric.
   * @param string|null $transcript
   *   The `deterministic.transcript` path, or NULL to declare none.
   *
   * @return string
   *   The repository root.
   */
  protected function repo(bool $rubric = FALSE, ?string $transcript = NULL): string {
    $this->tempDir = dirname(__DIR__, 3) . '/.artifacts/tmp/gradecmd-' . getmypid() . '-' . uniqid();
    mkdir($this->tempDir . '/skills/alpha', 0777, TRUE);

    file_put_contents($this->tempDir . '/skilltest.yml', "version: \"1\"\nmodels:\n  aliases:\n    haiku: claude-haiku-4-5\n  default: haiku\n  judge: haiku\n");
    file_put_contents($this->tempDir . '/skills/alpha/SKILL.md', "---\nname: alpha\ndescription: A clean well-formed skill for tests.\n---\n# Body\n");

    $eval = "version: \"1\"\n" . self::CONTRACT;
    if ($transcript !== NULL) {
      $eval .= "deterministic:\n  transcript: " . $transcript . "\n";
    }
    $eval .= "llm:\n  tasks:\n    - name: invoked\n      prompt: Build the thing\n";
    if ($rubric) {
      $eval .= "  judge:\n    rubric:\n      - Did it build?\n";
    }
    file_put_contents($this->tempDir . '/skills/alpha/eval.yaml', $eval);

    return $this->tempDir;
  }

  /**
   * Writes a file under the repository, creating parent directories.
   *
   * @param string $root
   *   The repository root.
   * @param string $relative
   *   The path relative to the root.
   * @param string $content
   *   The file content.
   *
   * @return string
   *   The absolute path written.
   */
  protected function write(string $root, string $relative, string $content): string {
    $path = $root . '/' . $relative;
    $dir = dirname($path);

    if (!is_dir($dir)) {
      mkdir($dir, 0777, TRUE);
    }

    file_put_contents($path, $content);

    return $path;
  }

  /**
   * Builds one trial row for a results document.
   *
   * @param int $number
   *   The trial number.
   * @param bool $pass
   *   The stored pass verdict.
   * @param string $transcript
   *   The transcript artifact reference.
   * @param array<int, array<mixed>> $contract
   *   The stored contract rows.
   * @param array<int, array<mixed>> $judge
   *   The stored judge criteria.
   *
   * @return array<string, mixed>
   *   The trial row.
   */
  protected function trialRow(int $number, bool $pass, string $transcript, array $contract = [], array $judge = []): array {
    return ['trial' => $number, 'pass' => $pass, 'contract' => $contract, 'judge' => $judge, 'transcript' => $transcript];
  }

  /**
   * Writes a results document and its transcript artifacts under a run dir.
   *
   * @param string $root
   *   The repository root.
   * @param array<int, array<mixed>> $trials
   *   The trial rows for the single model.
   * @param array<string, string> $artifacts
   *   Transcript contents keyed by their run-relative path.
   * @param string $skill
   *   The skill name in the document.
   * @param string $version
   *   The document schema version.
   *
   * @return string
   *   The results.json path.
   */
  protected function writeResults(string $root, array $trials, array $artifacts, string $skill = 'alpha', string $version = '1'): string {
    $run_dir = $root . '/run';
    mkdir($run_dir, 0777, TRUE);

    foreach ($artifacts as $relative => $content) {
      $this->write($run_dir, $relative, $content);
    }

    $document = [
      'version' => $version,
      'tool' => ['name' => 'skilltest', 'version' => 'development'],
      'run' => ['id' => 'st-1', 'started' => '2026-07-09T00:00:00+00:00', 'duration_ms' => 1, 'command' => 'llm', 'environment' => 'host'],
      'skills' => [[
        'skill' => $skill,
        'path' => 'skills/' . $skill,
        'llm' => [
          'tasks' => [['task' => 'invoked', 'models' => [['model' => 'claude-haiku-4-5', 'alias' => 'haiku', 'trials' => $trials, 'pass_rate' => 1.0]]]],
          'verdict' => ['minimal_model' => 'haiku', 'threshold' => 0.8, 'trials' => count($trials)],
        ],
      ]],
      'hooks' => [],
      'coverage' => ['violations' => []],
      'totals' => ['checks' => 1, 'failures' => 0, 'trials' => count($trials), 'tokens' => ['in' => 0, 'out' => 0], 'cost_usd' => 0.0],
    ];

    $path = $run_dir . '/results.json';
    file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));

    return $path;
  }

  /**
   * Writes a stub judge agent that emits a fixed verdict, returning its prefix.
   *
   * @param string $root
   *   The repository root the stub lives under.
   * @param string $verdict
   *   The verdict JSON the stub emits on stdout.
   *
   * @return string
   *   The `php <path>` command prefix.
   */
  protected function judgeStub(string $root, string $verdict): string {
    $path = $root . '/judge-agent.php';
    file_put_contents($path, "<?php\necho " . var_export($verdict, TRUE) . ";\nexit(0);\n");

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
   * Runs the grade command and asserts the exit code.
   *
   * @param array<string, string|bool|string[]> $input
   *   The command input.
   * @param int $expected_exit
   *   The expected exit code.
   *
   * @return string
   *   The combined command output.
   */
  protected function runGrade(array $input, int $expected_exit): string {
    $this->applicationInitFromCommand(GradeCommand::class);
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
