<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Config;

/**
 * The whole repository configuration after a successful load.
 *
 * Bundles the repo config (typed and raw) with every discovered, loaded skill,
 * ready for schema and coherence validation. A load that reaches this point has
 * already passed the hard gates (parse, schema major); everything left is a
 * finding, not a fatal error.
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
   *   The discovered, loaded skills.
   * @param string[] $skillsWithoutEval
   *   The root-relative directories of discovered skills that have no
   *   `eval.yaml`; the coverage gate names each one that is not excluded.
   */
  public function __construct(
    public RepoConfig $repo,
    public array $repoData,
    public string $repoFile,
    public array $skills,
    public array $skillsWithoutEval = [],
  ) {}

}
