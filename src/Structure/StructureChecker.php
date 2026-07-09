<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Structure;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Config\Discovery;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedSkill;
use AlexSkrypnyk\SkillTest\Config\SkillFiles;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Validation\ConfigValidator;
use AlexSkrypnyk\SkillTest\Validation\ValidationMessage;

/**
 * The deterministic `structure` group: each skill's files are well-formed.
 *
 * Runs a fixed catalog of pre-baked checks against every loaded skill and its
 * `SKILL.md`, proving the frontmatter parses and is honest, the tool
 * declaration is safe, the body executes nothing before the model reads it, the
 * files it references exist, the commands it names are real, and its own
 * `eval.yaml` is coherent. Every check is default-on and produces a verdict for
 * every skill; a skill may switch one off in `eval.yaml` with a written reason,
 * and that suppression is reported rather than hidden. The one check that runs a
 * process (`command-refs-resolve`) does so through an injected runner, and a
 * binary that cannot run is a hard configuration error, never a silent pass.
 */
final class StructureChecker {

  /**
   * Frontmatter parses with a non-empty name and description.
   */
  public const string CHECK_FRONTMATTER = 'structure.frontmatter';

  /**
   * Frontmatter name equals the skill directory basename.
   */
  public const string CHECK_NAME_MATCHES_DIR = 'structure.name-matches-dir';

  /**
   * Description length is within the configured bounds.
   */
  public const string CHECK_DESCRIPTION_LENGTH = 'structure.description-length';

  /**
   * A declared tool restriction parses to a usable form.
   */
  public const string CHECK_ALLOWED_TOOLS_DECLARED = 'structure.allowed-tools-declared';

  /**
   * The tool declaration never grants unrestricted Bash.
   */
  public const string CHECK_NO_UNRESTRICTED_BASH = 'structure.no-unrestricted-bash';

  /**
   * The body runs no pre-model dynamic-context command.
   */
  public const string CHECK_NO_PRE_MODEL_EXEC = 'structure.no-pre-model-exec';

  /**
   * Every relative file the body references exists in the skill directory.
   */
  public const string CHECK_FILES_EXIST = 'structure.files-exist';

  /**
   * Every `<binary> <subcommand>` reference resolves against the real binary.
   */
  public const string CHECK_COMMAND_REFS_RESOLVE = 'structure.command-refs-resolve';

  /**
   * The skill's own `eval.yaml` passes the coherence rules.
   */
  public const string CHECK_CONTRACT_COHERENT = 'structure.contract-coherent';

  /**
   * The checks in the fixed order they are reported.
   *
   * @var string[]
   */
  public const array CHECKS = [
    self::CHECK_FRONTMATTER,
    self::CHECK_NAME_MATCHES_DIR,
    self::CHECK_DESCRIPTION_LENGTH,
    self::CHECK_ALLOWED_TOOLS_DECLARED,
    self::CHECK_NO_UNRESTRICTED_BASH,
    self::CHECK_NO_PRE_MODEL_EXEC,
    self::CHECK_FILES_EXIST,
    self::CHECK_COMMAND_REFS_RESOLVE,
    self::CHECK_CONTRACT_COHERENT,
  ];

  /**
   * The default minimum description length, in characters.
   */
  public const int DEFAULT_DESCRIPTION_MIN = 16;

  /**
   * The default maximum description length, in characters.
   */
  public const int DEFAULT_DESCRIPTION_MAX = 1024;

  /**
   * The pattern that flags an unrestricted Bash grant in one tool entry.
   *
   * Matches `Bash(*)` and `Bash(:*)` (with optional surrounding whitespace),
   * the wildcard forms that grant every Bash command.
   */
  public const string UNRESTRICTED_BASH_GRANT = '#Bash\(\s*:?\*\s*\)#i';

  /**
   * The pattern that flags a pre-model dynamic-context execution.
   */
  public const string PRE_MODEL_EXEC_PATTERN = '~!`[^`]*`~';

