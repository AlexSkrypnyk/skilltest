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
final class ConfigValidator {

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
    'deterministic' => ['transcript' => TRUE],
    'llm' => [
      'tasks' => TRUE,
      'max-turns' => TRUE,
      'trials' => TRUE,
      'threshold' => TRUE,
      'models' => TRUE,
      'judge' => ['rubric' => TRUE],
      'checks' => TRUE,
    ],
    'inputs' => TRUE,
  ];

  /**
   * The repository root, used to resolve hook script paths.
   */
  protected readonly string $root;

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
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded
   *   The loaded configuration.
   *
   * @return \AlexSkrypnyk\SkillTest\Validation\ValidationResult
   *   The accumulated findings.
   */
  public function validate(LoadedConfig $loaded): ValidationResult {
    $result = new ValidationResult();

    if ($loaded->repoFile !== '') {
      $this->checkUnknownKeys($loaded->repoData, self::REPO_SCHEMA, $loaded->repoFile, '', $result);
      $this->validateRepoPatterns($loaded->repo, $loaded->repoFile, $result);
      $this->validateHooks($loaded->repo, $loaded->repoFile, $result);
      $this->validateModelAliases($loaded->repo, $loaded->repoFile, $result);
    }

    foreach ($loaded->skills as $skill) {
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
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $result
   *   The result to append warnings to.
   */
  protected function checkUnknownKeys(array $data, array $schema, string $file, string $prefix, ValidationResult $result): void {
    foreach ($data as $key => $value) {
      $key = (string) $key;
      $pointer = $prefix === '' ? $key : $prefix . '.' . $key;

      if (!array_key_exists($key, $schema)) {
        $result->addWarning($file, $pointer, 'unknown key (ignored).');

        continue;
      }

      $sub_schema = $schema[$key];

      if (is_array($sub_schema) && is_array($value)) {
        $this->checkUnknownKeys($value, $sub_schema, $file, $pointer, $result);
      }
    }
  }

  /**
   * Runs the per-skill coherence checks.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $skill
   *   The loaded skill.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $result
   *   The result to append findings to.
   */
  protected function validateSkill(LoadedSkill $skill, ValidationResult $result): void {
    $file = $skill->file;
    $data = $skill->data;
    $contract = Data::toArray(Data::get($data, 'contract'));

    $this->disjointList(
      Data::toStringList(Data::get($contract, 'tools', 'required')),
      Data::toStringList(Data::get($contract, 'tools', 'forbidden')),
      $file,
      'contract.tools',
      'tool',
      $result,
    );
    $this->disjointList(
      Data::toStringList(Data::get($contract, 'skills', 'required')),
      Data::toStringList(Data::get($contract, 'skills', 'forbidden')),
      $file,
      'contract.skills',
      'skill',
      $result,
    );

    $this->disjointCommands($contract, $file, $result);
    $this->validateCommandPatterns($contract, $file, $result);
    $this->validateSecurityPacks($data, $file, $result);
    $this->validateFixture($skill, $result);
    $this->validateRubric($data, $skill, $result);
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
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $result
   *   The result to append errors to.
   */
  protected function disjointList(array $required, array $forbidden, string $file, string $pointer, string $noun, ValidationResult $result): void {
    foreach (array_intersect($required, $forbidden) as $name) {
      $result->addError($file, $pointer, sprintf("%s '%s' is in both required and forbidden.", $noun, $name));
    }
  }

  /**
   * Reports command labels or patterns present in both required and forbidden.
   *
   * @param array<mixed> $contract
   *   The contract block.
   * @param string $file
   *   The file the contract came from.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $result
   *   The result to append errors to.
   */
  protected function disjointCommands(array $contract, string $file, ValidationResult $result): void {
    $required = Data::toStringMap(Data::get($contract, 'commands', 'required'));
    $forbidden = Data::toStringMap(Data::get($contract, 'commands', 'forbidden'));

    foreach (array_intersect(array_keys($required), array_keys($forbidden)) as $label) {
      $result->addError($file, 'contract.commands', sprintf("command '%s' is in both required and forbidden.", $label));
    }

    foreach (array_intersect(array_values($required), array_values($forbidden)) as $pattern) {
      $result->addError($file, 'contract.commands', sprintf("command pattern '%s' is in both required and forbidden.", $pattern));
    }
  }

  /**
   * Validates that every command pattern compiles or resolves to a pack.
   *
   * @param array<mixed> $contract
   *   The contract block.
   * @param string $file
   *   The file the contract came from.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $result
   *   The result to append errors to.
   */
  protected function validateCommandPatterns(array $contract, string $file, ValidationResult $result): void {
    $commands = Data::toArray(Data::get($contract, 'commands'));

    foreach (['required', 'forbidden'] as $kind) {
      foreach (Data::toStringMap(Data::get($commands, $kind)) as $label => $pattern) {
        $this->validatePattern($pattern, $file, sprintf('contract.commands.%s.%s', $kind, $label), $result);
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
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $result
   *   The result to append errors to.
   */
  protected function validatePattern(string $pattern, string $file, string $pointer, ValidationResult $result): void {
    $pack = Packs::reference($pattern);

    if ($pack !== NULL) {
      if (!Packs::isPatternPack($pack)) {
        $result->addError($file, $pointer, sprintf("unknown pattern pack '%s'.", $pack));
      }

      return;
    }

    if (!Pcre::compiles($pattern)) {
      $result->addError($file, $pointer, sprintf('pattern does not compile: %s', $pattern));
    }
  }

  /**
   * Validates that every security pack reference resolves.
   *
   * @param array<mixed> $data
   *   The parsed `eval.yaml`.
   * @param string $file
   *   The file the data came from.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $result
   *   The result to append errors to.
   */
  protected function validateSecurityPacks(array $data, string $file, ValidationResult $result): void {
    foreach (Data::toStringList(Data::get($data, 'security', 'packs')) as $pack) {
      if (!Packs::isSecurityPack($pack)) {
        $result->addError($file, 'security.packs', sprintf("unknown security pack '%s'.", $pack));
      }
    }
  }

  /**
   * Validates that a declared transcript fixture exists.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $skill
   *   The loaded skill.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $result
   *   The result to append errors to.
   */
  protected function validateFixture(LoadedSkill $skill, ValidationResult $result): void {
    $transcript = $skill->effective->transcript;

    if ($transcript === NULL) {
      return;
    }

    $path = str_starts_with($transcript, '/') ? $transcript : dirname($skill->file) . '/' . $transcript;

    if (!is_file($path)) {
      $result->addError($skill->file, 'deterministic.transcript', sprintf('fixture not found: %s', $transcript));
    }
  }

  /**
   * Requires a non-empty rubric whenever a judge is declared.
   *
   * @param array<mixed> $data
   *   The parsed `eval.yaml`.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $skill
   *   The loaded skill.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $result
   *   The result to append errors to.
   */
  protected function validateRubric(array $data, LoadedSkill $skill, ValidationResult $result): void {
    $llm = Data::toArray(Data::get($data, 'llm'));

    if (!array_key_exists('judge', $llm)) {
      return;
    }

    if ($skill->effective->rubric === []) {
      $result->addError($skill->file, 'llm.judge.rubric', 'rubric must not be empty when a judge is declared.');
    }
  }

  /**
   * Validates repo alias and guard patterns.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\RepoConfig $repo
   *   The repo configuration.
   * @param string $file
   *   The repo config file path.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $result
   *   The result to append errors to.
   */
  protected function validateRepoPatterns(RepoConfig $repo, string $file, ValidationResult $result): void {
    foreach ($repo->aliases as $name => $pattern) {
      if (!Pcre::compiles($pattern)) {
        $result->addError($file, 'aliases.' . $name, sprintf('pattern does not compile: %s', $pattern));
      }
    }

    foreach ($repo->guards as $label => $pattern) {
      $this->validatePattern($pattern, $file, 'guards.' . $label, $result);
    }
  }

  /**
   * Validates that every declared hook has an existing script.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\RepoConfig $repo
   *   The repo configuration.
   * @param string $file
   *   The repo config file path.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $result
   *   The result to append errors to.
   */
  protected function validateHooks(RepoConfig $repo, string $file, ValidationResult $result): void {
    foreach ($repo->hooks as $index => $hook) {
      $pointer = sprintf('hooks.%d.script', $index);
      $script = Data::toStringOrNull(Data::get($hook, 'script'));

      if ($script === NULL) {
        $result->addError($file, $pointer, 'hook is missing a script.');

        continue;
      }

      $path = str_starts_with($script, '/') ? $script : $this->root . '/' . $script;

      if (!is_file($path)) {
        $result->addError($file, $pointer, sprintf('hook script not found: %s', $script));
      }
    }
  }

  /**
   * Validates that every referenced model alias is defined.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\RepoConfig $repo
   *   The repo configuration.
   * @param string $file
   *   The repo config file path.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $result
   *   The result to append errors to.
   */
  protected function validateModelAliases(RepoConfig $repo, string $file, ValidationResult $result): void {
    foreach ($repo->ladder as $index => $alias) {
      $this->checkAlias($repo->modelAliases, $alias, $file, sprintf('models.ladder.%d', $index), $result);
    }

    $this->checkAlias($repo->modelAliases, $repo->defaultModel, $file, 'models.default', $result);
    $this->checkAlias($repo->modelAliases, $repo->judgeModel, $file, 'models.judge', $result);
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
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $result
   *   The result to append errors to.
   */
  protected function checkAlias(array $aliases, ?string $reference, string $file, string $pointer, ValidationResult $result): void {
    if ($reference !== NULL && !array_key_exists($reference, $aliases)) {
      $result->addError($file, $pointer, sprintf("undefined model alias '%s'.", $reference));
    }
  }

}
