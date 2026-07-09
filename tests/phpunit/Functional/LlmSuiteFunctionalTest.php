<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Live\LlmSuite;
use AlexSkrypnyk\SkillTest\Live\ProcessPool;
use AlexSkrypnyk\SkillTest\Live\TrialResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class LlmSuiteFunctionalTest.
 *
 * Drives the live suite over a real temp repo with an injected process pool,
 * asserting trials, contract grading, threshold gating, custom checks, agent
 * failures, artifacts, and selection errors without spending a token.
 */
#[CoversClass(LlmSuite::class)]
#[Group('live')]
final class LlmSuiteFunctionalTest extends TestCase {

  /**
   * A transcript that satisfies the alpha contract.
   */
  protected const string PASS_TRANSCRIPT = '{"type":"tool_use","name":"Bash","input":{"command":"harness build"}}' . "\n" . '{"type":"result","num_turns":3,"total_cost_usd":0.01,"usage":{"input_tokens":100,"output_tokens":50}}' . "\n";

  /**
   * A transcript that violates the alpha contract.
   */
  protected const string FAIL_TRANSCRIPT = '{"type":"tool_use","name":"Bash","input":{"command":"git push origin main"}}' . "\n" . '{"type":"result","num_turns":2,"total_cost_usd":0.02,"usage":{"input_tokens":80,"output_tokens":40}}' . "\n";

  /**
   * The base contract every helper eval declares.
   */
  protected const string CONTRACT = "contract:\n  tools:\n    allowed: [Bash]\n    required: [Bash]\n  commands:\n    required:\n      builds: '\\bharness\\s+build\\b'\n    forbidden:\n      no push: '\\bgit\\s+push\\b'\n";

  /**
   * The temporary repository root.
   */
  protected string $root = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    putenv(ConfigLoader::ENV_CONFIG);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv(ConfigLoader::ENV_CONFIG);

    if ($this->root !== '' && is_dir($this->root)) {
      $this->remove($this->root);
    }