  /**
   * File extensions that mark a bare token as a file reference to check.
   *
   * @var string[]
   */
  public const array KNOWN_EXTENSIONS = [
    'md', 'markdown', 'txt', 'php', 'sh', 'bash', 'yaml', 'yml', 'json',
    'py', 'js', 'ts', 'html', 'css', 'xml', 'ini', 'toml', 'lock', 'dist',
    'png', 'svg', 'jpg', 'jpeg', 'gif',
  ];

  /**
   * The repository root, used to render findings as root-relative paths.
   */
  protected string $root;

  /**
   * Runs the command-list binary, when one is configured; NULL uses the default.
   *
   * @var \Closure(string, string): array{0: int, 1: string}|null
   */
  protected ?\Closure $commandRunner;

  /**
   * Constructs a StructureChecker.
   *
   * @param string $root
   *   The repository root.
   * @param \Closure|null $commandRunner
   *   An optional runner for the `commands.resolve` binary, injected so the
   *   command-reference check is testable without a real process.
   */
  public function __construct(string $root, ?\Closure $commandRunner = NULL) {
    $this->root = rtrim($root, '/');
    $this->commandRunner = $commandRunner;
  }

  /**
   * Runs every structure check against every loaded skill.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded_config
   *   The loaded configuration.
   *
   * @return \AlexSkrypnyk\SkillTest\Structure\StructureResult[]
   *   The results, in skill-then-check order.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When `commands.resolve` is enabled but its binary cannot run or parse.
   */
  public function check(LoadedConfig $loaded_config): array {
    $catalog = $this->catalog($loaded_config);
    $coherence = $this->coherenceErrors($loaded_config);
    $results = [];

    foreach ($loaded_config->skills as $skill) {
      foreach ($this->checkSkill($skill, $catalog, $coherence) as $result) {
        $results[] = $result;
      }
    }

    return $results;
  }

  /**
   * Builds the command catalog when `commands.resolve` is configured.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded_config
   *   The loaded configuration.
   *
   * @return \AlexSkrypnyk\SkillTest\Structure\CommandCatalog|null
   *   The catalog, or NULL when the command-reference check is disabled.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When `commands.resolve` is set but names no binary.
   */
  protected function catalog(LoadedConfig $loaded_config): ?CommandCatalog {
    $resolve = $loaded_config->repo->commandResolve;

    if ($resolve === []) {
      return NULL;
    }

    $binary = Data::toStringOrNull(Data::get($resolve, 'binary'));

    if ($binary === NULL || $binary === '') {
      throw new ConfigException('commands.resolve is set but names no binary to resolve command references against.', $loaded_config->repoFile, CommandCatalog::POINTER);
    }

    return new CommandCatalog($this->root, $binary, Data::toStringList(Data::get($resolve, 'list-args')), $this->commandRunner);
  }

  /**
   * Validates the whole config once and groups the errors by source file.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded_config
   *   The loaded configuration.
   *
   * @return array<string, \AlexSkrypnyk\SkillTest\Validation\ValidationMessage[]>
   *   The coherence errors keyed by the file they were reported against.
   */
  protected function coherenceErrors(LoadedConfig $loaded_config): array {
    $by_file = [];

    foreach ((new ConfigValidator($this->root))->validate($loaded_config)->errors() as $error) {
      $by_file[$error->file][] = $error;
    }

    return $by_file;
  }

