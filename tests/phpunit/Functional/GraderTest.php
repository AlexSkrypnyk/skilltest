<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Grade\Grader;
use AlexSkrypnyk\SkillTest\Judge\Judge;
use AlexSkrypnyk\SkillTest\Tests\Traits\ResultsDocumentTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class GraderTest.
 *
 * Functional test for the offline re-score engine: contract re-evaluation from
 * saved transcripts, runtime-failure preservation, the minimal-model recompute,
 * and re-judging through an injected judge seam so no agent is spawned.
 */
#[CoversClass(Grader::class)]
final class GraderTest extends TestCase {

  use ResultsDocumentTrait;

  /**
   * The base contract every helper eval declares.
   */
  protected const string CONTRACT = "contract:\n  tools:\n    allowed: [Bash]\n    required: [Bash]\n  commands:\n    forbidden:\n      no push: '\\bgit\\s+push\\b'\n";

  /**
   * A transcript that satisfies the contract.
   */
  protected const string PASS = '{"type":"tool_use","name":"Bash","input":{"command":"harness build"}}' . "\n" . '{"type":"result","result":"done"}' . "\n";

  /**
   * A transcript that violates the forbidden-command contract.
   */
  protected const string FAIL = '{"type":"tool_use","name":"Bash","input":{"command":"git push origin main"}}' . "\n" . '{"type":"result","result":"pushed"}' . "\n";

  /**
   * The temporary repository root.
   */
  protected string $tempDir = '';

  /**
   * The run directory transcript artifacts resolve against.
   */
  protected string $runDir = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    putenv(ConfigLoader::ENV_CONFIG);
    $this->tempDir = dirname(__DIR__, 3) . '/.artifacts/tmp/grader-' . getmypid() . '-' . uniqid();
    $this->runDir = $this->tempDir . '/run';
    mkdir($this->runDir . '/artifacts', 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv(ConfigLoader::ENV_CONFIG);

    if ($this->tempDir !== '' && is_dir($this->tempDir)) {
      $this->remove($this->tempDir);
    }

    parent::tearDown();
  }

  public function testContractTighteningFlipsTrialToFailing(): void {
    $config = $this->config();
    $this->artifact('t1.jsonl', self::FAIL);
    $document = $this->document([$this->trial(1, TRUE, 'artifacts/t1.jsonl')]);

    $result = (new Grader($this->tempDir))->rescore($document, $config, $this->runDir);

    $this->assertSame(1, $result->trialsRescored);
    $this->assertSame(1, $result->newlyFailing);
    $this->assertSame(0, $result->newlyPassing);
    $this->assertTrue($result->changed());
    $this->assertFalse($this->documentTrialPass($result->document));
    $this->assertNull($this->documentMinimalModel($result->document));
    $this->assertEqualsWithDelta(0.0, $this->documentPassRate($result->document), PHP_FLOAT_EPSILON);
  }

  public function testCleanTranscriptStaysPassing(): void {
    $config = $this->config();
    $this->artifact('t1.jsonl', self::PASS);
    $document = $this->document([$this->trial(1, TRUE, 'artifacts/t1.jsonl')]);

    $result = (new Grader($this->tempDir))->rescore($document, $config, $this->runDir);

    $this->assertSame(0, $result->newlyFailing);
    $this->assertFalse($result->changed());
    $this->assertTrue($this->documentTrialPass($result->document));
  }

  public function testFailingTrialNewlyPasses(): void {
    $config = $this->config();
    $this->artifact('t1.jsonl', self::PASS);
    $document = $this->document([$this->trial(1, FALSE, 'artifacts/t1.jsonl')]);

    $result = (new Grader($this->tempDir))->rescore($document, $config, $this->runDir);

    $this->assertSame(1, $result->newlyPassing);
    $this->assertTrue($this->documentTrialPass($result->document));
  }

