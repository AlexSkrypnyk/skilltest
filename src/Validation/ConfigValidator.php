<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Validation;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedSkill;
use AlexSkrypnyk\SkillTest\Config\Packs;
use AlexSkrypnyk\SkillTest\Config\Pcre;
use AlexSkrypnyk\SkillTest\Config\RepoConfig;

/**
 * Validates a loaded configuration for schema conformance and coherence.
 *
 * Schema conformance warns about unknown keys (a newer minor may add fields
 * this reader ignores). Coherence is stricter: required and forbidden sets must
 * be disjoint, patterns must compile, pack and alias references must resolve,
 * and referenced fixtures and hook scripts must exist. Coherence problems are
 * errors; unknown keys are warnings.
 */
final readonly class ConfigValidator {

  /**
   * Known keys of `skilltest.yml`. A nested array descends; TRUE is a leaf.
   */
  public const array REPO_SCHEMA = [
    'version' => TRUE,
    'paths' => ['skills' => TRUE, 'eval-file' => TRUE, 'exclude' => TRUE],
    'aliases' => TRUE,
    'commands' => ['resolve' => ['binary' => TRUE, 'list-args' => TRUE]],
    'guards' => TRUE,
    'hooks' => TRUE,
    'models' => ['aliases' => TRUE, 'ladder' => TRUE, 'default' => TRUE, 'judge' => TRUE],
    'llm' => ['environment' => TRUE, 'docker' => TRUE, 'lifecycle' => TRUE],
    'report' => ['redact' => TRUE],
    'inputs' => TRUE,
  ];

  /**
   * Known keys of `eval.yaml`. A nested array descends; TRUE is a leaf.
   */
  public const array EVAL_SCHEMA = [
    'version' => TRUE,
    'skill' => TRUE,
    'contract' => [
      'tools' => ['allowed' => TRUE, 'required' => TRUE, 'forbidden' => TRUE],
      'commands' => ['required' => TRUE, 'forbidden' => TRUE],
      'skills' => ['required' => TRUE, 'forbidden' => TRUE],
    ],
    'security' => ['packs' => TRUE, 'forbidden-tokens' => TRUE],
    'structure' => ['suppress' => TRUE, 'params' => TRUE],
    'deterministic' => ['transcript' => TRUE],
    'llm' => [
      'tasks' => TRUE,
      'max-turns' => TRUE,
      'trials' => TRUE,
      'threshold' => TRUE,
      'models' => TRUE,
      'judge' => ['rubric' => TRUE, 'unknown' => TRUE],
      'checks' => TRUE,
    ],
    'inputs' => TRUE,
  ];

  /**
   * The repository root, used to resolve hook script paths.
   */
  protected string $root;

  /**
   * Constructs a ConfigValidator.
   *
   * @param string $root
   *   The repository root.
   */
  public function __construct(string $root) {
    $this->root = rtrim($root, '/');
  }

  /**
   * Validates a loaded configuration.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded_config
   *   The loaded configuration.
   *
   * @return \AlexSkrypnyk\SkillTest\Validation\ValidationResult
   *   The accumulated findings.
   */
  public function validate(LoadedConfig $loaded_config): ValidationResult {
    $result = new ValidationResult();

    if ($loaded_config->repoFile !== '') {
      $this->checkUnknownKeys($loaded_config->repoData, self::REPO_SCHEMA, $loaded_config->repoFile, '', $result);
      $this->validateRepoPatterns($loaded_config->repo, $loaded_config->repoFile, $result);
      $this->validateHooks($loaded_config->repo, $loaded_config->repoFile, $result);
      $this->validateModelAliases($loaded_config->repo, $loaded_config->repoFile, $result);
      $this->validateExcludes($loaded_config->repo, $loaded_config->repoFile, $result);
    }

    foreach ($loaded_config->skills as $skill) {
      $this->checkUnknownKeys($skill->data, self::EVAL_SCHEMA, $skill->file, '', $result);
      $this->validateSkill($skill, $result);
    }

    return $result;
  }

  /**
   * Warns about keys not present in a schema, descending into known blocks.
   *
   * @param array<mixed> $data
   *   The parsed data to check.
   * @param array<mixed> $schema
   *   The schema node: nested arrays descend, TRUE is a leaf.
   * @param string $file
   *   The file the data came from.
   * @param string $prefix
   *   The dotted pointer prefix for nested keys.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $validation_result
   *   The result to append warnings to.
   */
  protected function checkUnknownKeys(array $data, array $schema, string $file, string $prefix, ValidationResult $validation_result): void {
    foreach ($data as $key => $value) {
      $key = (string) $key;
      $pointer = $prefix === '' ? $key : $prefix . '.' . $key;

      if (!array_key_exists($key, $schema)) {
        $validation_result->addWarning($file, $pointer, 'unknown key (ignored).');

        continue;
      }

      $sub_schema = $schema[$key];

      if (is_array($sub_schema) && is_array($value)) {
        $this->checkUnknownKeys($value, $sub_schema, $file, $pointer, $validation_result);
      }
    }
  }

  /**
   * Runs the per-skill coherence checks.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $loaded_skill
   *   The loaded skill.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $validation_result
   *   The result to append findings to.
   */
  protected function validateSkill(LoadedSkill $loaded_skill, ValidationResult $validation_result): void {
    $file = $loaded_skill->file;
    $data = $loaded_skill->data;
    $contract = Data::toArray(Data::get($data, 'contract'));

    $this->disjointList(
      Data::toStringList(Data::get($contract, 'tools', 'required')),
      Data::toStringList(Data::get($contract, 'tools', 'forbidden')),
      $file,
      'contract.tools',
      'tool',
      $validation_result,
    );
    $this->disjointList(
      Data::toStringList(Data::get($contract, 'skills', 'required')),
      Data::toStringList(Data::get($contract, 'skills', 'forbidden')),
      $file,
      'contract.skills',
      'skill',
      $validation_result,
    );

    $this->disjointCommands($contract, $file, $validation_result);
    $this->validateCommandPatterns($contract, $file, $validation_result);
    $this->validateSecurityPacks($data, $file, $validation_result);
    $this->validateFixture($loaded_skill, $validation_result);
    $this->validateRubric($data, $loaded_skill, $validation_result);
    $this->validateJudgeUnknown($data, $loaded_skill, $validation_result);
  }

  /**
   * Reports names present in both required and forbidden lists.
   *
   * @param string[] $required
   *   The required names.
   * @param string[] $forbidden
   *   The forbidden names.
   * @param string $file
   *   The file the lists came from.
   * @param string $pointer
   *   The dotted pointer to the block.
   * @param string $noun
   *   A singular noun naming the entries, for the message.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $validation_result
   *   The result to append errors to.
   */
  protected function disjointList(array $required, array $forbidden, string $file, string $pointer, string $noun, ValidationResult $validation_result): void {
    foreach (array_intersect($required, $forbidden) as $name) {
      $validation_result->addError($file, $pointer, sprintf("%s '%s' is in both required and forbidden.", $noun, $name));
    }
  }

  /**
   * Reports command labels or patterns present in both required and forbidden.
   *
   * @param array<mixed> $contract
   *   The contract block.
   * @param string $file
   *   The file the contract came from.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $validation_result
   *   The result to append errors to.
   */
  protected function disjointCommands(array $contract, string $file, ValidationResult $validation_result): void {
    $required = Data::toStringMap(Data::get($contract, 'commands', 'required'));
    $forbidden = Data::toStringMap(Data::get($contract, 'commands', 'forbidden'));

    foreach (array_intersect(array_keys($required), array_keys($forbidden)) as $label) {
      $validation_result->addError($file, 'contract.commands', sprintf("command '%s' is in both required and forbidden.", $label));
    }

    foreach (array_intersect(array_values($required), array_values($forbidden)) as $pattern) {
      $validation_result->addError($file, 'contract.commands', sprintf("command pattern '%s' is in both required and forbidden.", $pattern));
    }
  }

  /**
   * Validates that every command pattern compiles or resolves to a pack.
   *
   * @param array<mixed> $contract
   *   The contract block.
   * @param string $file
   *   The file the contract came from.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $validation_result
   *   The result to append errors to.
   */
  protected function validateCommandPatterns(array $contract, string $file, ValidationResult $validation_result): void {
    $commands = Data::toArray(Data::get($contract, 'commands'));

    foreach (['required', 'forbidden'] as $kind) {
      foreach (Data::toStringMap(Data::get($commands, $kind)) as $label => $pattern) {
        $this->validatePattern($pattern, $file, sprintf('contract.commands.%s.%s', $kind, $label), $validation_result);
      }
    }
  }

  /**
   * Validates one pattern: a pack reference must resolve, a regex must compile.
   *
   * @param string $pattern
   *   The pattern value.
   * @param string $file
   *   The file the pattern came from.
   * @param string $pointer
   *   The dotted pointer to the pattern.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $validation_result
   *   The result to append errors to.
   */
  protected function validatePattern(string $pattern, string $file, string $pointer, ValidationResult $validation_result): void {
    $pack = Packs::reference($pattern);

    if ($pack !== NULL) {
      if (!Packs::isPatternPack($pack)) {
        $validation_result->addError($file, $pointer, sprintf("unknown pattern pack '%s'.", $pack));
      }

      return;
    }

    if (!Pcre::compiles($pattern)) {
      $validation_result->addError($file, $pointer, sprintf('pattern does not compile: %s', $pattern));
    }
  }

  /**
   * Validates that every security pack reference resolves.
   *
   * @param array<mixed> $data
   *   The parsed `eval.yaml`.
   * @param string $file
   *   The file the data came from.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $validation_result
   *   The result to append errors to.
   */
  protected function validateSecurityPacks(array $data, string $file, ValidationResult $validation_result): void {
    foreach (Data::toStringList(Data::get($data, 'security', 'packs')) as $pack) {
      if (!Packs::isSecurityPack($pack)) {
        $validation_result->addError($file, 'security.packs', sprintf("unknown security pack '%s'.", $pack));
      }
    }
  }

  /**
   * Validates that a declared transcript fixture exists.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $loaded_skill
   *   The loaded skill.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $validation_result
   *   The result to append errors to.
   */
  protected function validateFixture(LoadedSkill $loaded_skill, ValidationResult $validation_result): void {
    $transcript = $loaded_skill->effective->transcript;

    if ($transcript === NULL) {
      return;
    }

    $path = str_starts_with($transcript, '/') ? $transcript : dirname($loaded_skill->file) . '/' . $transcript;

    if (!is_file($path)) {
      $validation_result->addError($loaded_skill->file, 'deterministic.transcript', sprintf('fixture not found: %s', $transcript));
    }
  }

  /**
   * Requires a non-empty rubric whenever a judge is declared.
   *
   * @param array<mixed> $data
   *   The parsed `eval.yaml`.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $loaded_skill
   *   The loaded skill.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $validation_result
   *   The result to append errors to.
   */
  protected function validateRubric(array $data, LoadedSkill $loaded_skill, ValidationResult $validation_result): void {
    $llm = Data::toArray(Data::get($data, 'llm'));

    if (!array_key_exists('judge', $llm)) {
      return;
    }

    if ($loaded_skill->effective->rubric === []) {
      $validation_result->addError($loaded_skill->file, 'llm.judge.rubric', 'rubric must not be empty when a judge is declared.');
    }
  }

  /**
   * Requires the judge abstention policy to be a known value.
   *
   * @param array<mixed> $data
   *   The parsed `eval.yaml`.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $loaded_skill
   *   The loaded skill.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $validation_result
   *   The result to append errors to.
   */
  protected function validateJudgeUnknown(array $data, LoadedSkill $loaded_skill, ValidationResult $validation_result): void {
    $value = Data::get($data, 'llm', 'judge', 'unknown');

    if ($value === NULL) {
      return;
    }

    if (!in_array($value, ['fail', 'ignore'], TRUE)) {
      $validation_result->addError($loaded_skill->file, 'llm.judge.unknown', "must be 'fail' or 'ignore'.");
    }
  }

  /**
   * Validates repo alias and guard patterns.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\RepoConfig $repo_config
   *   The repo configuration.
   * @param string $file
   *   The repo config file path.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $validation_result
   *   The result to append errors to.
   */
  protected function validateRepoPatterns(RepoConfig $repo_config, string $file, ValidationResult $validation_result): void {
    foreach ($repo_config->aliases as $name => $pattern) {
      if (!Pcre::compiles($pattern)) {
        $validation_result->addError($file, 'aliases.' . $name, sprintf('pattern does not compile: %s', $pattern));
      }
    }

    foreach ($repo_config->guards as $label => $pattern) {
      $this->validatePattern($pattern, $file, 'guards.' . $label, $validation_result);
    }
  }

  /**
   * Validates that every declared hook has an existing script.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\RepoConfig $repo_config
   *   The repo configuration.
   * @param string $file
   *   The repo config file path.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $validation_result
   *   The result to append errors to.
   */
  protected function validateHooks(RepoConfig $repo_config, string $file, ValidationResult $validation_result): void {
    foreach ($repo_config->hooks as $index => $hook) {
      $pointer = sprintf('hooks.%d.script', $index);
      $script = Data::toStringOrNull(Data::get($hook, 'script'));

      if ($script === NULL) {
        $validation_result->addError($file, $pointer, 'hook is missing a script.');

        continue;
      }

      $path = str_starts_with($script, '/') ? $script : $this->root . '/' . $script;

      if (!is_file($path)) {
        $validation_result->addError($file, $pointer, sprintf('hook script not found: %s', $script));
      }
    }
  }

  /**
   * Validates that every referenced model alias is defined.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\RepoConfig $repo_config
   *   The repo configuration.
   * @param string $file
   *   The repo config file path.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $validation_result
   *   The result to append errors to.
   */
  protected function validateModelAliases(RepoConfig $repo_config, string $file, ValidationResult $validation_result): void {
    foreach ($repo_config->ladder as $index => $alias) {
      $this->checkAlias($repo_config->modelAliases, $alias, $file, sprintf('models.ladder.%d', $index), $validation_result);
    }

    $this->checkAlias($repo_config->modelAliases, $repo_config->defaultModel, $file, 'models.default', $validation_result);
    $this->checkAlias($repo_config->modelAliases, $repo_config->judgeModel, $file, 'models.judge', $validation_result);
  }

  /**
   * Reports a model reference that is not a defined alias.
   *
   * @param array<string, string> $aliases
   *   The defined model aliases.
   * @param string|null $reference
   *   The referenced alias, or NULL when the position is unset.
   * @param string $file
   *   The repo config file path.
   * @param string $pointer
   *   The dotted pointer to the reference.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $validation_result
   *   The result to append errors to.
   */
  protected function checkAlias(array $aliases, ?string $reference, string $file, string $pointer, ValidationResult $validation_result): void {
    if ($reference !== NULL && !array_key_exists($reference, $aliases)) {
      $validation_result->addError($file, $pointer, sprintf("undefined model alias '%s'.", $reference));
    }
  }

  /**
   * Requires every coverage-gate exclusion to name a skill and give a reason.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\RepoConfig $repo_config
   *   The repo configuration.
   * @param string $file
   *   The repo config file path.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $validation_result
   *   The result to append errors to.
   */
  protected function validateExcludes(RepoConfig $repo_config, string $file, ValidationResult $validation_result): void {
    foreach ($repo_config->excludes as $index => $entry) {
      $pointer = sprintf('paths.exclude.%d', $index);

      if ($entry->skill === '') {
        $validation_result->addError($file, $pointer, 'exclude entry is missing a skill name.');

        continue;
      }

      if ($entry->reason === NULL) {
        $validation_result->addError($file, $pointer, sprintf("excluded skill '%s' is missing a reason.", $entry->skill));
      }
    }
  }

}