  /**
   * Runs the planned checks for one skill, applying suppression.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $loaded_skill
   *   The loaded skill.
   * @param \AlexSkrypnyk\SkillTest\Structure\CommandCatalog|null $catalog
   *   The command catalog, or NULL when the command-reference check is off.
   * @param array<string, \AlexSkrypnyk\SkillTest\Validation\ValidationMessage[]> $coherence
   *   The coherence errors grouped by file.
   *
   * @return \AlexSkrypnyk\SkillTest\Structure\StructureResult[]
   *   The results for this skill.
   */
  protected function checkSkill(LoadedSkill $loaded_skill, ?CommandCatalog $catalog, array $coherence): array {
    $name = $loaded_skill->effective->skill;
    $dir = dirname($loaded_skill->file);
    $skill_md = $dir . '/' . Discovery::MARKER;
    $file = $this->relativePath($skill_md);
    $document = SkillDocument::fromFile($skill_md);
    $suppress = Data::toStringMap(Data::get($loaded_skill->effective->structure, 'suppress'));

    $results = [];

    foreach ($this->plannedChecks($catalog) as $check_id) {
      $reason = $suppress[$check_id] ?? '';

      if ($reason !== '') {
        $results[] = StructureResult::suppressed($check_id, $name, $reason);

        continue;
      }

      $results[] = $this->runCheck($check_id, $loaded_skill, $document, $name, $file, $dir, $catalog, $coherence);
    }

    return $results;
  }

  /**
   * The checks to run for a skill, dropping the disabled command-reference one.
   *
   * @param \AlexSkrypnyk\SkillTest\Structure\CommandCatalog|null $catalog
   *   The command catalog, or NULL when the command-reference check is off.
   *
   * @return string[]
   *   The ordered check ids to run.
   */
  protected function plannedChecks(?CommandCatalog $catalog): array {
    if ($catalog !== NULL) {
      return self::CHECKS;
    }

    return array_values(array_filter(self::CHECKS, static fn(string $check): bool => $check !== self::CHECK_COMMAND_REFS_RESOLVE));
  }

  /**
   * Dispatches one check to its implementation.
   *
   * @param string $check_id
   *   The check id to run.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $loaded_skill
   *   The loaded skill.
   * @param \AlexSkrypnyk\SkillTest\Structure\SkillDocument $document
   *   The parsed `SKILL.md`.
   * @param string $name
   *   The skill name.
   * @param string $file
   *   The `SKILL.md` path relative to the repository root.
   * @param string $dir
   *   The absolute skill directory.
   * @param \AlexSkrypnyk\SkillTest\Structure\CommandCatalog|null $catalog
   *   The command catalog, non-NULL whenever the command-reference check runs.
   * @param array<string, \AlexSkrypnyk\SkillTest\Validation\ValidationMessage[]> $coherence
   *   The coherence errors grouped by file.
   *
   * @return \AlexSkrypnyk\SkillTest\Structure\StructureResult
   *   The result.
   */
  protected function runCheck(string $check_id, LoadedSkill $loaded_skill, SkillDocument $document, string $name, string $file, string $dir, ?CommandCatalog $catalog, array $coherence): StructureResult {
    return match ($check_id) {
      self::CHECK_FRONTMATTER => $this->checkFrontmatter($document, $name, $file),
      self::CHECK_NAME_MATCHES_DIR => $this->checkNameMatchesDir($document, $name, $file, $dir),
      self::CHECK_DESCRIPTION_LENGTH => $this->checkDescriptionLength($loaded_skill, $document, $name, $file),
      self::CHECK_ALLOWED_TOOLS_DECLARED => $this->checkAllowedToolsDeclared($document, $name, $file),
      self::CHECK_NO_UNRESTRICTED_BASH => $this->checkNoUnrestrictedBash($document, $name, $file),
      self::CHECK_NO_PRE_MODEL_EXEC => $this->checkNoPreModelExec($document, $name, $file),
      self::CHECK_FILES_EXIST => $this->checkFilesExist($document, $name, $file, $dir),
      self::CHECK_COMMAND_REFS_RESOLVE => $this->checkCommandRefs($loaded_skill, $name, $dir, $this->requireCatalog($catalog)),
      self::CHECK_CONTRACT_COHERENT => $this->checkContractCoherent($loaded_skill, $name, $coherence),
    };
  }

