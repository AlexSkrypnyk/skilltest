<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Live\HostEnvironment;
use AlexSkrypnyk\SkillTest\Live\Lifecycle;
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

  /**
   * A rubric-declaring llm tail with two binary criteria and one task.
   */
  protected const string RUBRIC_TAIL = "llm:\n  trials: 1\n  judge:\n    rubric:\n      - names the change\n      - lists the files\n  tasks:\n    - name: invoked\n      prompt: Build it\n";

  public function testJudgePassingVerdictKeepsTrialGreen(): void {
    $config = $this->loadJudged(self::RUBRIC_TAIL);
    $verdict = '{"criteria":[{"id":1,"pass":true},{"id":2,"pass":true}],"reasoning":"ok"}';

    $report = $this->suite($this->pool([self::PASS_TRANSCRIPT]), NULL, 1, $this->verdicts([$verdict]))->run($config, []);

    $trial = $report->skills[0]->tasks[0]->models[0]->trials[0];
    $this->assertTrue($trial->pass);
    $this->assertSame('claude-opus-4-8', $trial->judgeModel);
    $this->assertCount(2, $trial->criteria);
    $this->assertNotContains('judge.verdict', $this->checkIds($trial));
    $this->assertNotContains('judge.criteria', $this->checkIds($trial));
  }

  public function testJudgeFailingCriteriaFailTheTrial(): void {
    $config = $this->loadJudged(self::RUBRIC_TAIL);
    $verdict = '{"criteria":[{"id":1,"pass":true},{"id":2,"pass":false}],"reasoning":"second missing"}';

    $report = $this->suite($this->pool([self::PASS_TRANSCRIPT]), NULL, 1, $this->verdicts([$verdict]))->run($config, []);

    $trial = $report->skills[0]->tasks[0]->models[0]->trials[0];
    $this->assertFalse($trial->pass);
    $this->assertContains('judge.criteria', $this->checkIds($trial));
    $this->assertCount(2, $trial->criteria);
  }

  public function testMalformedVerdictFailsTheTrial(): void {
    $config = $this->loadJudged(self::RUBRIC_TAIL);

    $report = $this->suite($this->pool([self::PASS_TRANSCRIPT]), NULL, 1, $this->verdicts(['I cannot tell from the transcript.']))->run($config, []);

    $trial = $report->skills[0]->tasks[0]->models[0]->trials[0];
    $this->assertFalse($trial->pass);
    $this->assertContains('judge.verdict', $this->checkIds($trial));
    $this->assertSame([], $trial->criteria);
    $this->assertSame('claude-opus-4-8', $trial->judgeModel);
  }

  public function testJudgeProcessFailureFailsTheTrial(): void {
    $config = $this->loadJudged(self::RUBRIC_TAIL);

    $report = $this->suite($this->pool([self::PASS_TRANSCRIPT]), NULL, 1, $this->verdicts([[1, '']]))->run($config, []);

    $trial = $report->skills[0]->tasks[0]->models[0]->trials[0];
    $this->assertFalse($trial->pass);
    $this->assertContains('judge.verdict', $this->checkIds($trial));
  }

  public function testAbstentionBlocksUnderTheDefaultPolicy(): void {
    $config = $this->loadJudged(self::RUBRIC_TAIL);
    $verdict = '{"criteria":[{"id":1,"pass":true},{"id":2,"pass":false,"unknown":true}],"reasoning":"cannot tell"}';

    $report = $this->suite($this->pool([self::PASS_TRANSCRIPT]), NULL, 1, $this->verdicts([$verdict]))->run($config, []);

    $trial = $report->skills[0]->tasks[0]->models[0]->trials[0];
    $this->assertFalse($trial->pass);
    $this->assertContains('judge.criteria', $this->checkIds($trial));
    $this->assertSame(1, $trial->toArray()['unknowns']);
  }

  public function testAbstentionPassesUnderIgnorePolicybutIsStillReported(): void {
    $tail = "llm:\n  trials: 1\n  judge:\n    unknown: ignore\n    rubric:\n      - names the change\n      - lists the files\n  tasks:\n    - name: invoked\n      prompt: Build it\n";
    $config = $this->loadJudged($tail);
    $verdict = '{"criteria":[{"id":1,"pass":true},{"id":2,"pass":false,"unknown":true}],"reasoning":"cannot tell"}';

    $report = $this->suite($this->pool([self::PASS_TRANSCRIPT]), NULL, 1, $this->verdicts([$verdict]))->run($config, []);

    $trial = $report->skills[0]->tasks[0]->models[0]->trials[0];
    $this->assertTrue($trial->pass);
    $this->assertSame(1, $trial->toArray()['unknowns']);
    $this->assertNotContains('judge.criteria', $this->checkIds($trial));
  }

  public function testJudgeModelStaysFixedWhenModelsVary(): void {
    $config = $this->loadJudged(self::RUBRIC_TAIL, ['models' => 'haiku,sonnet']);
    $verdict = '{"criteria":[{"id":1,"pass":true},{"id":2,"pass":true}],"reasoning":"ok"}';

    $report = $this->suite($this->pool([self::PASS_TRANSCRIPT, self::PASS_TRANSCRIPT]), NULL, 1, $this->verdicts([$verdict, $verdict]))->run($config, []);

    $models = $report->skills[0]->tasks[0]->models;
    $this->assertSame('claude-haiku-4-5', $models[0]->model);
    $this->assertSame('claude-sonnet-5', $models[1]->model);
    $this->assertSame('claude-opus-4-8', $models[0]->trials[0]->judgeModel);
    $this->assertSame('claude-opus-4-8', $models[1]->trials[0]->judgeModel);
  }

  public function testJudgeIsNotSpentOnFailedAgentRun(): void {
    $config = $this->loadJudged(self::RUBRIC_TAIL);
    $called = FALSE;
    $judge = function () use (&$called): array {
      $called = TRUE;

      return [0, '{"criteria":[{"id":1,"pass":true},{"id":2,"pass":true}]}'];
    };

    $report = $this->suite($this->pool([[1, self::PASS_TRANSCRIPT]]), NULL, 1, $judge)->run($config, []);

    $trial = $report->skills[0]->tasks[0]->models[0]->trials[0];
    $this->assertFalse($called);
    $this->assertFalse($trial->pass);
    $this->assertSame([], $trial->criteria);
    $this->assertSame('claude-opus-4-8', $trial->judgeModel);
    $this->assertContains(LlmSuite::CHECK_AGENT, $this->checkIds($trial));
    $this->assertNotContains('judge.verdict', $this->checkIds($trial));
  }

  public function testRubricWithoutJudgeModelThrows(): void {
    $this->root = $this->buildRepo("version: \"1\"\n", "version: \"1\"\n" . self::CONTRACT . self::RUBRIC_TAIL);
    $config = (new ConfigLoader($this->root))->load(['models' => 'claude-haiku-4-5']);

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('no judge model');

    $this->suite($this->pool([]))->run($config, []);
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

  public function testLifecycleHooksFireInOrderWithSubstitutedVariables(): void {
    // 'workdir' is a structural input, not a template variable, so it is
    // skipped while 'site' becomes '{{ vars.site }}'.
    $config = $this->load("llm:\n  trials: 1\n  tasks:\n    - name: invoked\n      prompt: Build it\n      inputs:\n        workdir: sub\n        site: example\n");
    $log = $this->root . '/hooks.log';
    $target = escapeshellarg($log);
    $lifecycle = new Lifecycle($this->root, [
      'before-run' => [['command' => 'echo before-run >> ' . $target]],
      'before-task' => [['command' => 'echo "before-task {{ task }} {{ trial }} {{ model }} {{ vars.site }}" >> ' . $target]],
      'after-task' => [['command' => 'echo after-task >> ' . $target]],
      'after-run' => [['command' => 'echo after-run >> ' . $target]],
    ]);

    $this->suite($this->pool([self::PASS_TRANSCRIPT]), NULL, 1, NULL, $lifecycle)->run($config, []);

    $this->assertSame("before-run\nbefore-task invoked 1 claude-haiku-4-5 example\nafter-task\nafter-run\n", (string) file_get_contents($log));
  }

  public function testBeforeTaskFailureAbortsWithConfigError(): void {
    $config = $this->load("llm:\n  trials: 1\n  tasks:\n    - name: invoked\n      prompt: Build it\n");
    $lifecycle = new Lifecycle($this->root, ['before-task' => [['command' => 'exit 3', 'error-on-fail' => TRUE]]]);

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage("lifecycle before-task hook 'exit 3' failed with exit 3");

    $this->suite($this->pool([self::PASS_TRANSCRIPT]), NULL, 1, NULL, $lifecycle)->run($config, []);
  }

  public function testAfterTaskFailureWarnsButDoesNotFailTheRun(): void {
    $config = $this->load("llm:\n  trials: 1\n  tasks:\n    - name: invoked\n      prompt: Build it\n");
    $warnings = [];
    $warn = function (string $message) use (&$warnings): void {
      $warnings[] = $message;
    };
    $lifecycle = new Lifecycle($this->root, ['after-task' => [['command' => 'exit 1']]], NULL, $warn);

    $report = $this->suite($this->pool([self::PASS_TRANSCRIPT]), NULL, 1, NULL, $lifecycle)->run($config, []);

    $this->assertFalse($report->failed());
    $this->assertCount(1, $warnings);
    $this->assertStringContainsString("lifecycle after-task hook 'exit 1' failed", $warnings[0]);
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

  public function testNamelessTaskThrowsEvenUnderGlob(): void {
    $config = $this->load("llm:\n  tasks:\n    - prompt: no name here\n");

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage("an llm task requires a 'name'");

    $this->suite($this->pool([]))->run($config, ['other']);
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
   * @param \Closure|null $judge
   *   An optional injected judge runner.
   * @param \AlexSkrypnyk\SkillTest\Live\Lifecycle|null $lifecycle
   *   An optional lifecycle; defaults to one with no hooks.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\LlmSuite
   *   The suite.
   */
  protected function suite(\Closure $pool, ?\Closure $check_runner = NULL, int $parallel = 1, ?\Closure $judge = NULL, ?Lifecycle $lifecycle = NULL): LlmSuite {
    $environment = new HostEnvironment($this->root, $parallel, 300.0, $pool, NULL, $this->root . '/.artifacts/tmp/ws');

    return new LlmSuite($this->root, 'stub', $environment, $lifecycle ?? new Lifecycle($this->root, []), $parallel, 300.0, $check_runner, $judge);
  }

  /**
   * The check ids of a trial, in order.
   *
   * @param \AlexSkrypnyk\SkillTest\Live\TrialResult $trial
   *   The trial.
   *
   * @return string[]
   *   The check ids.
   */
  protected function checkIds(TrialResult $trial): array {
    return array_map(static fn(CheckResult $check): string => $check->id, $trial->checks);
  }

  /**
   * Builds a stateful fake judge that returns queued verdicts in call order.
   *
   * @param array<int, string|array{int, string}> $outcomes
   *   The per-call judge outcomes: a verdict string (exit 0) or an
   *   `[exit, verdict]` pair.
   *
   * @return \Closure
   *   The judge runner closure.
   */
  protected function verdicts(array $outcomes): \Closure {
    $queue = array_map(static fn(array|string $outcome): array => is_array($outcome) ? $outcome : [0, $outcome], $outcomes);
    $index = 0;

    return function (string $command, string $cwd) use ($queue, &$index): array {
      $outcome = $queue[$index];
      $index++;

      return $outcome;
    };
  }

  /**
   * Loads a config whose skill declares a rubric and a distinct judge model.
   *
   * @param string $tail
   *   The `llm` sections appended after the version and contract.
   * @param array<string, mixed> $cli
   *   CLI overrides, e.g. a `models` list.
   *
   * @return \AlexSkrypnyk\SkillTest\Config\LoadedConfig
   *   The loaded configuration.
   */
  protected function loadJudged(string $tail, array $cli = []): LoadedConfig {
    $repo = "version: \"1\"\nmodels:\n  aliases:\n    haiku: claude-haiku-4-5\n    sonnet: claude-sonnet-5\n    opus: claude-opus-4-8\n  default: haiku\n  judge: opus\n";
    $this->root = $this->buildRepo($repo, "version: \"1\"\n" . self::CONTRACT . $tail);

    return (new ConfigLoader($this->root))->load($cli);
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
    $queue = array_map(static fn(array|string $outcome): array => is_array($outcome) ? $outcome : [0, $outcome], $outcomes);
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
