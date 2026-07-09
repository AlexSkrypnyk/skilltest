<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Run;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedSkill;
use AlexSkrypnyk\SkillTest\Hooks\HookRunner;
use AlexSkrypnyk\SkillTest\Security\SecurityScanner;
use AlexSkrypnyk\SkillTest\Structure\StructureChecker;

/**
 * Enumerates the checks a selection would run, without running anything.
 *
 * The plan is the `--list` answer: it names every check id per group per
 * skill from configuration and the engines' published catalogs alone, so no
 * hook script, custom check, or `commands.resolve` binary ever executes while
 * planning. Suppressed structure checks are named as suppressed rather than
 * omitted, matching how reports treat suppression.
 */
final readonly class RunPlan {

  /**
   * Constructs a RunPlan.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loadedConfig
   *   The loaded configuration, already narrowed to the selected skills.
   * @param \AlexSkrypnyk\SkillTest\Run\RunSelection $selection
   *   The validated selection.
   */
  public function __construct(
    protected LoadedConfig $loadedConfig,
    protected RunSelection $selection,
  ) {}

  /**
   * Describes the selected slice of the suite as a renderable structure.
   *
   * @return array{groups: string[], coverage: bool, skills: list<array{skill: string, path: string, groups: array<string, string[]>}>, hooks: string[]}
   *   The plan: the selected groups, whether the coverage gate applies, one
   *   entry per skill with its per-group check lines, and the hook lines.
   */
  public function describe(): array {
    $groups = array_values(array_filter(RunSelection::GROUPS, fn(string $group): bool => $this->selection->runs($group)));

    return [
      'groups' => $groups,
      'coverage' => $this->selection->coverageGateRuns(),
      'skills' => array_map($this->skillPlan(...), $this->loadedConfig->skills),
      'hooks' => $this->selection->runs(RunSelection::GROUP_HOOKS) ? $this->hookLines() : [],
    ];
  }

  /**
   * Plans one skill's per-group check lines.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $skill
   *   The loaded skill.
   *
   * @return array{skill: string, path: string, groups: array<string, string[]>}
   *   The skill name, path, and per-group lines.
   */
  protected function skillPlan(LoadedSkill $skill): array {
    $groups = [];

    if ($this->selection->runs(RunSelection::GROUP_STRUCTURE)) {
      $groups[RunSelection::GROUP_STRUCTURE] = $this->structureLines($skill);
    }

    if ($this->selection->runs(RunSelection::GROUP_SECURITY)) {
      $groups[RunSelection::GROUP_SECURITY] = $this->securityLines($skill);
    }

    if ($this->selection->runs(RunSelection::GROUP_TRANSCRIPT)) {
      $groups[RunSelection::GROUP_TRANSCRIPT] = $this->transcriptLines($skill);
    }

    return ['skill' => $skill->effective->skill, 'path' => $skill->effective->path, 'groups' => $groups];
  }

  /**
   * Plans the structure check lines for one skill.
   *
   * Mirrors the checker's own planning: the command-reference check is listed
   * only when `commands.resolve` is configured, and a suppressed check is
   * named with its written reason.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $skill
   *   The loaded skill.
   *
   * @return string[]
   *   The planned lines.
   */
  protected function structureLines(LoadedSkill $skill): array {
    $checks = StructureChecker::CHECKS;

    if ($this->loadedConfig->repo->commandResolve === []) {
      $checks = array_values(array_filter($checks, static fn(string $check): bool => $check !== StructureChecker::CHECK_COMMAND_REFS_RESOLVE));
    }

    $suppress = Data::toStringMap(Data::get($skill->effective->structure, 'suppress'));
    $lines = [];

    foreach ($checks as $check) {
      if (!$this->selection->matches($check)) {
        continue;
      }

      $reason = $suppress[$check] ?? '';
      $lines[] = $reason === '' ? $check : sprintf('%s (suppressed: %s)', $check, $reason);
    }

    return $lines;
  }

  /**
   * Plans the security check lines for one skill.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $skill
   *   The loaded skill.
   *
   * @return string[]
   *   The planned lines: the baseline pack ids, plus the forbidden-tokens
   *   check when the skill declares tokens.
   */
  protected function securityLines(LoadedSkill $skill): array {
    $checks = array_map(static fn(array $pattern): string => $pattern[0], SecurityScanner::BASELINE_PATTERNS);

    if (Data::toStringList(Data::get($skill->effective->security, 'forbidden-tokens')) !== []) {
      $checks[] = SecurityScanner::FORBIDDEN_TOKEN_CHECK;
    }

    return array_values(array_filter($checks, fn(string $check): bool => $this->selection->matches($check)));
  }

  /**
   * Plans the transcript check lines for one skill.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $skill
   *   The loaded skill.
   *
   * @return string[]
   *   The planned lines: one per contract assertion and custom check, or the
   *   skip note when no transcript fixture is declared.
   */
  protected function transcriptLines(LoadedSkill $skill): array {
    if ($skill->effective->transcript === NULL) {
      return [sprintf('(%s)', RunSuite::NOTE_NO_TRANSCRIPT)];
    }

    $contract = $skill->effective->contract;
    $lines = [];

    foreach (['tools' => 'tool', 'skills' => 'skill'] as $section => $noun) {
      foreach (['required', 'forbidden'] as $position) {
        foreach (Data::toStringList(Data::get($contract, $section, $position)) as $name) {
          $lines[] = [sprintf('contract.%s.%s', $section, $position), sprintf('%s %s', $noun, $name)];
        }
      }
    }

    foreach (['required', 'forbidden'] as $position) {
      foreach (array_keys(Data::toStringMap(Data::get($contract, 'commands', $position))) as $label) {
        $lines[] = [sprintf('contract.commands.%s', $position), (string) $label];
      }
    }

    foreach ($skill->effective->checks as $entry) {
      $name = Data::toStringOrNull(Data::get($entry, 'name'));

      if ($name !== NULL && $name !== '') {
        $lines[] = ['check.' . $name, ''];
      }
    }

    $selected = array_filter($lines, fn(array $line): bool => $this->selection->matches($line[0]));

    return array_values(array_map(static fn(array $line): string => $line[1] === '' ? $line[0] : sprintf('%s (%s)', $line[0], $line[1]), $selected));
  }

  /**
   * Plans the repo-level hook lines.
   *
   * @return string[]
   *   One line per declared hook, naming its check id and case count.
   */
  protected function hookLines(): array {
    $lines = [];

    foreach ($this->loadedConfig->repo->hooks as $hook) {
      $script = Data::toStringOrNull(Data::get($hook, 'script'));

      if ($script === NULL || $script === '') {
        continue;
      }

      $id = HookRunner::ID_PREFIX . pathinfo($script, PATHINFO_FILENAME);

      if (!$this->selection->matches($id)) {
        continue;
      }

      $lines[] = sprintf('%s (%d case(s))', $id, count(Data::toArrayList(Data::get($hook, 'cases'))));
    }

    return $lines;
  }

}