  /**
   * Asserts a frontmatter block that parses with a name and a description.
   *
   * @param \AlexSkrypnyk\SkillTest\Structure\SkillDocument $document
   *   The parsed `SKILL.md`.
   * @param string $name
   *   The skill name.
   * @param string $file
   *   The `SKILL.md` path relative to the repository root.
   *
   * @return \AlexSkrypnyk\SkillTest\Structure\StructureResult
   *   The result.
   */
  protected function checkFrontmatter(SkillDocument $document, string $name, string $file): StructureResult {
    $id = self::CHECK_FRONTMATTER;

    if (!$document->frontmatterPresent) {
      return StructureResult::fail($id, $name, 'SKILL.md opens with no YAML frontmatter block.', $file, 1);
    }

    if (!$document->frontmatterValid) {
      return StructureResult::fail($id, $name, 'SKILL.md frontmatter does not parse as a YAML mapping.', $file, 1);
    }

    $front_name = Data::toStringOrNull(Data::get($document->frontmatter, 'name'));
    $description = Data::toStringOrNull(Data::get($document->frontmatter, 'description'));

    if ($front_name === NULL || trim($front_name) === '') {
      return StructureResult::fail($id, $name, "frontmatter 'name:' is missing or empty.", $file, 1);
    }

    if ($description === NULL || trim($description) === '') {
      return StructureResult::fail($id, $name, "frontmatter 'description:' is missing or empty.", $file, 1);
    }

    return StructureResult::pass($id, $name, 'frontmatter parses with a name and description.');
  }

  /**
   * Asserts the frontmatter name equals the skill directory basename.
   *
   * @param \AlexSkrypnyk\SkillTest\Structure\SkillDocument $document
   *   The parsed `SKILL.md`.
   * @param string $name
   *   The skill name.
   * @param string $file
   *   The `SKILL.md` path relative to the repository root.
   * @param string $dir
   *   The absolute skill directory.
   *
   * @return \AlexSkrypnyk\SkillTest\Structure\StructureResult
   *   The result.
   */
  protected function checkNameMatchesDir(SkillDocument $document, string $name, string $file, string $dir): StructureResult {
    $id = self::CHECK_NAME_MATCHES_DIR;
    $front_name = Data::toStringOrNull(Data::get($document->frontmatter, 'name'));
    $expected = basename($dir);

    if ($front_name === NULL || $front_name === '') {
      return StructureResult::fail($id, $name, "frontmatter 'name:' is missing, so it cannot match the directory.", $file, 1);
    }

    if ($front_name !== $expected) {
      return StructureResult::fail($id, $name, sprintf("frontmatter name '%s' does not match directory '%s'.", $front_name, $expected), $file, 1, $front_name);
    }

    return StructureResult::pass($id, $name, sprintf("name matches the directory '%s'.", $expected));
  }

  /**
   * Asserts the description length is within the configured bounds.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $loaded_skill
   *   The loaded skill, carrying any `min`/`max` overrides.
   * @param \AlexSkrypnyk\SkillTest\Structure\SkillDocument $document
   *   The parsed `SKILL.md`.
   * @param string $name
   *   The skill name.
   * @param string $file
   *   The `SKILL.md` path relative to the repository root.
   *
   * @return \AlexSkrypnyk\SkillTest\Structure\StructureResult
   *   The result.
   */
  protected function checkDescriptionLength(LoadedSkill $loaded_skill, SkillDocument $document, string $name, string $file): StructureResult {
    $id = self::CHECK_DESCRIPTION_LENGTH;
    $params = Data::toArray(Data::get($loaded_skill->effective->structure, 'params', $id));
    $min = Data::toIntOrNull(Data::get($params, 'min')) ?? self::DEFAULT_DESCRIPTION_MIN;
    $max = Data::toIntOrNull(Data::get($params, 'max')) ?? self::DEFAULT_DESCRIPTION_MAX;
    $description = Data::toStringOrNull(Data::get($document->frontmatter, 'description'));

    if ($description === NULL) {
      return StructureResult::fail($id, $name, "frontmatter 'description:' is missing.", $file, 1);
    }

    $length = mb_strlen(trim($description));

    if ($length < $min) {
      return StructureResult::fail($id, $name, sprintf('description is %d characters, below the minimum of %d.', $length, $min), $file, 1);
    }

    if ($length > $max) {
      return StructureResult::fail($id, $name, sprintf('description is %d characters, above the maximum of %d.', $length, $max), $file, 1);
    }

    return StructureResult::pass($id, $name, sprintf('description length %d is within [%d, %d].', $length, $min, $max));
  }

