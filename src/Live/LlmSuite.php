<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Config\EffectiveConfig;
use AlexSkrypnyk\SkillTest\Config\Glob;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedSkill;
use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Contract\ContractChecker;
use AlexSkrypnyk\SkillTest\Contract\CustomCheck;
use AlexSkrypnyk\SkillTest\Contract\Transcript;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Judge\Judge;
use AlexSkrypnyk\SkillTest\Judge\JudgeException;
use AlexSkrypnyk\SkillTest\Judge\UnknownPolicy;
use AlexSkrypnyk\SkillTest\Live\Mcp\McpMock;
use AlexSkrypnyk\SkillTest\Live\Mcp\McpMockWiring;
use AlexSkrypnyk\SkillTest\Live\Mcp\SelfInvocation;

/**
 * Runs the live llm suite: workspaces, headless trials, and the same contract.
 *
 * For every selected skill, task, and model this assembles a fresh workspace
 * per trial, runs the skill headlessly, and grades the live transcript against
 * the identical contract the deterministic suite asserts - so one declaration
 * is enforced both from the recorded fixture on every push and behaviourally
 * from live runs. Trials for a model run through a bounded worker pool so
 * `--parallel` shortens wall-clock without changing the verdict, and every
 * workspace is torn down whether its trial passed, failed, or threw. A trial
 * passes only when every contract and custom check passes and the agent exited
 * cleanly within its timeout; a task passes on a model when its pass rate meets
 * the threshold, with no retries to mask a flaky skill. Trials run through an
 * injected {@see EnvironmentInterface} - where a trial runs and what it can
 * touch, never what passing means - and a {@see Lifecycle} brackets the run
 * and every trial with deterministic setup and teardown hooks; both, and the
 * check seam, are injectable so the whole orchestration is testable without a
 * real agent.
 */