  public function testRuntimeFailureIsPreserved(): void {
    $config = $this->config();
    $this->artifact('t1.jsonl', self::PASS);
    $contract = [['check' => 'live.agent', 'label' => 'agent run', 'pass' => FALSE, 'evidence' => '', 'message' => 'agent run exited with code 3.']];
    $document = $this->document([$this->trial(1, FALSE, 'artifacts/t1.jsonl', $contract)]);

    $result = (new Grader($this->tempDir))->rescore($document, $config, $this->runDir);

    $this->assertSame(0, $result->newlyPassing, 'A runtime failure the transcript cannot reproduce is not resurrected.');
    $this->assertFalse($this->documentTrialPass($result->document));
  }

  public function testUnconfiguredSkillWithTasksIsNoted(): void {
    $config = $this->config();
    $this->artifact('t1.jsonl', self::FAIL);
    $document = $this->document([$this->trial(1, TRUE, 'artifacts/t1.jsonl')], skill: 'ghost');

    $result = (new Grader($this->tempDir))->rescore($document, $config, $this->runDir);

    $this->assertSame(0, $result->trialsRescored);
    $this->assertContains("skill 'ghost' is not in the current config; left unchanged.", $result->notes);
  }

  public function testUnconfiguredDeterministicSkillIsSilent(): void {
    $config = $this->config();
    $document = $this->wrap([['skill' => 'ghost', 'path' => 'skills/ghost', 'deterministic' => ['structure' => [['check' => 'structure.x', 'pass' => TRUE]], 'security' => [], 'transcript' => []]]]);

    $result = (new Grader($this->tempDir))->rescore($document, $config, $this->runDir);

    $this->assertSame([], $result->notes);
  }

  public function testTrialWithoutTranscriptIsNoted(): void {
    $config = $this->config();
    $trial = ['trial' => 1, 'pass' => TRUE, 'contract' => [], 'judge' => []];
    $document = $this->document([$trial]);

    $result = (new Grader($this->tempDir))->rescore($document, $config, $this->runDir);

    $this->assertSame(0, $result->trialsRescored);
    $this->assertContains('a trial carries no transcript artifact; left unchanged.', $result->notes);
  }

  public function testStoredJudgeVerdictBlocksWithoutRejudge(): void {
    $config = $this->config(rubric: TRUE);
    $this->artifact('t1.jsonl', self::PASS);
    $judge = [['criterion' => 1, 'pass' => FALSE, 'unknown' => FALSE]];
    $document = $this->document([$this->trial(1, TRUE, 'artifacts/t1.jsonl', [], $judge)]);

    $result = (new Grader($this->tempDir))->rescore($document, $config, $this->runDir);

    $this->assertFalse($this->documentTrialPass($result->document), 'A stored failing criterion still blocks.');
  }

  public function testRejudgeReplacesStoredVerdict(): void {
    $config = $this->config(rubric: TRUE);
    $this->artifact('t1.jsonl', self::PASS);
    $judge = new Judge('claude', fn(string $command, string $cwd): array => [0, '{"criteria":[{"id":1,"pass":false}],"reasoning":"no"}']);
    $stored = [['criterion' => 1, 'pass' => TRUE, 'unknown' => FALSE]];
    $document = $this->document([$this->trial(1, TRUE, 'artifacts/t1.jsonl', [], $stored)]);

    $result = (new Grader($this->tempDir, $judge))->rescore($document, $config, $this->runDir);

    // The stored verdict passed, so a now-failing trial proves re-judging ran.
    $this->assertFalse($this->documentTrialPass($result->document), 'Re-judging failed the criterion and blocked the trial.');
  }

  public function testRejudgeFailureKeepsStoredVerdict(): void {
    $config = $this->config(rubric: TRUE);
    $this->artifact('t1.jsonl', self::PASS);
    $judge = new Judge('claude', fn(string $command, string $cwd): array => [1, 'boom']);
    $stored = [['criterion' => 1, 'pass' => TRUE, 'unknown' => FALSE]];
    $document = $this->document([$this->trial(1, TRUE, 'artifacts/t1.jsonl', [], $stored)]);

    $result = (new Grader($this->tempDir, $judge))->rescore($document, $config, $this->runDir);

    $this->assertTrue($this->documentTrialPass($result->document), 'The stored passing verdict is kept.');
    $this->assertNotEmpty($result->notes);
  }