  /**
   * Asserts a declared tool restriction parses to a string or a scalar list.
   *
   * @param \AlexSkrypnyk\SkillTest\Structure\SkillDocument $document
   *   The parsed `SKILL.md`.
   * @param string $name
   *   The skill name.
   * @param string $file
   *   The `SKILL.md` path relative to the repository root.
   *
   * @return \AlexSkrypnyk\SkillTest\Structure\StructureResult
   *   The result.
   */
  protected function checkAllowedToolsDeclared(SkillDocument $document, string $name, string $file): StructureResult {
    $id = self::CHECK_ALLOWED_TOOLS_DECLARED;

    if (!array_key_exists('allowed-tools', $document->frontmatter)) {
      return StructureResult::pass($id, $name, 'no tool restriction declared.');
    }

    $value = $document->frontmatter['allowed-tools'];

    if (is_string($value) && trim($value) !== '') {
      return StructureResult::pass($id, $name, 'allowed-tools declaration parses.');
    }

    if (is_array($value) && array_is_list($value) && $this->allScalars($value)) {
      return StructureResult::pass($id, $name, 'allowed-tools declaration parses.');
    }

    return StructureResult::fail($id, $name, "frontmatter declares 'allowed-tools' but it does not parse to a tool string or list.", $file, 1);
  }

  /**
   * Asserts the tool declaration never grants unrestricted Bash.
   *
   * @param \AlexSkrypnyk\SkillTest\Structure\SkillDocument $document
   *   The parsed `SKILL.md`.
   * @param string $name
   *   The skill name.
   * @param string $file
   *   The `SKILL.md` path relative to the repository root.
   *
   * @return \AlexSkrypnyk\SkillTest\Structure\StructureResult
   *   The result.
   */
  protected function checkNoUnrestrictedBash(SkillDocument $document, string $name, string $file): StructureResult {
    $id = self::CHECK_NO_UNRESTRICTED_BASH;

    // Inspect the declared tool entries, not the raw document: a `Bash(*)` in
    // the body is documentation, not a grant, and a multiline list form must be
    // caught as surely as the inline string form.
    foreach ($this->allowedToolEntries($document) as [$line, $entry]) {
      if (preg_match(self::UNRESTRICTED_BASH_GRANT, $entry) === 1) {
        return StructureResult::fail($id, $name, 'allowed-tools grants unrestricted Bash access.', $file, $line, $entry);
      }
    }

    return StructureResult::pass($id, $name, 'no unrestricted Bash grant.');
  }

  /**
   * Asserts the body runs no pre-model dynamic-context command.
   *
   * @param \AlexSkrypnyk\SkillTest\Structure\SkillDocument $document
   *   The parsed `SKILL.md`.
   * @param string $name
   *   The skill name.
   * @param string $file
   *   The `SKILL.md` path relative to the repository root.
   *
   * @return \AlexSkrypnyk\SkillTest\Structure\StructureResult
   *   The result.
   */
  protected function checkNoPreModelExec(SkillDocument $document, string $name, string $file): StructureResult {
    $id = self::CHECK_NO_PRE_MODEL_EXEC;
    $match = $this->firstLineMatching($document->body, self::PRE_MODEL_EXEC_PATTERN, $document->bodyStartLine);

    if ($match !== NULL) {
      [$line, $evidence] = $match;

      return StructureResult::fail($id, $name, 'body runs a pre-model dynamic-context command (!`...`).', $file, $line, $evidence);
    }

    return StructureResult::pass($id, $name, 'body runs no pre-model command.');
  }