final readonly class LlmSuite {

  /**
   * The check id a process-level agent failure renders under.
   */
  public const string CHECK_AGENT = 'live.agent';

  /**
   * The check id an unparseable or failed judge verdict renders under.
   */
  public const string CHECK_JUDGE = 'judge.verdict';

  /**
   * The check id a blocking judge rubric verdict renders under.
   */
  public const string CHECK_JUDGE_RUBRIC = 'judge.criteria';

  /**
   * The check id an unmatched or unknown mock tool call renders under.
   */
  public const string CHECK_MCP = 'live.mcp';

  /**
   * The check id an abstaining or failed responder renders under.
   */
  public const string CHECK_RESPONDER = 'live.responder';

  /**
   * The default per-trial wall-clock budget, in seconds.
   */
  public const float DEFAULT_TIMEOUT = 300.0;

  /**
   * The environment variable overriding the per-trial timeout, in seconds.
   */
  public const string ENV_TIMEOUT = 'SKILLTEST_TRIAL_TIMEOUT';

  /**
   * The judge that scores a trial when its skill declares a rubric.
   */
  protected Judge $judge;

  /**
   * The responder that plays the user when a task declares one.
   */
  protected Responder $responder;

  /**
   * Constructs an LlmSuite.
   *
   * @param string $root
   *   The repository root.
   * @param string $binary
   *   The resolved agent binary or command prefix.
   * @param \AlexSkrypnyk\SkillTest\Live\EnvironmentInterface $environment
   *   The environment trials are assembled, run, and torn down in.
   * @param \AlexSkrypnyk\SkillTest\Live\Lifecycle $lifecycle
   *   The lifecycle hooks bracketing the run and every trial.
   * @param int $parallel
   *   The maximum number of workspaces assembled and run concurrently.
   * @param float $timeout
   *   The per-trial wall-clock budget, in seconds, for the timeout message.
   * @param \Closure|null $checkRunner
   *   An override for the custom-check process runner, for tests.
   * @param \Closure|null $judge_runner
   *   An override for the judge process runner, for tests.
   * @param \Closure|null $responder_runner
   *   An override for the responder process runner, for tests.
   * @param \AlexSkrypnyk\SkillTest\Live\TrialCache|null $cache
   *   The trial cache, or NULL to run every trial live without caching.
   */
  public function __construct(
    protected string $root,
    protected string $binary,
    protected EnvironmentInterface $environment,
    protected Lifecycle $lifecycle,
    protected int $parallel = 1,
    protected float $timeout = self::DEFAULT_TIMEOUT,
    protected ?\Closure $checkRunner = NULL,
    ?\Closure $judge_runner = NULL,
    ?\Closure $responder_runner = NULL,
    protected ?TrialCache $cache = NULL,
  ) {
    $this->judge = new Judge($binary, $judge_runner, $timeout);
    $this->responder = new Responder($binary, $responder_runner, $timeout);
  }

  /**
   * Runs the llm suite over the selected skills and tasks.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $config
   *   The loaded configuration, already narrowed to the selected skills.
   * @param string[] $task_globs
   *   The task-name globs; empty selects every declared task.
   * @param bool $stop_at_pass
   *   When TRUE, climb the ladder weakest first and stop each skill at the
   *   first model that passes every one of its tasks, leaving the stronger rows
   *   unrun; when FALSE, run the full matrix.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\LlmReport
   *   The aggregated run outcome.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the selection runs no tasks, a task is malformed, no model is
   *   configured, or a workspace cannot be assembled.
   */
  public function run(LoadedConfig $config, array $task_globs, bool $stop_at_pass = FALSE): LlmReport {
    // Resolve and validate the selection before any hook fires, so a
    // configuration error surfaces without disturbing external state.
    $selected = [];

    foreach ($config->skills as $skill) {
      $effective = $skill->effective;
      $entries = $this->selectTasks($effective->tasks, $task_globs, $skill->file);

      if ($entries === []) {
        continue;
      }

      if ($effective->models === []) {
        throw new ConfigException(sprintf("skill '%s' has llm tasks but no model configured; set models.default, a ladder, or pass --models.", $effective->skill), $skill->file, 'models');
      }

      if ($effective->rubric !== [] && $effective->judgeModel === NULL) {
        throw new ConfigException(sprintf("skill '%s' declares a judge rubric but no judge model; set models.judge, a ladder, or models.default.", $effective->skill), $skill->file, 'models.judge');
      }

      $selected[] = [$skill, $entries];
    }

    if ($selected === []) {
      throw new ConfigException($task_globs === [] ? 'no llm tasks are declared for the selected skills.' : sprintf('no llm tasks matched --task %s.', implode(', ', $task_globs)));
    }

    $this->environment->prepare();

    try {
      $this->lifecycle->beforeRun([]);

      $skills = [];

      foreach ($selected as [$skill, $entries]) {
        $effective = $skill->effective;
        $skills[] = new SkillOutcome($effective->skill, $effective->path, $this->taskOutcomes($config, $skill, $entries, $stop_at_pass), $effective->threshold, $effective->trials, $effective->rubric, $effective->judgeUnknown);
      }

      $this->lifecycle->afterRun([]);

      return new LlmReport($skills);
    }
    finally {
      $this->environment->teardown();
    }
  }

  /**
   * Runs every selected task of one skill across the ladder, model by model.
   *
   * The ladder is climbed weakest first and every task runs on a model before
   * the climb moves up, so a model's row across the whole skill is complete
   * before the next model starts. In full-matrix mode every model runs and each
   * task ends up with the full ladder; under `--stop-at-pass` the climb stops
   * at the first model that passes every task, so the stronger rows above the
   * minimal model are never paid for. Trials for a given task and model are
   * independent, so the per-task model list is identical to a task-major run in
   * full-matrix mode.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $config
   *   The loaded configuration.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $skill
   *   The skill being run.
   * @param array<int, array{name: string, prompt: string, task: array<mixed>}> $entries
   *   The validated task entries.
   * @param bool $stop_at_pass
   *   Whether to stop the ladder climb at the first model that passes every
   *   task.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\TaskOutcome[]
   *   The per-task outcomes.
   */
  protected function taskOutcomes(LoadedConfig $config, LoadedSkill $skill, array $entries, bool $stop_at_pass): array {
    $effective = $skill->effective;

    // Parse each task's inputs and responder once, up front, so the ladder
    // climb below reuses them across every model rather than re-parsing.
    $prepared = [];

    foreach ($entries as $entry) {
      $inputs = TrialWorkspace::parseInputs($entry['task'], $skill->file);
      $responder = ResponderConfig::fromTask($entry['task'], $skill->file, $effective->judgeModel, $effective->modelAliases);
      $prepared[] = [$entry, $inputs, $responder];
    }

    $models_per_task = array_fill(0, count($prepared), []);

    foreach ($effective->models as $token) {
      $supports = TRUE;

      foreach ($prepared as $index => [$entry, $inputs, $responder]) {
        $trials = $this->runTrials($config, $skill, $entry, $inputs, $responder, $token);
        $model = new ModelOutcome($this->resolveModelId($token, $effective->modelAliases), $token, $trials, $effective->threshold);
        $models_per_task[$index][] = $model;

        if (!$model->passed()) {
          $supports = FALSE;
        }
      }

      if ($stop_at_pass && $supports) {
        break;
      }
    }

    $outcomes = [];

    foreach ($prepared as $index => [$entry]) {
      $outcomes[] = new TaskOutcome($entry['name'], $models_per_task[$index]);
    }

    return $outcomes;
  }

  /**
   * Runs one task's trials on one model, batched by the concurrency limit.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $config
   *   The loaded configuration.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $skill
   *   The skill being run.
   * @param array{name: string, prompt: string, task: array<mixed>} $entry
   *   The validated task entry.
   * @param array{fixture: ?string, repos: array<int, array{source: string, commit: string, dest: string}>, workdir: ?string} $inputs
   *   The parsed task inputs.
   * @param \AlexSkrypnyk\SkillTest\Live\ResponderConfig|null $responder
   *   The task's responder configuration, or NULL for a single-shot task.
   * @param string $token
   *   The model alias or id from configuration.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\TrialResult[]
   *   The trial results, in trial order.
   */
  protected function runTrials(LoadedConfig $config, LoadedSkill $skill, array $entry, array $inputs, ?ResponderConfig $responder, string $token): array {
    $effective = $skill->effective;
    $mock = McpMock::fromTask($entry['task'], $skill->file, dirname($skill->file));
    $allowed = $this->allowedTools($effective, $mock);
    $model_id = $this->resolveModelId($token, $effective->modelAliases);

    // A cache hit replays the graded trials of an unchanged task-on-model
    // without executing the agent; the key digests everything that could change
    // the verdict, so any change to the task, skill, fixtures, model, or tool
    // misses and re-runs live.
    $cache = $this->cache;
    $key = $cache?->key($effective->skill, $entry, $model_id, $this->root . '/' . $effective->path, $inputs);

    if ($cache instanceof TrialCache && $key !== NULL) {
      $hit = $cache->get($key);

      if ($hit !== NULL) {
        return $hit;
      }
    }

    $total = max(1, $effective->trials);
    $limit = max(1, $this->parallel);
    $results = [];

    for ($start = 0; $start < $total; $start += $limit) {
      $numbers = range($start + 1, min($start + $limit, $total));
      $results += $this->runBatch($config, $skill, $entry, $inputs, $responder, $token, $model_id, $mock, $allowed, $numbers);
    }

    ksort($results);
    $trials = array_values($results);

    if ($cache instanceof TrialCache && $key !== NULL) {
      $cache->put($key, $trials);
    }

    return $trials;
  }

  /**
   * The allowed-tools list, extended with mocked tools when tools are gated.
   *
   * A restricted contract must permit the tools it mocks or the agent cannot
   * call them; an unrestricted contract already permits everything, so nothing
   * is added and the run stays unrestricted.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\EffectiveConfig $effective
   *   The skill's effective configuration.
   * @param \AlexSkrypnyk\SkillTest\Live\Mcp\McpMock $mock
   *   The task's parsed mocks.
   *
   * @return string[]
   *   The allowed-tools list.
   */
  protected function allowedTools(EffectiveConfig $effective, McpMock $mock): array {
    $allowed = Data::toStringList(Data::get($effective->contract, 'tools', 'allowed'));

    if ($allowed === [] || $mock->isEmpty()) {
      return $allowed;
    }

    return array_values(array_unique(array_merge($allowed, $mock->toolNames())));
  }

  /**
   * Assembles, runs, grades, and tears down one concurrent batch of trials.
   *
   * A single-shot batch runs every trial's command at once through the
   * environment's process pool, so `--parallel` shortens wall-clock. An
   * interactive batch drives each trial's conversation loop in turn - the loop
   * is stateful and each turn feeds the next, so trials run sequentially - but
   * every workspace is still assembled and torn down together, and grading is
   * identical whichever shape produced the transcript.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $config
   *   The loaded configuration.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $skill
   *   The skill being run.
   * @param array{name: string, prompt: string, task: array<mixed>} $entry
   *   The validated task entry.
   * @param array{fixture: ?string, repos: array<int, array{source: string, commit: string, dest: string}>, workdir: ?string} $inputs
   *   The parsed task inputs.
   * @param \AlexSkrypnyk\SkillTest\Live\ResponderConfig|null $responder
   *   The task's responder configuration, or NULL for a single-shot task.
   * @param string $token
   *   The model alias or id from configuration.
   * @param string $model_id
   *   The resolved model id, for the hook template variables and turn commands.
   * @param \AlexSkrypnyk\SkillTest\Live\Mcp\McpMock $mock
   *   The task's parsed mocks, written into each trial workspace.
   * @param string[] $allowed
   *   The allowed-tools list, already extended with the mocked tools.
   * @param int[] $numbers
   *   The 1-based trial numbers in this batch.
   *
   * @return array<int, \AlexSkrypnyk\SkillTest\Live\TrialResult>
   *   The graded trials keyed by trial number.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When a workspace cannot be assembled or a `before-task` hook aborts.
   */
  protected function runBatch(LoadedConfig $config, LoadedSkill $skill, array $entry, array $inputs, ?ResponderConfig $responder, string $token, string $model_id, McpMock $mock, array $allowed, array $numbers): array {
    $effective = $skill->effective;
    $workspaces = [];
    $graded = [];

    try {
      foreach ($numbers as $number) {
        $workspace = $this->environment->setup($effective->skill, $effective->path, $inputs);
        $workspaces[$number] = $workspace;
        $this->lifecycle->beforeTask($this->taskVars($effective, $entry, $model_id, $number, $workspace));
      }

      $conversations = $responder instanceof ResponderConfig
        ? $this->runInteractive($workspaces, $entry, $model_id, $effective->maxTurns, $allowed, $responder)
        : $this->runSingleShot($workspaces, $entry, $model_id, $effective->maxTurns, $allowed, $mock);

      foreach ($numbers as $number) {
        $this->lifecycle->afterTask($this->taskVars($effective, $entry, $model_id, $number, $workspaces[$number]));
        $graded[$number] = $this->grade($config, $skill, $entry, $token, $number, $conversations[$number], $workspaces[$number], $mock);
      }
    }
    finally {
      foreach ($workspaces as $workspace) {
        $this->environment->cleanup($workspace);
      }
    }

    return $graded;
  }

  /**
   * Runs a batch of single-prompt trials at once through the process pool.
   *
   * @param array<int, \AlexSkrypnyk\SkillTest\Live\TrialWorkspace> $workspaces
   *   The assembled workspaces, keyed by trial number.
   * @param array{name: string, prompt: string, task: array<mixed>} $entry
   *   The validated task entry, supplying the opening prompt.
   * @param string $model_id
   *   The resolved execution model id.
   * @param int|null $max_turns
   *   The per-trial turn cap, or NULL for none.
   * @param string[] $allowed
   *   The allowed-tools list, already extended with the mocked tools.
   * @param \AlexSkrypnyk\SkillTest\Live\Mcp\McpMock $mock
   *   The task's parsed mocks, written into each trial workspace.
   *
   * @return array<int, \AlexSkrypnyk\SkillTest\Live\Conversation>
   *   The one-turn conversations keyed by trial number.
   */
  protected function runSingleShot(array $workspaces, array $entry, string $model_id, ?int $max_turns, array $allowed, McpMock $mock): array {
    $batch = [];

    foreach ($workspaces as $number => $workspace) {
      // The MCP config path differs per workspace, so the command is finished
      // here from the workspace the environment assembled the mocks into.
      $mcp_config = $mock->isEmpty() ? NULL : McpMockWiring::write($workspace->path(), $mock->servers(), SelfInvocation::resolve());
      $batch[$number] = [$workspace, AgentCommand::build($this->binary, $entry['prompt'], $model_id, $max_turns, $allowed, $mcp_config)];
    }

    $conversations = [];

    foreach ($this->environment->exec($batch) as $number => [$exit_code, $stdout, $duration_ms]) {
      $conversations[$number] = Conversation::singleShot($exit_code, $stdout, $duration_ms);
    }

    return $conversations;
  }

  /**
   * Drives each trial's conversation loop, one at a time.
   *
   * @param array<int, \AlexSkrypnyk\SkillTest\Live\TrialWorkspace> $workspaces
   *   The assembled workspaces, keyed by trial number.
   * @param array{name: string, prompt: string, task: array<mixed>} $entry
   *   The validated task entry, supplying the opening prompt.
   * @param string $model_id
   *   The resolved execution model id.
   * @param int|null $max_turns
   *   The per-turn turn cap, or NULL for none.
   * @param string[] $allowed
   *   The contract's allowed tools.
   * @param \AlexSkrypnyk\SkillTest\Live\ResponderConfig $responder
   *   The task's responder configuration.
   *
   * @return array<int, \AlexSkrypnyk\SkillTest\Live\Conversation>
   *   The graded-ready conversations keyed by trial number.
   */
  protected function runInteractive(array $workspaces, array $entry, string $model_id, ?int $max_turns, array $allowed, ResponderConfig $responder): array {
    $runner = new ConversationRunner($this->binary, $this->root, $this->responder);
    $conversations = [];

    foreach ($workspaces as $number => $workspace) {
      $turn = fn(string $line): array => $this->environment->exec([[$workspace, $line]])[0];
      $conversations[$number] = $runner->run($turn, $entry['prompt'], $model_id, $max_turns, $allowed, $responder);
    }

    return $conversations;
  }

  /**
   * Builds the template variables one trial's lifecycle hooks receive.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\EffectiveConfig $effective
   *   The skill's effective configuration.
   * @param array{name: string, prompt: string, task: array<mixed>} $entry
   *   The task entry, whose `inputs` supply the `vars.*` variables.
   * @param string $model_id
   *   The resolved model id.
   * @param int $number
   *   The 1-based trial number.
   * @param \AlexSkrypnyk\SkillTest\Live\TrialWorkspace $workspace
   *   The trial workspace, supplying the `workspace` variable.
   *
   * @return array<string, string>
   *   The variables keyed by name, including `vars.*` from the task inputs.
   */
  protected function taskVars(EffectiveConfig $effective, array $entry, string $model_id, int $number, TrialWorkspace $workspace): array {
    $vars = [
      'skill' => $effective->skill,
      'task' => $entry['name'],
      'trial' => (string) $number,
      'model' => $model_id,
      'workspace' => $workspace->path(),
    ];

    // 'inputs' also carries structural keys: 'repos' is a list dropped by the
    // scalar guard below, and 'workdir' is the one scalar structural key to
    // exclude. Every other scalar input becomes a template variable.
    foreach (Data::toArray(Data::get($entry['task'], 'inputs')) as $key => $value) {
      if ($key === 'workdir') {
        continue;
      }

      $string = Data::toStringOrNull($value);

      if ($string !== NULL) {
        $vars['vars.' . $key] = $string;
      }
    }

    return $vars;
  }

  /**
   * Grades one trial's transcript into a result.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $config
   *   The loaded configuration.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $skill
   *   The skill being run.
   * @param array{name: string, prompt: string, task: array<mixed>} $entry
   *   The validated task entry.
   * @param string $token
   *   The model alias or id from configuration.
   * @param int $number
   *   The 1-based trial number.
   * @param \AlexSkrypnyk\SkillTest\Live\Conversation $conversation
   *   The trial's run: its accumulated transcript, exit, metrics, and outcome.
   * @param \AlexSkrypnyk\SkillTest\Live\TrialWorkspace $workspace
   *   The trial workspace, where the transcript is staged for custom checks.
   * @param \AlexSkrypnyk\SkillTest\Live\Mcp\McpMock $mock
   *   The task's parsed mocks, whose call logs are read from the workspace.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\TrialResult
   *   The graded trial.
   */
  protected function grade(LoadedConfig $config, LoadedSkill $skill, array $entry, string $token, int $number, Conversation $conversation, TrialWorkspace $workspace, McpMock $mock): TrialResult {
    $effective = $skill->effective;
    $stdout = $conversation->transcript;
    $transcript = new Transcript($stdout);
    $checks = (new ContractChecker($config->repo->aliases))->check($transcript, $effective->contract);

    if ($effective->checks !== []) {
      $checks = array_merge($checks, $this->customChecks($effective, $stdout, $workspace));
    }

    // An abstention or a responder error is an incomplete run, so the judge is
    // not spent on it - the same treatment a non-zero agent exit gets.
    $judgeable = $conversation->exitCode === 0 && !$conversation->responderFailed();
    [$criteria, $judge_model, $judge_checks] = $this->judgeTrial($effective, $entry, $stdout, $judgeable);
    $checks = array_merge($checks, $judge_checks);

    [$mock_logs, $mock_checks] = $this->mockOutcome($effective, $entry, $token, $number, $workspace, $mock);
    $checks = array_merge($checks, $mock_checks);

    $outcome = $conversation->outcome;

    if ($outcome instanceof ResponderOutcome && $outcome->isFailure()) {
      array_unshift($checks, $this->responderFailure($outcome));
    }

    if ($conversation->exitCode !== 0) {
      array_unshift($checks, $this->agentFailure($conversation->exitCode));
    }

    $pass = array_reduce($checks, static fn(bool $carry, CheckResult $result): bool => $carry && $result->pass, TRUE);

    return new TrialResult(
      $number,
      $pass,
      $checks,
      $conversation->tokensIn,
      $conversation->tokensOut,
      $conversation->turns,
      $conversation->cost,
      $conversation->durationMs,
      $stdout,
      $this->transcriptPath($effective->skill, $entry['name'], $token, $number),
      $criteria,
      $judge_model,
      $mock_logs,
      $outcome,
      $conversation->followups,
    );
  }

  /**
   * Collects a trial's mock call logs and the failures they name.
   *
   * Each mocked server's log becomes an artifact keyed by its document-relative
   * path, and every unmatched or unknown call recorded in it folds in a failing
   * check - so an agent that drifts onto an unmocked path fails the trial
   * deterministically, naming the tool and the closest fixture, regardless of
   * how the agent itself reacted to the mock's error.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\EffectiveConfig $effective
   *   The skill's effective configuration.
   * @param array{name: string, prompt: string, task: array<mixed>} $entry
   *   The validated task entry.
   * @param string $token
   *   The model alias or id from configuration.
   * @param int $number
   *   The 1-based trial number.
   * @param \AlexSkrypnyk\SkillTest\Live\TrialWorkspace $workspace
   *   The trial workspace holding the mock logs, read before teardown.
   * @param \AlexSkrypnyk\SkillTest\Live\Mcp\McpMock $mock
   *   The task's parsed mocks.
   *
   * @return array{0: array<string, string>, 1: \AlexSkrypnyk\SkillTest\Contract\CheckResult[]}
   *   The artifact map keyed by relative path, and the failing mock checks.
   */
  protected function mockOutcome(EffectiveConfig $effective, array $entry, string $token, int $number, TrialWorkspace $workspace, McpMock $mock): array {
    $artifacts = [];
    $checks = [];

    foreach (McpMockWiring::logs($workspace->path(), $mock->servers()) as $server => $content) {
      $artifacts[$this->mockLogPath($effective->skill, $entry['name'], $token, $number, $server)] = $content;

      foreach ($this->unmatchedCalls($content) as $message) {
        $checks[] = CheckResult::fail(self::CHECK_MCP, 'mcp mock', '', $message);
      }
    }

    return [$artifacts, $checks];
  }

  /**
   * Extracts the failure message of every unmatched call in a mock log.
   *
   * @param string $content
   *   The server's JSONL call log.
   *
   * @return string[]
   *   One message per `matched:false` record, in call order.
   */
  protected function unmatchedCalls(string $content): array {
    $messages = [];

    foreach (explode("\n", trim($content)) as $line) {
      if ($line === '') {
        continue;
      }

      $record = json_decode($line, TRUE);

      if (is_array($record) && ($record['matched'] ?? NULL) === FALSE) {
        $messages[] = Data::toStringOrNull($record['error'] ?? NULL) ?? 'a mocked tool call did not match any fixture.';
      }
    }

    return $messages;
  }

  /**
   * Builds the document-relative path one server's mock log is referenced by.
   *
   * @param string $skill
   *   The skill name.
   * @param string $task
   *   The task name.
   * @param string $alias
   *   The model alias.
   * @param int $number
   *   The 1-based trial number.
   * @param string $server
   *   The mock server name.
   *
   * @return string
   *   The relative mock-log path.
   */
  protected function mockLogPath(string $skill, string $task, string $alias, int $number, string $server): string {
    return sprintf('artifacts/%s__%s__%s__t%d__mock-%s.jsonl', self::slug($skill), self::slug($task), self::slug($alias), $number, self::slug($server));
  }

  /**
   * Scores a trial against its skill's rubric, when one is declared.
   *
   * Runs only when the skill declares a rubric and the run is judgeable; a
   * broken or incomplete run (a non-zero agent exit, an abstention, a responder
   * failure) is already a failing trial with a partial transcript, so the judge
   * is not spent on it, though the pinned model is still reported so a judged
   * skill records one judge model across every trial. A judge failure (an
   * unparseable verdict or a broken judge process) folds in a distinct failing
   * check rather than a silent pass; a verdict that blocks under the abstention
   * policy folds in a rubric check naming the tally.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\EffectiveConfig $effective
   *   The skill's effective configuration.
   * @param array{name: string, prompt: string, task: array<mixed>} $entry
   *   The validated task entry.
   * @param string $stdout
   *   The trial's captured transcript.
   * @param bool $judgeable
   *   Whether the run reached a state worth judging.
   *
   * @return array{0: \AlexSkrypnyk\SkillTest\Judge\JudgeCriterion[], 1: string|null, 2: \AlexSkrypnyk\SkillTest\Contract\CheckResult[]}
   *   The per-criterion verdict, the pinned judge model id, and any failing
   *   judge checks to fold into the trial.
   */
  protected function judgeTrial(EffectiveConfig $effective, array $entry, string $stdout, bool $judgeable): array {
    $token = $effective->judgeModel;

    if ($effective->rubric === [] || $token === NULL) {
      return [[], NULL, []];
    }

    $judge_model = $this->resolveModelId($token, $effective->modelAliases);

    if (!$judgeable) {
      return [[], $judge_model, []];
    }

    try {
      $verdict = $this->judge->evaluate($effective->rubric, $entry['prompt'], $stdout, $judge_model, $this->root);
    }
    catch (JudgeException $judge_exception) {
      return [[], $judge_model, [CheckResult::fail(self::CHECK_JUDGE, 'judge verdict', '', $judge_exception->getMessage())]];
    }

    if (!$verdict->blocks(UnknownPolicy::fromConfig($effective->judgeUnknown))) {
      return [$verdict->criteria, $judge_model, []];
    }

    $message = sprintf('the judge passed %d of %d criteria (%d unknown).', $verdict->passedCount(), $verdict->total(), $verdict->unknowns());

    return [$verdict->criteria, $judge_model, [CheckResult::fail(self::CHECK_JUDGE_RUBRIC, 'judge rubric', '', $message)]];
  }

  /**
   * Runs a skill's custom checks against a live transcript.
   *
   * The live transcript is staged inside the trial workspace so the check
   * scripts receive it the same way they receive a recorded fixture; it is
   * removed with the workspace once the trial is graded.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\EffectiveConfig $effective
   *   The skill's effective configuration.
   * @param string $stdout
   *   The captured transcript.
   * @param \AlexSkrypnyk\SkillTest\Live\TrialWorkspace $workspace
   *   The trial workspace the transcript is staged in.
   *
   * @return \AlexSkrypnyk\SkillTest\Contract\CheckResult[]
   *   The custom check results.
   */
  protected function customChecks(EffectiveConfig $effective, string $stdout, TrialWorkspace $workspace): array {
    $file = $workspace->path() . '/.skilltest-transcript.jsonl';
    file_put_contents($file, $stdout);

    $custom = new CustomCheck($this->root, $this->checkRunner);
    $skill_dir = $this->root . '/' . $effective->path;
    $results = [];

    foreach ($effective->checks as $check) {
      $result = $custom->run($check, $file, $skill_dir);

      if ($result instanceof CheckResult) {
        $results[] = $result;
      }
    }

    return $results;
  }

  /**
   * Builds the failing check that folds a broken agent run into the verdict.
   *
   * @param int $exit_code
   *   The agent process exit code.
   *
   * @return \AlexSkrypnyk\SkillTest\Contract\CheckResult
   *   The failing agent check.
   */
  protected function agentFailure(int $exit_code): CheckResult {
    $message = $exit_code === ProcessPool::TIMEOUT_EXIT
      ? sprintf('agent run timed out after %ds.', (int) round($this->timeout))
      : sprintf('agent run exited with code %d.', $exit_code);

    return CheckResult::fail(self::CHECK_AGENT, 'agent run', '', $message);
  }

  /**
   * Builds the failing check that folds a responder failure into the verdict.
   *
   * An abstention says the persona brief was too vague to answer the skill; any
   * other responder failure means the responder process broke or returned an
   * unusable move. Either way the conversation never reached a state worth
   * grading, so the trial fails on this check.
   *
   * @param \AlexSkrypnyk\SkillTest\Live\ResponderOutcome $outcome
   *   The failing responder outcome.
   *
   * @return \AlexSkrypnyk\SkillTest\Contract\CheckResult
   *   The failing responder check.
   */
  protected function responderFailure(ResponderOutcome $outcome): CheckResult {
    $message = $outcome === ResponderOutcome::Abstained
      ? 'the responder abstained: the persona brief could not answer the skill.'
      : 'the responder failed to produce a usable reply.';

    return CheckResult::fail(self::CHECK_RESPONDER, 'responder', '', $message);
  }

  /**
   * Selects and validates the tasks a skill runs under the task globs.
   *
   * @param array<int, array<mixed>> $tasks
   *   The declared tasks.
   * @param string[] $globs
   *   The task-name globs; empty selects all.
   * @param string $config_file
   *   The declaring `eval.yaml`, for error context.
   *
   * @return array<int, array{name: string, prompt: string, task: array<mixed>}>
   *   The validated task entries.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When a selected task omits its name or prompt.
   */
  protected function selectTasks(array $tasks, array $globs, string $config_file): array {
    $entries = [];

    foreach ($tasks as $task) {
      $name = Data::toStringOrNull(Data::get($task, 'name'));

      // A missing name is a malformed task regardless of the selection, so
      // it is rejected before the glob filter rather than silently skipped.
      if ($name === NULL || $name === '') {
        throw new ConfigException("an llm task requires a 'name'.", $config_file, 'llm.tasks');
      }

      if ($globs !== [] && !Glob::matches($name, $globs)) {
        continue;
      }

      $prompt = Data::toStringOrNull(Data::get($task, 'prompt'));

      if ($prompt === NULL || $prompt === '') {
        throw new ConfigException(sprintf("llm task '%s' requires a 'prompt'.", $name), $config_file, 'llm.tasks');
      }

      $entries[] = ['name' => $name, 'prompt' => $prompt, 'task' => $task];
    }

    return $entries;
  }

  /**
   * Resolves a model alias to its id, passing an unknown token through.
   *
   * @param string $token
   *   The model alias or full id from configuration.
   * @param array<string, string> $aliases
   *   The repo model aliases.
   *
   * @return string
   *   The resolved model id.
   */
  protected function resolveModelId(string $token, array $aliases): string {
    return $aliases[$token] ?? $token;
  }

  /**
   * Builds the document-relative path a trial's transcript is referenced by.
   *
   * @param string $skill
   *   The skill name.
   * @param string $task
   *   The task name.
   * @param string $alias
   *   The model alias.
   * @param int $number
   *   The 1-based trial number.
   *
   * @return string
   *   The relative transcript path.
   */
  protected function transcriptPath(string $skill, string $task, string $alias, int $number): string {
    return sprintf('artifacts/%s__%s__%s__t%d.jsonl', self::slug($skill), self::slug($task), self::slug($alias), $number);
  }

  /**
   * Reduces a value to a filesystem-safe slug for an artifact path.
   *
   * @param string $value
   *   The value to slugify.
   *
   * @return string
   *   The slug, with every unsafe run collapsed to a hyphen.
   */
  protected static function slug(string $value): string {
    return (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $value);
  }

}