  public function testRejudgeWithoutJudgeModelKeepsStored(): void {
    $config = $this->config(rubric: TRUE, models: FALSE);
    $this->artifact('t1.jsonl', self::PASS);
    $judge = new Judge('claude', fn(string $command, string $cwd): array => [0, '{"criteria":[{"id":1,"pass":false}]}']);
    $stored = [['criterion' => 1, 'pass' => TRUE, 'unknown' => FALSE]];
    $document = $this->document([$this->trial(1, TRUE, 'artifacts/t1.jsonl', [], $stored)]);

    $result = (new Grader($this->tempDir, $judge))->rescore($document, $config, $this->runDir);

    $this->assertTrue($this->documentTrialPass($result->document));
    $this->assertContains('a judged trial could not be re-judged (task or judge model missing); kept the stored verdict.', $result->notes);
  }

  public function testMinimalModelRecomputedAcrossLadder(): void {
    $config = $this->config();
    $this->artifact('haiku.jsonl', self::FAIL);
    $this->artifact('sonnet.jsonl', self::PASS);
    $models = [
      ['model' => 'claude-haiku', 'alias' => 'haiku', 'trials' => [$this->trial(1, TRUE, 'artifacts/haiku.jsonl')], 'pass_rate' => 1.0],
      ['model' => 'claude-sonnet', 'alias' => 'sonnet', 'trials' => [$this->trial(1, TRUE, 'artifacts/sonnet.jsonl')], 'pass_rate' => 1.0],
    ];
    $document = $this->documentWithModels($models, 'haiku');

    $result = (new Grader($this->tempDir))->rescore($document, $config, $this->runDir);

    $this->assertSame('sonnet', $this->documentMinimalModel($result->document), 'Haiku now fails, so the minimal model climbs to sonnet.');
  }

  public function testAbsoluteTranscriptPathResolves(): void {
    $config = $this->config();
    $absolute = $this->runDir . '/artifacts/abs.jsonl';
    file_put_contents($absolute, self::FAIL);
    $document = $this->document([$this->trial(1, TRUE, $absolute)]);

    $result = (new Grader($this->tempDir))->rescore($document, $config, $this->runDir);

    $this->assertSame(1, $result->newlyFailing);
  }

  public function testTaskWithoutVerdictStillRescoresRates(): void {
    $config = $this->config();
    $this->artifact('t1.jsonl', self::FAIL);
    $models = [['model' => 'claude-haiku-4-5', 'alias' => 'haiku', 'trials' => [$this->trial(1, TRUE, 'artifacts/t1.jsonl')], 'pass_rate' => 1.0]];
    $document = $this->documentWithModels($models, NULL, verdict: FALSE);

    $result = (new Grader($this->tempDir))->rescore($document, $config, $this->runDir);

    $this->assertEqualsWithDelta(0.0, $this->documentPassRate($result->document), PHP_FLOAT_EPSILON);
    $this->assertArrayNotHasKey('verdict', $this->documentLlm($result->document));
  }

  public function testRecomputesFailuresAcrossDimensions(): void {
    $config = $this->config();
    $this->artifact('t1.jsonl', self::FAIL);
    $models = [['model' => 'claude-haiku-4-5', 'alias' => 'haiku', 'trials' => [$this->trial(1, TRUE, 'artifacts/t1.jsonl')], 'pass_rate' => 1.0]];
    $document = $this->documentWithModels($models, 'haiku', deterministic: ['structure' => [['check' => 'structure.x', 'pass' => FALSE]], 'security' => [], 'transcript' => []]);
    $document['hooks'] = [['check' => 'hooks.h', 'pass' => FALSE]];
    $document['coverage'] = ['violations' => [['check' => 'coverage.eval-exists', 'pass' => FALSE]]];

    $result = (new Grader($this->tempDir))->rescore($document, $config, $this->runDir);

    // 1 structure + 1 hook + 1 coverage + 1 failing llm verdict.
    $this->assertSame(4, $this->documentFailures($result->document));
  }