  /**
   * Asserts every relative file the body references exists in the skill dir.
   *
   * @param \AlexSkrypnyk\SkillTest\Structure\SkillDocument $document
   *   The parsed `SKILL.md`.
   * @param string $name
   *   The skill name.
   * @param string $file
   *   The `SKILL.md` path relative to the repository root.
   * @param string $dir
   *   The absolute skill directory.
   *
   * @return \AlexSkrypnyk\SkillTest\Structure\StructureResult
   *   The result.
   */
  protected function checkFilesExist(SkillDocument $document, string $name, string $file, string $dir): StructureResult {
    $id = self::CHECK_FILES_EXIST;

    foreach ($this->referencedFiles($document) as [$line, $path]) {
      if ($this->escapesDirectory($path)) {
        return StructureResult::fail($id, $name, sprintf("referenced file '%s' escapes the skill directory.", $path), $file, $line, $path);
      }

      if (!file_exists($dir . '/' . $path)) {
        return StructureResult::fail($id, $name, sprintf("referenced file '%s' does not exist in the skill directory.", $path), $file, $line, $path);
      }
    }

    return StructureResult::pass($id, $name, 'every referenced file exists.');
  }

  /**
   * Asserts every `<binary> <subcommand>` reference resolves to a real command.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $loaded_skill
   *   The loaded skill.
   * @param string $name
   *   The skill name.
   * @param string $dir
   *   The absolute skill directory.
   * @param \AlexSkrypnyk\SkillTest\Structure\CommandCatalog $catalog
   *   The resolved command catalog.
   *
   * @return \AlexSkrypnyk\SkillTest\Structure\StructureResult
   *   The result.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the binary cannot run or its output cannot be parsed.
   */
  protected function checkCommandRefs(LoadedSkill $loaded_skill, string $name, string $dir, CommandCatalog $catalog): StructureResult {
    $id = self::CHECK_COMMAND_REFS_RESOLVE;
    $tokens = $catalog->firstTokens();
    $binary = $catalog->binaryName();
    $pattern = '/\b' . preg_quote($binary, '/') . '\s+([A-Za-z][\w:-]*)/';

    foreach (SkillFiles::under($dir) as $absolute) {
      if ($absolute === $loaded_skill->file) {
        continue;
      }

      $contents = @file_get_contents($absolute);

      // @codeCoverageIgnoreStart
      if ($contents === FALSE) {
        continue;
      }
      // @codeCoverageIgnoreEnd
      $relative = $this->relativePath($absolute);

      foreach (explode("\n", $contents) as $index => $line) {
        if (preg_match_all($pattern, $line, $matches, PREG_SET_ORDER) < 1) {
          continue;
        }

        foreach ($matches as $match) {
          $subcommand = $match[1];

          if (!in_array($subcommand, $tokens, TRUE)) {
            return StructureResult::fail($id, $name, sprintf("references '%s %s', but '%s' is not a command the binary has.", $binary, $subcommand, $subcommand), $relative, $index + 1, trim($line));
          }
        }
      }
    }

    return StructureResult::pass($id, $name, 'every command reference resolves.');
  }

  /**
   * Asserts the skill's own `eval.yaml` passes the coherence rules.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $loaded_skill
   *   The loaded skill.
   * @param string $name
   *   The skill name.
   * @param array<string, \AlexSkrypnyk\SkillTest\Validation\ValidationMessage[]> $coherence
   *   The coherence errors grouped by file.
   *
   * @return \AlexSkrypnyk\SkillTest\Structure\StructureResult
   *   The result.
   */
  protected function checkContractCoherent(LoadedSkill $loaded_skill, string $name, array $coherence): StructureResult {
    $id = self::CHECK_CONTRACT_COHERENT;
    $errors = $coherence[$loaded_skill->file] ?? [];
    $file = $this->relativePath($loaded_skill->file);

    if ($errors === []) {
      return StructureResult::pass($id, $name, 'eval.yaml is coherent.');
    }

    $first = $errors[0];
    $evidence = $first->pointer === '' ? $first->message : $first->pointer . ': ' . $first->message;

    return StructureResult::fail($id, $name, sprintf('eval.yaml has %d coherence error(s).', count($errors)), $file, 0, $evidence);
  }

