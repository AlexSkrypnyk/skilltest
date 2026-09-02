<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Config;

/**
 * The whole repository configuration after a successful load.
 *
 * Bundles the repo config (typed and raw) with every discovered skill, split by
 * whether it loaded an `eval.yaml`, ready for schema and coherence validation.
 * A load that reaches this point has already passed the hard gates (parse,
 * schema major); everything left is a finding, not a fatal error.
 */
final readonly class LoadedConfig {

  /**
   * Constructs a LoadedConfig.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\RepoConfig $repo
   *   The typed repo configuration.
   * @param array<mixed> $repoData
   *   The raw parsed `skilltest.yml`, or an empty array when absent.
   * @param string $repoFile
   *   The `skilltest.yml` path, or an empty string when absent.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill[] $skills
   *   The discovered skills that loaded an `eval.yaml`.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill[] $skillsWithoutEval
   *   The discovered skills that have no `eval.yaml`, each carrying the
   *   configuration folded from the repo defaults.
   */
  public function __construct(
    public RepoConfig $repo,
    public array $repoData,
    public string $repoFile,
    public array $skills,
    public array $skillsWithoutEval = [],
  ) {}

  /**
   * Returns every discovered skill, configured or not, in path order.
   *
   * Engines that read a skill's files rather than its `eval.yaml` work from
   * this list, so a skill with no configuration is still checked.
   *
   * @return \AlexSkrypnyk\SkillTest\Config\LoadedSkill[]
   *   The skills, sorted by directory so reports over them are deterministic.
   */
  public function allSkills(): array {
    $all = array_merge($this->skills, $this->skillsWithoutEval);

    usort($all, static fn(LoadedSkill $a, LoadedSkill $b): int => strcmp($a->effective->path, $b->effective->path));

    return $all;
  }

}