  public function testConfiguredDeterministicSkillIsUnchanged(): void {
    $config = $this->config();
    $document = $this->wrap([['skill' => 'alpha', 'path' => 'skills/alpha', 'deterministic' => ['structure' => [['check' => 'structure.x', 'pass' => TRUE]], 'security' => [], 'transcript' => []]]]);

    $result = (new Grader($this->tempDir))->rescore($document, $config, $this->runDir);

    $this->assertSame(0, $result->trialsRescored);
    $this->assertSame([], $result->notes);
  }

  public function testRejudgeUnknownTaskKeepsStored(): void {
    $config = $this->config(rubric: TRUE);
    $this->artifact('t1.jsonl', self::PASS);
    $judge = new Judge('claude', fn(string $command, string $cwd): array => [0, '{"criteria":[{"id":1,"pass":false}]}']);
    $stored = [['criterion' => 1, 'pass' => TRUE, 'unknown' => FALSE]];
    $document = $this->document([$this->trial(1, TRUE, 'artifacts/t1.jsonl', [], $stored)], task: 'ghost-task');

    $result = (new Grader($this->tempDir, $judge))->rescore($document, $config, $this->runDir);

    $this->assertTrue($this->documentTrialPass($result->document), 'A task no longer in the config keeps its stored verdict.');
    $this->assertContains('a judged trial could not be re-judged (task or judge model missing); kept the stored verdict.', $result->notes);
  }

  public function testEmptyTrialSetRatesToZero(): void {
    $config = $this->config();
    $models = [['model' => 'claude-haiku-4-5', 'alias' => 'haiku', 'trials' => [], 'pass_rate' => 1.0]];
    $document = $this->documentWithModels($models, NULL);

    $result = (new Grader($this->tempDir))->rescore($document, $config, $this->runDir);

    $this->assertSame(0, $result->trialsRescored);
    $this->assertEqualsWithDelta(0.0, $this->documentPassRate($result->document), PHP_FLOAT_EPSILON);
    $this->assertNull($this->documentMinimalModel($result->document));
  }

  public function testMissingTotalsIsLeftAlone(): void {
    $config = $this->config();
    $this->artifact('t1.jsonl', self::PASS);
    $document = $this->document([$this->trial(1, TRUE, 'artifacts/t1.jsonl')]);
    unset($document['totals']);

    $result = (new Grader($this->tempDir))->rescore($document, $config, $this->runDir);

    $this->assertArrayNotHasKey('totals', $result->document);
  }

  /**
   * Loads a config from a temp repo with one skill and one llm task.
   *
   * @param bool $rubric
   *   Whether the eval declares a judge rubric.
   * @param bool $models
   *   Whether the repo sets any models (FALSE leaves the judge model unset).
   *
   * @return \AlexSkrypnyk\SkillTest\Config\LoadedConfig
   *   The loaded configuration.
   */
  protected function config(bool $rubric = FALSE, bool $models = TRUE): LoadedConfig {
    mkdir($this->tempDir . '/skills/alpha', 0777, TRUE);

    $repo = "version: \"1\"\n";
    if ($models) {
      $repo .= "models:\n  aliases:\n    haiku: claude-haiku-4-5\n  default: haiku\n  judge: haiku\n";
    }
    file_put_contents($this->tempDir . '/skilltest.yml', $repo);
    file_put_contents($this->tempDir . '/skills/alpha/SKILL.md', "---\nname: alpha\ndescription: A clean well-formed skill for tests.\n---\n# Body\n");

    $eval = "version: \"1\"\n" . self::CONTRACT . "llm:\n  tasks:\n    - name: invoked\n      prompt: Build the thing\n";
    if ($rubric) {
      $eval .= "  judge:\n    rubric:\n      - Did it build?\n";
    }
    file_put_contents($this->tempDir . '/skills/alpha/eval.yaml', $eval);

    return (new ConfigLoader($this->tempDir))->load();
  }