  /**
   * Extracts the checkable file references from a document body, with lines.
   *
   * @param \AlexSkrypnyk\SkillTest\Structure\SkillDocument $document
   *   The parsed `SKILL.md`.
   *
   * @return array<int, array{0: int, 1: string}>
   *   Each reference as its 1-based file line and its cleaned relative path.
   */
  protected function referencedFiles(SkillDocument $document): array {
    $references = [];

    foreach (explode("\n", $document->body) as $index => $line) {
      $number = $document->bodyStartLine + $index;

      foreach ($this->candidatesInLine($line) as $path) {
        $references[] = [$number, $path];
      }
    }

    return $references;
  }

  /**
   * Extracts the checkable path candidates from a single body line.
   *
   * Considers two forms: markdown-link targets (`](path)`) and inline-code
   * spans (`` `path` ``). Each is cleaned and filtered down to a relative file
   * path worth checking, so prose, commands, and URLs are ignored.
   *
   * @param string $line
   *   The body line.
   *
   * @return string[]
   *   The cleaned relative paths referenced on the line.
   */
  protected function candidatesInLine(string $line): array {
    $paths = [];

    if (preg_match_all('/\]\(([^)]+)\)/', $line, $links) > 0) {
      foreach ($links[1] as $raw) {
        $path = $this->checkablePath($raw, TRUE);

        if ($path !== NULL) {
          $paths[] = $path;
        }
      }
    }

    if (preg_match_all('/`([^`]+)`/', $line, $spans) > 0) {
      foreach ($spans[1] as $raw) {
        $path = $this->checkablePath($raw, FALSE);

        if ($path !== NULL) {
          $paths[] = $path;
        }
      }
    }

    return $paths;
  }

  /**
   * Narrows a raw reference to a checkable relative path, or NULL.
   *
   * @param string $raw
   *   The raw captured reference.
   * @param bool $is_link
   *   TRUE when the reference came from a markdown link, so a trailing title
   *   and a URL fragment are stripped.
   *
   * @return string|null
   *   The relative path to check, or NULL when it is not a local file path.
   */
  protected function checkablePath(string $raw, bool $is_link): ?string {
    $path = trim($raw);

    if ($is_link) {
      $path = trim((string) preg_replace('/\s+(["\']).*\1\s*$/', '', $path));
      $hash = strpos($path, '#');

      if ($hash !== FALSE) {
        $path = substr($path, 0, $hash);
      }
    }

    if (str_starts_with($path, './')) {
      $path = substr($path, 2);
    }

    if ($path === '') {
      return NULL;
    }

    if (str_starts_with($path, '/') || str_starts_with($path, '~') || str_starts_with($path, '#')) {
      return NULL;
    }

    if (str_contains($path, '://') || str_starts_with($path, 'mailto:')) {
      return NULL;
    }

    if (preg_match('#^[\w./-]+$#', $path) !== 1) {
      return NULL;
    }

    if (!str_contains($path, '/') && !$this->hasKnownExtension($path)) {
      return NULL;
    }

    return $path;
  }

  /**
   * Whether a relative path steps outside the skill directory.
   *
   * A `..` path segment points at a parent, so the reference is to a file the
   * skill does not ship; the check requires references to resolve inside the
   * skill directory, so such a path fails rather than being followed.
   *
   * @param string $path
   *   The candidate path.
   *
   * @return bool
   *   TRUE when a parent-directory segment is present.
   */
  protected function escapesDirectory(string $path): bool {
    return preg_match('#(^|/)\.\.(/|$)#', $path) === 1;
  }

  /**
   * Whether a bare token ends in a recognised file extension.
   *
   * @param string $path
   *   The candidate path.
   *
   * @return bool
   *   TRUE when the extension is one that marks a file reference.
   */
  protected function hasKnownExtension(string $path): bool {
    if (preg_match('/\.([a-z0-9]+)$/i', $path, $matches) !== 1) {
      return FALSE;
    }

    return in_array(strtolower($matches[1]), self::KNOWN_EXTENSIONS, TRUE);
  }

