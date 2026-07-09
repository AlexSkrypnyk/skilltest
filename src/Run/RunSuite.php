<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Run;

use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedSkill;
use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Contract\ContractChecker;
use AlexSkrypnyk\SkillTest\Contract\CustomCheck;
use AlexSkrypnyk\SkillTest\Contract\Transcript;
use AlexSkrypnyk\SkillTest\Coverage\Coverage;
use AlexSkrypnyk\SkillTest\Hooks\HookRunner;
use AlexSkrypnyk\SkillTest\Security\SecurityFinding;
use AlexSkrypnyk\SkillTest\Security\SecurityScanner;
use AlexSkrypnyk\SkillTest\Structure\StructureChecker;

/**
 * Executes the deterministic suite over an already-filtered configuration.
 *
 * Drives the four group engines and the coverage gate under one selection:
 * groups the selection excludes never run, a check filter narrows every
 * group's output to the one id, and repo-level hooks run once regardless of
 * how many skills are selected. The engines' process hooks are injectable so
 * the orchestration is unit-testable without spawning a process.
 */
final readonly class RunSuite {

  /**
   * Constructs a RunSuite.
   *
   * @param string $root
   *   The repository root.
   * @param \Closure|null $hookRunner
   *   An override for the hook process runner, for tests.
   * @param \Closure|null $hookReady
   *   An override for the hook script readiness probe, for tests.
   * @param \Closure|null $checkRunner
   *   An override for the custom-check process runner, for tests.
   * @param \Closure|null $commandRunner
   *   An override for the `commands.resolve` binary runner, for tests.
   */
  public function __construct(
    protected string $root,
    protected ?\Closure $hookRunner = NULL,
    protected ?\Closure $hookReady = NULL,
    protected ?\Closure $checkRunner = NULL,
    protected ?\Closure $commandRunner = NULL,
  ) {}

  /**
   * Runs the selected slice of the deterministic suite.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded_config
   *   The loaded configuration, already narrowed to the selected skills.
   * @param \AlexSkrypnyk\SkillTest\Run\RunSelection $selection
   *   The validated selection.
   *
   * @return \AlexSkrypnyk\SkillTest\Run\RunReport
   *   The aggregated run outcome.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the structure group's `commands.resolve` binary cannot run, or a
   *   declared hook script is missing or not executable.
   */
  public function run(LoadedConfig $loaded_config, RunSelection $selection): RunReport {
    $structure = $selection->runs(RunSelection::GROUP_STRUCTURE) ? $this->structureBySkill($loaded_config, $selection) : [];
    $security = $selection->runs(RunSelection::GROUP_SECURITY) ? $this->securityFindings($loaded_config, $selection) : [];

    $skills = [];

    foreach ($loaded_config->skills as $skill) {
      $name = $skill->effective->skill;
      $path = $skill->effective->path;
      [$transcript, $note] = $this->transcriptResults($loaded_config, $skill, $selection);
      $skills[] = new SkillRunResult($name, $path, $structure[$name] ?? [], self::findingsUnder($security, $path), $transcript, $note);
    }

    $hooks = $selection->runs(RunSelection::GROUP_HOOKS) ? $this->hookResults($loaded_config, $selection) : [];
    $coverage = $selection->coverageGateRuns() ? (new Coverage($loaded_config))->violations() : [];

    return new RunReport($skills, $hooks, $coverage);
  }

  /**
   * Runs the structure group and buckets its results by skill name.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded_config
   *   The loaded configuration.
   * @param \AlexSkrypnyk\SkillTest\Run\RunSelection $selection
   *   The validated selection.
   *
   * @return array<string, \AlexSkrypnyk\SkillTest\Structure\StructureResult[]>
   *   The surviving results keyed by skill name.
   */
  protected function structureBySkill(LoadedConfig $loaded_config, RunSelection $selection): array {
    $by_skill = [];

    foreach ((new StructureChecker($this->root, $this->commandRunner))->check($loaded_config) as $result) {
      if ($selection->matches($result->check)) {
        $by_skill[$result->skill][] = $result;
      }
    }

    return $by_skill;
  }

  /**
   * Runs the security group and applies the check filter.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded_config
   *   The loaded configuration.
   * @param \AlexSkrypnyk\SkillTest\Run\RunSelection $selection
   *   The validated selection.
   *
   * @return \AlexSkrypnyk\SkillTest\Security\SecurityFinding[]
   *   The surviving findings.
   */
  protected function securityFindings(LoadedConfig $loaded_config, RunSelection $selection): array {
    $findings = (new SecurityScanner($this->root))->scan($loaded_config);

    return array_values(array_filter($findings, static fn(SecurityFinding $finding): bool => $selection->matches($finding->check)));
  }

  /**
   * Runs the transcript group for one skill: the contract, then custom checks.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded_config
   *   The loaded configuration.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $skill
   *   The skill to grade.
   * @param \AlexSkrypnyk\SkillTest\Run\RunSelection $selection
   *   The validated selection.
   *
   * @return array{0: \AlexSkrypnyk\SkillTest\Contract\CheckResult[], 1: string}
   *   The surviving results and the skip note, one of which is empty.
   */
  protected function transcriptResults(LoadedConfig $loaded_config, LoadedSkill $skill, RunSelection $selection): array {
    if (!$selection->runs(RunSelection::GROUP_TRANSCRIPT)) {
      return [[], ''];
    }

    $fixture = $skill->effective->transcript;

    if ($fixture === NULL) {
      return [[], SkillRunResult::NOTE_NO_TRANSCRIPT];
    }

    $path = str_starts_with($fixture, '/') ? $fixture : dirname($skill->file) . '/' . $fixture;
    $transcript = Transcript::fromFile($path);

    $results = (new ContractChecker($loaded_config->repo->aliases))->check($transcript, $skill->effective->contract);

    $custom = new CustomCheck($this->root, $this->checkRunner);
    foreach ($skill->effective->checks as $entry) {
      $result = $custom->run($entry, $path, dirname($skill->file));

      if ($result instanceof CheckResult) {
        $results[] = $result;
      }
    }

    return [array_values(array_filter($results, static fn(CheckResult $result): bool => $selection->matches($result->id))), ''];
  }

  /**
   * Runs the repo-level hooks group and applies the check filter.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded_config
   *   The loaded configuration.
   * @param \AlexSkrypnyk\SkillTest\Run\RunSelection $selection
   *   The validated selection.
   *
   * @return \AlexSkrypnyk\SkillTest\Contract\CheckResult[]
   *   The surviving hook case results.
   */
  protected function hookResults(LoadedConfig $loaded_config, RunSelection $selection): array {
    $results = (new HookRunner($this->root, $this->hookRunner, $this->hookReady))->run($loaded_config->repo->hooks);

    return array_values(array_filter($results, static fn(CheckResult $result): bool => $selection->matches($result->id)));
  }

  /**
   * Selects the findings located under one skill directory.
   *
   * @param \AlexSkrypnyk\SkillTest\Security\SecurityFinding[] $findings
   *   The findings across every scanned skill.
   * @param string $path
   *   The skill directory, relative to the repository root.
   *
   * @return \AlexSkrypnyk\SkillTest\Security\SecurityFinding[]
   *   The findings whose file lives under the skill directory.
   */
  protected static function findingsUnder(array $findings, string $path): array {
    return array_values(array_filter($findings, static fn(SecurityFinding $finding): bool => str_starts_with($finding->file, $path . '/')));
  }

}