  /**
   * Writes a transcript artifact under the run directory.
   *
   * @param string $name
   *   The artifact file name under `artifacts/`.
   * @param string $content
   *   The transcript content.
   */
  protected function artifact(string $name, string $content): void {
    file_put_contents($this->runDir . '/artifacts/' . $name, $content);
  }

  /**
   * Builds a trial row.
   *
   * @param int $number
   *   The trial number.
   * @param bool $pass
   *   The stored pass verdict.
   * @param string $transcript
   *   The transcript reference.
   * @param array<int, array<mixed>> $contract
   *   The stored contract rows.
   * @param array<int, array<mixed>> $judge
   *   The stored judge criteria.
   *
   * @return array<string, mixed>
   *   The trial row.
   */
  protected function trial(int $number, bool $pass, string $transcript, array $contract = [], array $judge = []): array {
    return ['trial' => $number, 'pass' => $pass, 'contract' => $contract, 'judge' => $judge, 'transcript' => $transcript];
  }

  /**
   * Builds a results document with one skill and one single-model task.
   *
   * @param array<int, array<mixed>> $trials
   *   The trial rows.
   * @param string $skill
   *   The skill name.
   * @param string $task
   *   The task name.
   *
   * @return array<string, mixed>
   *   The document.
   */
  protected function document(array $trials, string $skill = 'alpha', string $task = 'invoked'): array {
    $models = [['model' => 'claude-haiku-4-5', 'alias' => 'haiku', 'trials' => $trials, 'pass_rate' => 1.0]];

    return $this->documentWithModels($models, 'haiku', $skill, $task);
  }

  /**
   * Builds a results document with an explicit model ladder.
   *
   * @param array<int, array<mixed>> $models
   *   The model entries.
   * @param string|null $minimal
   *   The minimal-model verdict alias.
   * @param string $skill
   *   The skill name.
   * @param string $task
   *   The task name.
   * @param bool $verdict
   *   Whether to include the llm verdict block.
   * @param array<string, mixed> $deterministic
   *   The skill's deterministic block, when it carries one.
   *
   * @return array<string, mixed>
   *   The document.
   */
  protected function documentWithModels(array $models, ?string $minimal, string $skill = 'alpha', string $task = 'invoked', bool $verdict = TRUE, array $deterministic = []): array {
    $llm = ['tasks' => [['task' => $task, 'models' => $models]]];

    if ($verdict) {
      $llm['verdict'] = ['minimal_model' => $minimal, 'threshold' => 0.8, 'trials' => 1];
    }

    $skill_entry = ['skill' => $skill, 'path' => 'skills/' . $skill, 'llm' => $llm];

    if ($deterministic !== []) {
      $skill_entry['deterministic'] = $deterministic;
    }

    return $this->wrap([$skill_entry]);
  }

  /**
   * Wraps skill entries in a full results document envelope.
   *
   * @param array<int, array<mixed>> $skills
   *   The skill entries.
   *
   * @return array<string, mixed>
   *   The document.
   */
  protected function wrap(array $skills): array {
    return [
      'version' => '1',
      'tool' => ['name' => 'skilltest', 'version' => 'development'],
      'run' => ['id' => 'st-1', 'started' => '2026-07-09T00:00:00+00:00', 'duration_ms' => 1, 'command' => 'llm', 'environment' => 'host'],
      'skills' => $skills,
      'hooks' => [],
      'coverage' => ['violations' => []],
      'totals' => ['checks' => 1, 'failures' => 0, 'trials' => 1, 'tokens' => ['in' => 0, 'out' => 0], 'cost_usd' => 0.0],
    ];
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