  /**
   * Returns the first line of a text that matches a pattern, with its number.
   *
   * @param string $text
   *   The text to scan.
   * @param string $pattern
   *   The delimited pattern.
   * @param int $base_line
   *   The 1-based file line the text's first line corresponds to.
   *
   * @return array{0: int, 1: string}|null
   *   The 1-based file line and the trimmed matching line, or NULL.
   */
  protected function firstLineMatching(string $text, string $pattern, int $base_line): ?array {
    foreach (explode("\n", $text) as $index => $line) {
      if (preg_match($pattern, $line) === 1) {
        return [$base_line + $index, trim($line)];
      }
    }

    return NULL;
  }

  /**
   * The declared allowed-tools entries as [file line, entry] pairs.
   *
   * Reads the parsed frontmatter value in either form - a comma-separated
   * string or a YAML list - and pairs each entry with the file line it appears
   * on, so a finding can point at the offending declaration.
   *
   * @param \AlexSkrypnyk\SkillTest\Structure\SkillDocument $document
   *   The parsed `SKILL.md`.
   *
   * @return array<int, array{0: int, 1: string}>
   *   Each entry as its 1-based file line and its trimmed text.
   */
  protected function allowedToolEntries(SkillDocument $document): array {
    if (!array_key_exists('allowed-tools', $document->frontmatter)) {
      return [];
    }

    $value = $document->frontmatter['allowed-tools'];
    $raw = [];

    if (is_string($value)) {
      $raw = explode(',', $value);
    }
    elseif (is_array($value)) {
      $raw = Data::toStringList($value);
    }

    $entries = [];

    foreach ($raw as $part) {
      $entry = trim($part);

      if ($entry !== '') {
        $entries[] = [$this->lineContaining($document->content, $entry), $entry];
      }
    }

    return $entries;
  }

  /**
   * The 1-based file line a needle first appears on, or 1 when it is absent.
   *
   * @param string $content
   *   The full document content.
   * @param string $needle
   *   The substring to locate.
   *
   * @return int
   *   The 1-based line number.
   */
  protected function lineContaining(string $content, string $needle): int {
    foreach (explode("\n", $content) as $index => $line) {
      if (str_contains($line, $needle)) {
        return $index + 1;
      }
    }

    // @codeCoverageIgnoreStart
    return 1;
    // @codeCoverageIgnoreEnd
  }

  /**
   * Whether every element of a list is a scalar.
   *
   * @param array<mixed> $value
   *   The list to inspect.
   *
   * @return bool
   *   TRUE when the list contains only scalars.
   */
  protected function allScalars(array $value): bool {
    foreach ($value as $item) {
      if (!is_scalar($item)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Narrows a nullable catalog to a non-null one for the command-reference run.
   *
   * @param \AlexSkrypnyk\SkillTest\Structure\CommandCatalog|null $catalog
   *   The catalog, guaranteed non-NULL by the planning step that gates the run.
   *
   * @return \AlexSkrypnyk\SkillTest\Structure\CommandCatalog
   *   The catalog.
   */
  protected function requireCatalog(?CommandCatalog $catalog): CommandCatalog {
    // @codeCoverageIgnoreStart
    if ($catalog === NULL) {
      throw new \LogicException('The command-reference check ran without a catalog.');
    }
    // @codeCoverageIgnoreEnd
    return $catalog;
  }

  /**
   * Renders an absolute path relative to the repository root.
   *
   * @param string $absolute
   *   The absolute path.
   *
   * @return string
   *   The root-relative path.
   */
  protected function relativePath(string $absolute): string {
    $prefix = $this->root . '/';

    if (str_starts_with($absolute, $prefix)) {
      return substr($absolute, strlen($prefix));
    }

    // @codeCoverageIgnoreStart
    return $absolute;
    // @codeCoverageIgnoreEnd
  }

}
