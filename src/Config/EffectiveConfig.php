<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Config;

/**
 * The effective, merged configuration for one skill.
 *
 * Produced by folding a skill's `eval.yaml` over the repo `skilltest.yml` over
 * built-in defaults, with CLI overrides on top. This is what `validate
 * --show-config` prints, so precedence is observable rather than a mystery.
 */
final class EffectiveConfig {

  /**
   * The default per-model pass-rate threshold.
   */
  public const float DEFAULT_THRESHOLD = 0.8;

  /**
   * The default number of trials per task.
   */
  public const int DEFAULT_TRIALS = 1;

  /**
   * The security pack that is always enabled.
   */
  public const string BASELINE_PACK = 'baseline';

  /**
   * The keyword that expands to the repo model ladder.
   */
  public const string LADDER_KEYWORD = 'ladder';

  /**
   * Constructs an EffectiveConfig.
   *
   * @param string $skill
   *   The skill name.
   * @param string $path
   *   The skill directory, relative to the repo root.
   * @param array<string, mixed> $contract
   *   The normalised contract (tools, commands, skills).
   * @param array<string, mixed> $security
   *   The normalised security block (packs, forbidden-tokens).
   * @param string|null $transcript
   *   The deterministic transcript fixture path, relative to the skill dir.
   * @param string[] $models
   *   The resolved model list.
   * @param float $threshold
   *   The resolved pass-rate threshold.
   * @param int $trials
   *   The resolved trial count.
   * @param int|null $maxTurns
   *   The live-run turn cap, when set.
   * @param string $environment
   *   The execution environment (host or docker).
   * @param string|null $judgeModel
   *   The judge model alias, when set.
   * @param string[] $rubric
   *   The judge rubric criteria.
   * @param array<int, array<mixed>> $tasks
   *   The declared llm tasks.
   * @param array<int, array<mixed>> $checks
   *   The declared custom check scripts.
   * @param array<string, string> $modelAliases
   *   The repo model aliases, carried through for display.
   */
  public function __construct(
    public readonly string $skill,
    public readonly string $path,
    public readonly array $contract,
    public readonly array $security,
    public readonly ?string $transcript,
    public readonly array $models,
    public readonly float $threshold,
    public readonly int $trials,
    public readonly ?int $maxTurns,
    public readonly string $environment,
    public readonly ?string $judgeModel,
    public readonly array $rubric,
    public readonly array $tasks,
    public readonly array $checks,
    public readonly array $modelAliases,
  ) {}

  /**
   * Resolves the effective configuration for a skill.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\RepoConfig $repo
   *   The repo configuration.
   * @param array<mixed> $eval
   *   The parsed `eval.yaml` for this skill.
   * @param array<string, mixed> $cli
   *   CLI overrides keyed by name (models, threshold, trials, env).
   * @param string $name
   *   The skill name derived from the directory.
   * @param string $path
   *   The skill directory, relative to the repo root.
   *
   * @return self
   *   The merged configuration.
   */
  public static function resolve(RepoConfig $repo, array $eval, array $cli, string $name, string $path): self {
    $llm = Data::toArray(Data::get($eval, 'llm'));

    return new self(
      Data::toStringOrNull(Data::get($eval, 'skill')) ?? $name,
      $path,
      self::resolveContract($repo, $eval),
      self::resolveSecurity($eval),
      Data::toStringOrNull(Data::get($eval, 'deterministic', 'transcript')),
      self::resolveModels($repo, $llm, $cli),
      Data::toFloatOrNull($cli['threshold'] ?? NULL) ?? Data::toFloatOrNull(Data::get($llm, 'threshold')) ?? self::DEFAULT_THRESHOLD,
      Data::toIntOrNull($cli['trials'] ?? NULL) ?? Data::toIntOrNull(Data::get($llm, 'trials')) ?? self::DEFAULT_TRIALS,
      Data::toIntOrNull(Data::get($llm, 'max-turns')),
      Data::toStringOrNull($cli['env'] ?? NULL) ?? $repo->environment,
      $repo->judgeModel,
      Data::toStringList(Data::get($llm, 'judge', 'rubric')),
      Data::toArrayList(Data::get($llm, 'tasks')),
      Data::toArrayList(Data::get($llm, 'checks')),
      $repo->modelAliases,
    );
  }

