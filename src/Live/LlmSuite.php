<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Config\EffectiveConfig;
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
  ) {
    $this->judge = new Judge($binary, $judge_runner, $timeout);
  }

  /**
   * Runs the llm suite over the selected skills and tasks.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $config
   *   The loaded configuration, already narrowed to the selected skills.
   * @param string[] $task_globs
   *   The task-name globs; empty selects every declared task.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\LlmReport
   *   The aggregated run outcome.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the selection runs no tasks, a task is malformed, no model is
   *   configured, or a workspace cannot be assembled.
   */
  public function run(LoadedConfig $config, array $task_globs): LlmReport {
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
        $skills[] = new SkillOutcome($effective->skill, $effective->path, $this->taskOutcomes($config, $skill, $entries), $effective->threshold, $effective->trials);
      }

      $this->lifecycle->afterRun([]);

      return new LlmReport($skills);
    }
    finally {
      $this->environment->teardown();
    }
  }

  /**
   * Runs every selected task of one skill across every configured model.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $config
   *   The loaded configuration.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $skill
   *   The skill being run.
   * @param array<int, array{name: string, prompt: string, task: array<mixed>}> $entries
   *   The validated task entries.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\TaskOutcome[]
   *   The per-task outcomes.
   */
  protected function taskOutcomes(LoadedConfig $config, LoadedSkill $skill, array $entries): array {
    $effective = $skill->effective;
    $outcomes = [];

    foreach ($entries as $entry) {
      $inputs = TrialWorkspace::parseInputs($entry['task'], $skill->file);
      $models = [];

      foreach ($effective->models as $token) {
        $trials = $this->runTrials($config, $skill, $entry, $inputs, $token);
        $models[] = new ModelOutcome($this->resolveModelId($token, $effective->modelAliases), $token, $trials, $effective->threshold);
      }

      $outcomes[] = new TaskOutcome($entry['name'], $models);
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
   * @param string $token
   *   The model alias or id from configuration.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\TrialResult[]
   *   The trial results, in trial order.
   */
  protected function runTrials(LoadedConfig $config, LoadedSkill $skill, array $entry, array $inputs, string $token): array {
    $effective = $skill->effective;
    $allowed = Data::toStringList(Data::get($effective->contract, 'tools', 'allowed'));
    $model_id = $this->resolveModelId($token, $effective->modelAliases);
    $command = AgentCommand::build($this->binary, $entry['prompt'], $model_id, $effective->maxTurns, $allowed);

    $total = max(1, $effective->trials);
    $limit = max(1, $this->parallel);
    $results = [];

    for ($start = 0; $start < $total; $start += $limit) {
      $numbers = range($start + 1, min($start + $limit, $total));
      $results += $this->runBatch($config, $skill, $entry, $inputs, $token, $model_id, $command, $numbers);
    }

    ksort($results);

    return array_values($results);
  }

  /**
   * Assembles, runs, grades, and tears down one concurrent batch of trials.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $config
   *   The loaded configuration.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $skill
   *   The skill being run.
   * @param array{name: string, prompt: string, task: array<mixed>} $entry
   *   The validated task entry.
   * @param array{fixture: ?string, repos: array<int, array{source: string, commit: string, dest: string}>, workdir: ?string} $inputs
   *   The parsed task inputs.
   * @param string $token
   *   The model alias or id from configuration.
   * @param string $model_id
   *   The resolved model id, for the hook template variables.
   * @param string $command
   *   The assembled agent command.
   * @param int[] $numbers
   *   The 1-based trial numbers in this batch.
   *
   * @return array<int, \AlexSkrypnyk\SkillTest\Live\TrialResult>
   *   The graded trials keyed by trial number.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When a workspace cannot be assembled or a `before-task` hook aborts.
   */
  protected function runBatch(LoadedConfig $config, LoadedSkill $skill, array $entry, array $inputs, string $token, string $model_id, string $command, array $numbers): array {
    $effective = $skill->effective;
    $workspaces = [];
    $batch = [];
    $graded = [];

    try {
      foreach ($numbers as $number) {
        $workspace = $this->environment->setup($effective->skill, $effective->path, $inputs);
        $workspaces[$number] = $workspace;
        $this->lifecycle->beforeTask($this->taskVars($effective, $entry, $model_id, $number, $workspace));
        $batch[$number] = [$workspace, $command];
      }

      $outcomes = $this->environment->exec($batch);

      foreach ($numbers as $number) {
        [$exit_code, $stdout, $duration_ms] = $outcomes[$number];
        $this->lifecycle->afterTask($this->taskVars($effective, $entry, $model_id, $number, $workspaces[$number]));
        $graded[$number] = $this->grade($config, $skill, $entry, $token, $number, $exit_code, $stdout, $duration_ms, $workspaces[$number]);
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
   * @param int $exit_code
   *   The agent process exit code.
   * @param string $stdout
   *   The captured stream-json transcript.
   * @param int $duration_ms
   *   The measured wall-clock duration.
   * @param \AlexSkrypnyk\SkillTest\Live\TrialWorkspace $workspace
   *   The trial workspace, where the transcript is staged for custom checks.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\TrialResult
   *   The graded trial.
   */
  protected function grade(LoadedConfig $config, LoadedSkill $skill, array $entry, string $token, int $number, int $exit_code, string $stdout, int $duration_ms, TrialWorkspace $workspace): TrialResult {
    $effective = $skill->effective;
    $transcript = new Transcript($stdout);
    $checks = (new ContractChecker($config->repo->aliases))->check($transcript, $effective->contract);

    if ($effective->checks !== []) {
      $checks = array_merge($checks, $this->customChecks($effective, $stdout, $workspace));
    }

    [$criteria, $judge_model, $judge_checks] = $this->judgeTrial($effective, $entry, $stdout, $exit_code);
    $checks = array_merge($checks, $judge_checks);

    if ($exit_code !== 0) {
      array_unshift($checks, $this->agentFailure($exit_code));
    }

    $pass = array_reduce($checks, static fn(bool $carry, CheckResult $result): bool => $carry && $result->pass, TRUE);
    $metrics = TranscriptMetrics::fromTranscript($stdout);

    return new TrialResult(
      $number,
      $pass,
      $checks,
      $metrics->tokensIn,
      $metrics->tokensOut,
      $metrics->turns,
      $metrics->cost,
      $duration_ms,
      $stdout,
      $this->transcriptPath($effective->skill, $entry['name'], $token, $number),
      $criteria,
      $judge_model,
    );
  }

  /**
   * Scores a trial against its skill's rubric, when one is declared.
   *
   * Runs only when the skill declares a rubric; a failed agent run is already a
   * failing trial with a partial transcript, so the judge is not spent on it,
   * though the pinned model is still reported so a judged skill records one
   * judge model across every trial. A judge failure (an unparseable verdict or
   * a broken judge process) folds in a distinct failing check rather than a
   * silent pass; a verdict that blocks under the abstention policy folds in a
   * rubric check naming the tally.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\EffectiveConfig $effective
   *   The skill's effective configuration.
   * @param array{name: string, prompt: string, task: array<mixed>} $entry
   *   The validated task entry.
   * @param string $stdout
   *   The trial's captured transcript.
   * @param int $exit_code
   *   The agent process exit code.
   *
   * @return array{0: \AlexSkrypnyk\SkillTest\Judge\JudgeCriterion[], 1: string|null, 2: \AlexSkrypnyk\SkillTest\Contract\CheckResult[]}
   *   The per-criterion verdict, the pinned judge model id, and any failing
   *   judge checks to fold into the trial.
   */
  protected function judgeTrial(EffectiveConfig $effective, array $entry, string $stdout, int $exit_code): array {
    $token = $effective->judgeModel;

    if ($effective->rubric === [] || $token === NULL) {
      return [[], NULL, []];
    }

    $judge_model = $this->resolveModelId($token, $effective->modelAliases);

    if ($exit_code !== 0) {
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

      if ($globs !== [] && !$this->matchesGlobs($name, $globs)) {
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
   * Whether a task name matches any of the selection globs.
   *
   * @param string $name
   *   The task name.
   * @param string[] $globs
   *   The globs.
   *
   * @return bool
   *   TRUE when any glob matches.
   */
  protected function matchesGlobs(string $name, array $globs): bool {
    foreach ($globs as $glob) {
      $regex = '#^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($glob, '#')) . '$#';

      if (preg_match($regex, $name) === 1) {
        return TRUE;
      }
    }

    return FALSE;
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
