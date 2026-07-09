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
 * the threshold, with no retries to mask a flaky skill. The process, git, and
 * check seams are injectable so the whole orchestration is testable without a
 * real agent.
 */
final readonly class LlmSuite {

  /**
   * The check id a process-level agent failure renders under.
   */
  public const string CHECK_AGENT = 'live.agent';

  /**
   * The default per-trial wall-clock budget, in seconds.
   */
  public const float DEFAULT_TIMEOUT = 300.0;

  /**
   * The environment variable overriding the per-trial timeout, in seconds.
   */
  public const string ENV_TIMEOUT = 'SKILLTEST_TRIAL_TIMEOUT';

  /**
   * Runs a pool of commands and returns each one's exit, stdout, and duration.
   *
   * @var \Closure(array<array-key, array{0: string, 1: string}>): array<array-key, array{0: int, 1: string, 2: int}>
   */
  protected \Closure $pool;

  /**
   * The base directory trial workspaces are assembled under.
   */
  protected string $workspaceBase;

  /**
   * Constructs an LlmSuite.
   *
   * @param string $root
   *   The repository root.
   * @param string $binary
   *   The resolved agent binary or command prefix.
   * @param int $parallel
   *   The maximum number of concurrent trials.
   * @param float $timeout
   *   The per-trial wall-clock budget, in seconds.
   * @param \Closure|null $pool
   *   An override for the concurrent process runner, for tests.
   * @param \Closure|null $git
   *   An override for the workspace git runner, for tests.
   * @param \Closure|null $checkRunner
   *   An override for the custom-check process runner, for tests.
   * @param string|null $workspace_base
   *   An override for the workspace base directory, for tests.
   */
  public function __construct(
    protected string $root,
    protected string $binary,
    protected int $parallel = 1,
    protected float $timeout = self::DEFAULT_TIMEOUT,
    ?\Closure $pool = NULL,
    protected ?\Closure $git = NULL,
    protected ?\Closure $checkRunner = NULL,
    ?string $workspace_base = NULL,
  ) {
    $this->pool = $pool ?? (new ProcessPool($parallel, $timeout))->run(...);
    $this->workspaceBase = $workspace_base ?? rtrim($root, '/') . '/.artifacts/tmp/skilltest-llm';
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
    $skills = [];

    foreach ($config->skills as $skill) {
      $effective = $skill->effective;
      $entries = $this->selectTasks($effective->tasks, $task_globs, $skill->file);

      if ($entries === []) {
        continue;
      }

      if ($effective->models === []) {
        throw new ConfigException(sprintf("skill '%s' has llm tasks but no model configured; set models.default, a ladder, or pass --models.", $effective->skill), $skill->file, 'models');
      }

      $skills[] = new SkillOutcome($effective->skill, $effective->path, $this->taskOutcomes($config, $skill, $entries), $effective->threshold, $effective->trials);
    }

    if ($skills === []) {
      throw new ConfigException($task_globs === [] ? 'no llm tasks are declared for the selected skills.' : sprintf('no llm tasks matched --task %s.', implode(', ', $task_globs)));
    }

    return new LlmReport($skills);
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
    $command = AgentCommand::build($this->binary, $entry['prompt'], $this->resolveModelId($token, $effective->modelAliases), $effective->maxTurns, $allowed);

    $total = max(1, $effective->trials);
    $limit = max(1, $this->parallel);
    $results = [];

    for ($start = 0; $start < $total; $start += $limit) {
      $numbers = range($start + 1, min($start + $limit, $total));
      $results += $this->runBatch($config, $skill, $entry, $inputs, $token, $command, $numbers);
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
   * @param string $command
   *   The assembled agent command.
   * @param int[] $numbers
   *   The 1-based trial numbers in this batch.
   *
   * @return array<int, \AlexSkrypnyk\SkillTest\Live\TrialResult>
   *   The graded trials keyed by trial number.
   */
  protected function runBatch(LoadedConfig $config, LoadedSkill $skill, array $entry, array $inputs, string $token, string $command, array $numbers): array {
    $workspaces = [];
    $commands = [];
    $graded = [];

    try {
      foreach ($numbers as $number) {
        $workspace = new TrialWorkspace($this->workspaceBase . '/' . uniqid('ws-', TRUE), $this->root, $skill->effective->skill, $skill->effective->path, $inputs, $this->git);
        $workspace->assemble();
        $workspaces[$number] = $workspace;
        $commands[$number] = [$command, $workspace->agentDir()];
      }

      $outcomes = ($this->pool)($commands);

      foreach ($numbers as $number) {
        [$exit_code, $stdout, $duration_ms] = $outcomes[$number];
        $graded[$number] = $this->grade($config, $skill, $entry, $token, $number, $exit_code, $stdout, $duration_ms, $workspaces[$number]);
      }
    }
    finally {
      foreach ($workspaces as $workspace) {
        $workspace->cleanup();
      }
    }

    return $graded;
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
    );
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
