<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use AlexSkrypnyk\SkillTest\Config\LoadedSkill;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Live\ProcessPool;
use AlexSkrypnyk\SkillTest\Live\RecordResult;
use AlexSkrypnyk\SkillTest\Live\RecordRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class RecordRunnerFunctionalTest.
 *
 * Drives the single-trial record runner over a real temp repo with an injected
 * process pool, asserting the raw outcome, the assembled command, workspace
 * teardown, and error propagation without spending a token.
 */
#[CoversClass(RecordRunner::class)]
#[Group('live')]
final class RecordRunnerFunctionalTest extends TestCase {

  /**
   * A transcript the fake pool returns for a recorded run.
   */
  protected const string TRANSCRIPT = '{"type":"tool_use","name":"Bash","input":{"command":"harness build"}}' . "\n";

  /**
   * The workspace base the runner assembles trials under.
   */
  protected const string WS_BASE = '/.artifacts/tmp/record-ws';

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

  public function testRecordsOneTrialAndReturnsRawOutcome(): void {
    $skill = $this->skill("llm:\n  max-turns: 5\n  tasks:\n    - name: invoked\n      prompt: Build it\n");
    $captured = [];
    $runner = $this->runner($this->pool([[0, self::TRANSCRIPT]], $captured));

    $result = $runner->record($skill, $this->entry('invoked', 'Build it'), 'claude-haiku-4-5');

    $this->assertInstanceOf(RecordResult::class, $result);
    $this->assertSame(self::TRANSCRIPT, $result->transcript);
    $this->assertSame(0, $result->exitCode);
    $this->assertSame(5, $result->durationMs);

    $this->assertCount(1, $captured);
    $this->assertStringContainsString('-p ' . escapeshellarg('Build it'), $captured[0][0]);
    $this->assertStringContainsString('--model ' . escapeshellarg('claude-haiku-4-5'), $captured[0][0]);
    $this->assertStringContainsString('--max-turns 5', $captured[0][0]);
    $this->assertStringContainsString('--allowedTools ' . escapeshellarg('Bash'), $captured[0][0]);
  }

  public function testRunsTheAgentInsideTheWorkspaceAndTearsItDown(): void {
    $skill = $this->skill("llm:\n  tasks:\n    - name: invoked\n      prompt: Build it\n");
    $captured = [];
    $runner = $this->runner($this->pool([[0, self::TRANSCRIPT]], $captured));

    $runner->record($skill, $this->entry('invoked', 'Build it'), 'claude-haiku-4-5');

    $this->assertStringStartsWith($this->root . self::WS_BASE . '/ws-', $captured[0][1]);
    $this->assertSame([], glob($this->root . self::WS_BASE . '/ws-*') ?: [], 'The trial workspace should be cleaned up.');
  }

  public function testNonZeroExitIsCarriedThrough(): void {
    $skill = $this->skill("llm:\n  tasks:\n    - name: invoked\n      prompt: Build it\n");
    $captured = [];
    $runner = $this->runner($this->pool([[ProcessPool::TIMEOUT_EXIT, '']], $captured));

    $result = $runner->record($skill, $this->entry('invoked', 'Build it'), 'claude-haiku-4-5');

    $this->assertSame(ProcessPool::TIMEOUT_EXIT, $result->exitCode);
    $this->assertSame('', $result->transcript);
  }

  public function testMalformedInputsThrowAndStillCleanUp(): void {
    $skill = $this->skill("llm:\n  tasks:\n    - name: invoked\n      prompt: Build it\n");
    $captured = [];
    $runner = $this->runner($this->pool([[0, self::TRANSCRIPT]], $captured));
    $entry = ['name' => 'invoked', 'prompt' => 'Build it', 'task' => ['inputs' => ['repos' => [['dest' => 'x']]]]];

    try {
      $runner->record($skill, $entry, 'claude-haiku-4-5');
      $this->fail('Expected a ConfigException for the source-less repos entry.');
    }
    catch (ConfigException $config_exception) {
      $this->assertStringContainsString("a repos entry requires a 'source'", $config_exception->getMessage());
    }

    $this->assertSame([], $captured, 'A malformed input should never reach the agent.');
    $this->assertSame([], glob($this->root . self::WS_BASE . '/ws-*') ?: [], 'The workspace should be cleaned up even when assembly throws.');
  }

  /**
   * Builds a RecordRunner over the temp repo with an injected pool.
   *
   * @param \Closure $pool
   *   The injected process pool.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\RecordRunner
   *   The runner.
   */
  protected function runner(\Closure $pool): RecordRunner {
    return new RecordRunner($this->root, 'stub', 300.0, $pool, NULL, $this->root . self::WS_BASE);
  }

  /**
   * A validated task entry as the command hands the runner.
   *
   * @param string $name
   *   The task name.
   * @param string $prompt
   *   The task prompt.
   *
   * @return array{name: string, prompt: string, task: array<mixed>}
   *   The entry.
   */
  protected function entry(string $name, string $prompt): array {
    return ['name' => $name, 'prompt' => $prompt, 'task' => ['name' => $name, 'prompt' => $prompt]];
  }

  /**
   * Builds a fake pool that captures its commands and returns queued outcomes.
   *
   * @param array<int, array{int, string}> $outcomes
   *   The per-call `[exit, transcript]` outcomes.
   * @param array<int, array{0: string, 1: string}> $captured
   *   The captured `[command, cwd]` pairs, appended to in place.
   *
   * @return \Closure
   *   The pool closure.
   */
  protected function pool(array $outcomes, array &$captured): \Closure {
    $index = 0;

    return function (array $commands) use ($outcomes, &$index, &$captured): array {
      $results = [];

      foreach ($commands as $key => $command) {
        $captured[] = $command;
        [$exit, $stdout] = $outcomes[$index];
        $index++;
        $results[$key] = [$exit, $stdout, 5];
      }

      return $results;
    };
  }

  /**
   * Loads the alpha skill from a temp repo built from an eval.yaml tail.
   *
   * @param string $tail
   *   The eval sections appended after the version and contract.
   *
   * @return \AlexSkrypnyk\SkillTest\Config\LoadedSkill
   *   The loaded skill.
   */
  protected function skill(string $tail): LoadedSkill {
    $this->root = dirname(__DIR__, 3) . '/.artifacts/tmp/recordrunner-' . getmypid() . '-' . uniqid();
    mkdir($this->root . '/skills/alpha', 0777, TRUE);

    file_put_contents($this->root . '/skilltest.yml', "version: \"1\"\nmodels:\n  aliases:\n    haiku: claude-haiku-4-5\n  default: haiku\n");
    file_put_contents($this->root . '/skills/alpha/SKILL.md', "---\nname: alpha\ndescription: A clean well-formed skill for tests.\n---\n# Body\n");

    $contract = "contract:\n  tools:\n    allowed: [Bash]\n    required: [Bash]\n";
    file_put_contents($this->root . '/skills/alpha/eval.yaml', "version: \"1\"\n" . $contract . $tail);

    $skills = (new ConfigLoader($this->root))->load()->skills;

    return $skills[0];
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