  /**
   * Normalises the contract and appends repo guards to forbidden commands.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\RepoConfig $repo
   *   The repo configuration.
   * @param array<mixed> $eval
   *   The parsed `eval.yaml`.
   *
   * @return array<string, mixed>
   *   The normalised contract.
   */
  protected static function resolveContract(RepoConfig $repo, array $eval): array {
    $contract = Data::toArray(Data::get($eval, 'contract'));
    $tools = Data::toArray(Data::get($contract, 'tools'));
    $commands = Data::toArray(Data::get($contract, 'commands'));
    $skills = Data::toArray(Data::get($contract, 'skills'));

    $forbidden_commands = Data::toStringMap(Data::get($commands, 'forbidden'));
    foreach ($repo->guards as $label => $pattern) {
      $forbidden_commands[$label] = $pattern;
    }

    return [
      'tools' => [
        'allowed' => Data::toStringList(Data::get($tools, 'allowed')),
        'required' => Data::toStringList(Data::get($tools, 'required')),
        'forbidden' => Data::toStringList(Data::get($tools, 'forbidden')),
      ],
      'commands' => [
        'required' => Data::toStringMap(Data::get($commands, 'required')),
        'forbidden' => $forbidden_commands,
      ],
      'skills' => [
        'required' => Data::toStringList(Data::get($skills, 'required')),
        'forbidden' => Data::toStringList(Data::get($skills, 'forbidden')),
      ],
    ];
  }

  /**
   * Normalises the security block, ensuring the baseline pack is present.
   *
   * @param array<mixed> $eval
   *   The parsed `eval.yaml`.
   *
   * @return array<string, mixed>
   *   The normalised security block.
   */
  protected static function resolveSecurity(array $eval): array {
    $security = Data::toArray(Data::get($eval, 'security'));
    $packs = Data::toStringList(Data::get($security, 'packs'));

    if (!in_array(self::BASELINE_PACK, $packs, TRUE)) {
      array_unshift($packs, self::BASELINE_PACK);
    }

    return [
      'packs' => $packs,
      'forbidden-tokens' => Data::toStringList(Data::get($security, 'forbidden-tokens')),
    ];
  }

  /**
   * Resolves the model list by precedence: CLI, eval, repo ladder, repo default.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\RepoConfig $repo
   *   The repo configuration.
   * @param array<mixed> $llm
   *   The eval `llm` block.
   * @param array<string, mixed> $cli
   *   CLI overrides.
   *
   * @return string[]
   *   The resolved model list.
   */
  protected static function resolveModels(RepoConfig $repo, array $llm, array $cli): array {
    $cli_models = self::expandModels($cli['models'] ?? NULL, $repo);
    if ($cli_models !== []) {
      return $cli_models;
    }

    $eval_models = self::expandModels(Data::get($llm, 'models'), $repo);
    if ($eval_models !== []) {
      return $eval_models;
    }

    if ($repo->ladder !== []) {
      return $repo->ladder;
    }

    if ($repo->defaultModel !== NULL) {
      return [$repo->defaultModel];
    }

    return [];
  }

  /**
   * Expands a model value, turning the `ladder` keyword into the repo ladder.
   *
   * @param mixed $value
   *   A model value: the `ladder` keyword, a comma string, or a list.
   * @param \AlexSkrypnyk\SkillTest\Config\RepoConfig $repo
   *   The repo configuration.
   *
   * @return string[]
   *   The expanded model list.
   */
  protected static function expandModels(mixed $value, RepoConfig $repo): array {
    if ($value === self::LADDER_KEYWORD) {
      return $repo->ladder;
    }

    if (is_string($value)) {
      return array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $part): bool => $part !== ''));
    }

    return Data::toStringList($value);
  }

  /**
   * Returns the merged configuration as a stable, printable structure.
   *
   * @return array<string, mixed>
   *   The merged configuration.
   */
  public function toArray(): array {
    return [
      'skill' => $this->skill,
      'path' => $this->path,
      'contract' => $this->contract,
      'security' => $this->security,
      'deterministic' => ['transcript' => $this->transcript],
      'llm' => [
        'models' => $this->models,
        'threshold' => $this->threshold,
        'trials' => $this->trials,
        'max-turns' => $this->maxTurns,
        'environment' => $this->environment,
        'judge' => ['model' => $this->judgeModel, 'rubric' => $this->rubric],
        'tasks' => $this->tasks,
        'checks' => $this->checks,
      ],
      'models' => ['aliases' => $this->modelAliases],
    ];
  }

}
