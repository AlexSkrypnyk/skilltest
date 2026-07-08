<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Config;

/**
 * The repository-level configuration parsed from `skilltest.yml`.
 *
 * Carries everything true for the whole repo: where skills live, the per-skill
 * config filename, command aliases and guards, enforcement hooks, model
 * aliases and ladder, and the default execution environment. Built-in defaults
 * apply for every value the file omits.
 */
final readonly class RepoConfig {

  /**
   * The default skills paths when the file specifies none.
   */
  public const array DEFAULT_SKILLS_PATHS = ['skills'];

  /**
   * The default per-skill config filename.
   */
  public const string DEFAULT_EVAL_FILE = 'eval.yaml';

  /**
   * The default execution environment.
   */
  public const string DEFAULT_ENVIRONMENT = 'host';

  /**
   * Constructs a RepoConfig.
   *
   * @param string[] $skillsPaths
   *   Directories that contain skill directories.
   * @param string $evalFile
   *   The per-skill config filename.
   * @param \AlexSkrypnyk\SkillTest\Config\ExcludeEntry[] $excludes
   *   Skills exempt from the coverage gate, each with its declared reason.
   * @param array<string, string> $aliases
   *   Command normalisation patterns keyed by canonical name.
   * @param array<string, string> $guards
   *   Forbidden-command patterns appended to every skill, keyed by label.
   * @param array<int, array<mixed>> $hooks
   *   Enforcement hook declarations, each with a script and cases.
   * @param array<mixed> $commandResolve
   *   The `commands.resolve` block (binary and list-args).
   * @param array<string, string> $modelAliases
   *   Model ids keyed by alias.
   * @param string[] $ladder
   *   The model ladder, weakest first.
   * @param string|null $defaultModel
   *   The default model alias, when set.
   * @param string|null $judgeModel
   *   The judge model alias, when set.
   * @param string $environment
   *   The execution environment (host or docker).
   * @param array<mixed> $report
   *   The `report` block.
   */
  public function __construct(
    public array $skillsPaths,
    public string $evalFile,
    public array $excludes,
    public array $aliases,
    public array $guards,
    public array $hooks,
    public array $commandResolve,
    public array $modelAliases,
    public array $ladder,
    public ?string $defaultModel,
    public ?string $judgeModel,
    public string $environment,
    public array $report,
  ) {}

  /**
   * Builds a RepoConfig from parsed `skilltest.yml` data.
   *
   * @param array<mixed> $data
   *   The parsed file, or an empty array when the file is absent.
   *
   * @return self
   *   The repo configuration with defaults applied.
   */
  public static function fromArray(array $data): self {
    $paths = Data::toArray(Data::get($data, 'paths'));

    $skills_paths = Data::toStringList(Data::get($paths, 'skills'));
    if ($skills_paths === []) {
      $skills_paths = self::DEFAULT_SKILLS_PATHS;
    }

    $models = Data::toArray(Data::get($data, 'models'));

    $hooks = Data::toArrayList(Data::get($data, 'hooks'));

    $excludes = array_map(
      static fn(mixed $item): ExcludeEntry => ExcludeEntry::fromValue($item),
      array_values(Data::toArray(Data::get($paths, 'exclude'))),
    );

    return new self(
      $skills_paths,
      Data::toStringOrNull(Data::get($paths, 'eval-file')) ?? self::DEFAULT_EVAL_FILE,
      $excludes,
      Data::toStringMap(Data::get($data, 'aliases')),
      Data::toStringMap(Data::get($data, 'guards')),
      $hooks,
      Data::toArray(Data::get($data, 'commands', 'resolve')),
      Data::toStringMap(Data::get($models, 'aliases')),
      Data::toStringList(Data::get($models, 'ladder')),
      Data::toStringOrNull(Data::get($models, 'default')),
      Data::toStringOrNull(Data::get($models, 'judge')),
      Data::toStringOrNull(Data::get($data, 'llm', 'environment')) ?? self::DEFAULT_ENVIRONMENT,
      Data::toArray(Data::get($data, 'report')),
    );
  }

}