    parent::tearDown();
  }

  public function testRunsTrialsAssertsContractAndComputesPassRate(): void {
    $config = $this->load("llm:\n  trials: 3\n  tasks:\n    - name: invoked\n      prompt: Build it\n");
    $pool = $this->pool([self::PASS_TRANSCRIPT, self::FAIL_TRANSCRIPT, self::PASS_TRANSCRIPT]);

    $report = $this->suite($pool)->run($config, []);

    $model = $report->skills[0]->tasks[0]->models[0];
    $this->assertCount(3, $model->trials);
    $this->assertEqualsWithDelta(2 / 3, $model->passRate(), 0.0001);
    $this->assertFalse($model->passed());
    $this->assertTrue($report->failed());
    $this->assertSame(3, $report->trials());
    $this->assertSame(['in' => 280, 'out' => 140], $report->tokens());
    $this->assertSame('claude-haiku-4-5', $model->model);
    $this->assertSame('haiku', $model->alias);
    $this->assertTrue($model->trials[0]->pass);
    $this->assertFalse($model->trials[1]->pass);
    $this->assertSame(3, $model->trials[0]->turns);
  }

  public function testAllPassingMeetsThreshold(): void {
    $config = $this->load("llm:\n  trials: 2\n  tasks:\n    - name: invoked\n      prompt: Build it\n");

    $report = $this->suite($this->pool([self::PASS_TRANSCRIPT, self::PASS_TRANSCRIPT]))->run($config, []);

    $this->assertTrue($report->skills[0]->tasks[0]->models[0]->passed());
    $this->assertFalse($report->failed());
  }

  public function testAgentFailureFoldsInFailingCheck(): void {
    $config = $this->load("llm:\n  trials: 1\n  tasks:\n    - name: invoked\n      prompt: Build it\n");

    $report = $this->suite($this->pool([[1, self::PASS_TRANSCRIPT]]))->run($config, []);

    $trial = $report->skills[0]->tasks[0]->models[0]->trials[0];
    $this->assertFalse($trial->pass);
    $this->assertSame(LlmSuite::CHECK_AGENT, $trial->checks[0]->id);
    $this->assertStringContainsString('exited with code 1', $trial->checks[0]->message);
  }

  public function testTimeoutIsReportedAsAgentFailure(): void {
    $config = $this->load("llm:\n  trials: 1\n  tasks:\n    - name: invoked\n      prompt: Build it\n");

    $report = $this->suite($this->pool([[ProcessPool::TIMEOUT_EXIT, '']]))->run($config, []);

    $trial = $report->skills[0]->tasks[0]->models[0]->trials[0];
    $this->assertFalse($trial->pass);
    $this->assertStringContainsString('timed out', $trial->checks[0]->message);
  }

  public function testCustomChecksAreAsserted(): void {
    $config = $this->load("llm:\n  trials: 1\n  tasks:\n    - name: invoked\n      prompt: Build it\n  checks:\n    - name: board\n      run: check.sh\n");
    $check_runner = static fn(): array => [1, ''];

    $report = $this->suite($this->pool([self::PASS_TRANSCRIPT]), $check_runner)->run($config, []);

    $trial = $report->skills[0]->tasks[0]->models[0]->trials[0];
    $this->assertFalse($trial->pass);
    $ids = array_map(static fn(CheckResult $check): string => $check->id, $trial->checks);
    $this->assertContains('check.board', $ids);
  }

  public function testArtifactsCarryTranscripts(): void {
    $config = $this->load("llm:\n  trials: 1\n  tasks:\n    - name: invoked\n      prompt: Build it\n");

    $report = $this->suite($this->pool([self::PASS_TRANSCRIPT]))->run($config, []);

    $artifacts = $report->artifacts();
    $this->assertContains(self::PASS_TRANSCRIPT, $artifacts);
    $this->assertArrayHasKey('artifacts/alpha__invoked__haiku__t1.jsonl', $artifacts);
  }

  public function testParallelBatchingRunsEveryTrial(): void {
    $config = $this->load("llm:\n  trials: 3\n  tasks:\n    - name: invoked\n      prompt: Build it\n");

    $report = $this->suite($this->pool([self::PASS_TRANSCRIPT, self::PASS_TRANSCRIPT, self::PASS_TRANSCRIPT]), NULL, 2)->run($config, []);

    $trials = $report->skills[0]->tasks[0]->models[0]->trials;
    $this->assertCount(3, $trials);
    $this->assertSame([1, 2, 3], array_map(static fn(TrialResult $trial): int => $trial->number, $trials));
  }

  public function testTaskGlobSelectsSubset(): void {
    $config = $this->load("llm:\n  trials: 1\n  tasks:\n    - name: invoked\n      prompt: A\n    - name: discovery\n      prompt: B\n");

    $report = $this->suite($this->pool([self::PASS_TRANSCRIPT]))->run($config, ['invoked']);

    $this->assertCount(1, $report->skills[0]->tasks);
    $this->assertSame('invoked', $report->skills[0]->tasks[0]->task);
  }

  public function testNoTasksThrows(): void {
    $config = $this->load('');

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('no llm tasks are declared');

    $this->suite($this->pool([]))->run($config, []);
  }

  public function testNoMatchingTaskGlobThrows(): void {
    $config = $this->load("llm:\n  tasks:\n    - name: invoked\n      prompt: A\n");

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('no llm tasks matched --task nope');

    $this->suite($this->pool([]))->run($config, ['nope']);
  }

  public function testTaskWithoutPromptThrows(): void {
    $config = $this->load("llm:\n  tasks:\n    - name: invoked\n");

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage("llm task 'invoked' requires a 'prompt'");

    $this->suite($this->pool([]))->run($config, []);
  }

  public function testTaskWithoutNameThrows(): void {
    $config = $this->load("llm:\n  tasks:\n    - prompt: no name here\n");

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage("an llm task requires a 'name'");

    $this->suite($this->pool([]))->run($config, []);
  }

  public function testNoModelsConfiguredThrows(): void {
    $this->root = $this->buildRepo("version: \"1\"\n", "version: \"1\"\nllm:\n  tasks:\n    - name: invoked\n      prompt: A\n");
    $config = (new ConfigLoader($this->root))->load();

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('no model configured');

    $this->suite($this->pool([]))->run($config, []);
  }

  /**
   * Builds an LlmSuite over the temp repo with an injected pool.
   *
   * @param \Closure $pool
   *   The injected process pool.
   * @param \Closure|null $check_runner
   *   An optional injected custom-check runner.
   * @param int $parallel
   *   The concurrency.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\LlmSuite
   *   The suite.
   */
  protected function suite(\Closure $pool, ?\Closure $check_runner = NULL, int $parallel = 1): LlmSuite {
    return new LlmSuite($this->root, 'stub', $parallel, 300.0, $pool, NULL, $check_runner, $this->root . '/.artifacts/tmp/ws');
  }

  /**
   * Builds a stateful fake pool that returns queued outcomes in call order.
   *
   * @param array<int, string|array{int, string}> $outcomes
   *   The per-trial outcomes: a transcript string (exit 0) or an
   *   `[exit, transcript]` pair.
   *
   * @return \Closure
   *   The pool closure.
   */
  protected function pool(array $outcomes): \Closure {
    $queue = array_map(static fn($outcome): array => is_array($outcome) ? $outcome : [0, $outcome], $outcomes);
    $index = 0;

    return function (array $commands) use ($queue, &$index): array {
      $results = [];

      foreach (array_keys($commands) as $key) {
        [$exit, $stdout] = $queue[$index];
        $index++;
        $results[$key] = [$exit, $stdout, 5];
      }

      return $results;
    };
  }

  /**
   * Loads a config for the temp repo built from an alpha eval.yaml tail.
   *
   * @param string $tail
   *   The eval sections appended after the version and contract.
   *
   * @return \AlexSkrypnyk\SkillTest\Config\LoadedConfig
   *   The loaded configuration.
   */
  protected function load(string $tail): LoadedConfig {
    $repo = "version: \"1\"\nmodels:\n  aliases:\n    haiku: claude-haiku-4-5\n  default: haiku\n";
    $this->root = $this->buildRepo($repo, "version: \"1\"\n" . self::CONTRACT . $tail);

    return (new ConfigLoader($this->root))->load();
  }

  /**
   * Writes a temp repo with the alpha skill.
   *
   * @param string $repo
   *   The `skilltest.yml` content.
   * @param string $eval
   *   The alpha `eval.yaml` content.
   *
   * @return string
   *   The repository root.
   */
  protected function buildRepo(string $repo, string $eval): string {
    $root = dirname(__DIR__, 3) . '/.artifacts/tmp/llmsuite-' . getmypid() . '-' . uniqid();
    mkdir($root . '/skills/alpha', 0777, TRUE);
    file_put_contents($root . '/skilltest.yml', $repo);
    file_put_contents($root . '/skills/alpha/SKILL.md', "---\nname: alpha\ndescription: A clean well-formed skill for tests.\n---\n# Body\n");
    file_put_contents($root . '/skills/alpha/eval.yaml', $eval);

    return $root;
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
