<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Run;

use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedSkill;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;

/**
 * The validated selection a run applies: skill globs, one group, one check.
 *
 * Selection is resolved once, up front, so an impossible request - an unknown
 * group, a check id no group owns, or a check that contradicts the requested
 * group - fails as a configuration error before anything runs. The check id's
 * prefix decides which group owns it, so `--check` alone is enough to narrow
 * the run to the one group that can produce it.
 */
final readonly class RunSelection {

  /**
   * The structure group name.
   */
  public const string GROUP_STRUCTURE = 'structure';

  /**
   * The security group name.
   */
  public const string GROUP_SECURITY = 'security';

  /**
   * The hooks group name.
   */
  public const string GROUP_HOOKS = 'hooks';

  /**
   * The transcript group name.
   */
  public const string GROUP_TRANSCRIPT = 'transcript';

  /**
   * The selectable groups, in suite order.
   */
  public const array GROUPS = [
    self::GROUP_STRUCTURE,
    self::GROUP_SECURITY,
    self::GROUP_HOOKS,
    self::GROUP_TRANSCRIPT,
  ];

  /**
   * The owning group of every known check-id prefix.
   *
   * Contract assertions and custom checks both grade the recorded transcript,
   * so both prefixes belong to the transcript group.
   */
  public const array CHECK_PREFIXES = [
    'structure.' => self::GROUP_STRUCTURE,
    'security.' => self::GROUP_SECURITY,
    'hooks.' => self::GROUP_HOOKS,
    'contract.' => self::GROUP_TRANSCRIPT,
    'check.' => self::GROUP_TRANSCRIPT,
  ];

  /**
   * Constructs a RunSelection.
   *
   * @param string[] $globs
   *   The skill name globs; empty selects every discovered skill.
   * @param string|null $group
   *   The one group to run, or NULL for the whole suite.
   * @param string|null $check
   *   The one check id to run, or NULL for every check.
   */
  public function __construct(
    public array $globs,
    public ?string $group,
    public ?string $check,
  ) {}

  /**
   * Builds a selection, rejecting impossible group and check requests.
   *
   * @param string[] $globs
   *   The skill name globs; empty selects every discovered skill.
   * @param string|null $group
   *   The requested group, or NULL.
   * @param string|null $check
   *   The requested check id, or NULL.
   *
   * @return self
   *   The validated selection.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the group is unknown, the check id has no owning group, or the
   *   check belongs to a different group than the one requested.
   */
  public static function create(array $globs, ?string $group, ?string $check): self {
    if ($group !== NULL && !in_array($group, self::GROUPS, TRUE)) {
      throw new ConfigException(sprintf("unknown group '%s'; expected one of: %s.", $group, implode(', ', self::GROUPS)));
    }

    $owner = NULL;

    if ($check !== NULL) {
      $owner = self::ownerGroup($check);

      if ($owner === NULL) {
        throw new ConfigException(sprintf("unknown check id '%s'; expected a %s prefix.", $check, implode(', ', array_keys(self::CHECK_PREFIXES))));
      }
    }

    if ($group !== NULL && $owner !== NULL && $owner !== $group) {
      throw new ConfigException(sprintf("check '%s' belongs to group '%s', not '%s'.", $check, $owner, $group));
    }

    return new self(array_values($globs), $group, $check);
  }

  /**
   * Resolves the group that owns a check id from its prefix.
   *
   * @param string $check
   *   The check id.
   *
   * @return string|null
   *   The owning group, or NULL when no known prefix matches.
   */
  public static function ownerGroup(string $check): ?string {
    foreach (self::CHECK_PREFIXES as $prefix => $group) {
      if (str_starts_with($check, $prefix)) {
        return $group;
      }
    }

    return NULL;
  }

  /**
   * Whether a group is part of this selection.
   *
   * @param string $group
   *   The group name.
   *
   * @return bool
   *   TRUE when the group should run.
   */
  public function runs(string $group): bool {
    $effective = $this->group ?? ($this->check === NULL ? NULL : self::ownerGroup($this->check));

    return $effective === NULL || $effective === $group;
  }

  /**
   * Whether the coverage gate is part of this selection.
   *
   * The gate is not a group: it belongs to the full suite only, so any group
   * or check narrowing switches it off.
   *
   * @return bool
   *   TRUE when the gate should run.
   */
  public function coverageGateRuns(): bool {
    return $this->group === NULL && $this->check === NULL;
  }

  /**
   * Whether a produced check id survives the check filter.
   *
   * @param string $check_id
   *   The check id a result carries.
   *
   * @return bool
   *   TRUE when no check filter is set or the id matches it exactly.
   */
  public function matches(string $check_id): bool {
    return $this->check === NULL || $this->check === $check_id;
  }

  /**
   * Narrows a loaded configuration to the skills the globs select.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded_config
   *   The loaded configuration.
   *
   * @return \AlexSkrypnyk\SkillTest\Config\LoadedConfig
   *   The configuration containing only the selected skills, both with and
   *   without an `eval.yaml`.
   */
  public function filter(LoadedConfig $loaded_config): LoadedConfig {
    if ($this->globs === []) {
      return $loaded_config;
    }

    $skills = array_values(array_filter($loaded_config->skills, fn(LoadedSkill $skill): bool => $this->matchesGlobs($skill->effective->skill)));
    $without_eval = array_values(array_filter($loaded_config->skillsWithoutEval, fn(string $dir): bool => $this->matchesGlobs(basename($dir))));

    return new LoadedConfig($loaded_config->repo, $loaded_config->repoData, $loaded_config->repoFile, $skills, $without_eval);
  }

  /**
   * Whether a skill name matches any of the selection globs.
   *
   * @param string $name
   *   The skill name.
   *
   * @return bool
   *   TRUE when any glob matches.
   */
  protected function matchesGlobs(string $name): bool {
    foreach ($this->globs as $glob) {
      if (fnmatch($glob, $name)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
