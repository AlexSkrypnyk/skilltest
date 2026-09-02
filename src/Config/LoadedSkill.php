<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Config;

/**
 * One discovered skill after loading: its directory, raw data, merged config.
 *
 * A skill is a directory holding a `SKILL.md`; the `eval.yaml` beside it is an
 * optional sidecar, so a discovered skill that ships without one is still a
 * skill here, with an empty file path and a configuration folded from the repo
 * defaults alone. The raw parsed `eval.yaml` is kept next to the merged
 * {@see EffectiveConfig} so schema checks can inspect the keys as written while
 * coherence checks work from the normalised, merged view.
 */
final readonly class LoadedSkill {

  /**
   * Constructs a LoadedSkill.
   *
   * @param string $file
   *   The `eval.yaml` path, or an empty string when the skill ships none.
   * @param array<mixed> $data
   *   The raw parsed `eval.yaml`.
   * @param \AlexSkrypnyk\SkillTest\Config\EffectiveConfig $effective
   *   The merged effective configuration.
   * @param string $dir
   *   The absolute skill directory.
   */
  public function __construct(
    public string $file,
    public array $data,
    public EffectiveConfig $effective,
    public string $dir,
  ) {}

  /**
   * Whether the skill ships an `eval.yaml`.
   *
   * @return bool
   *   TRUE when an `eval.yaml` was loaded for this skill.
   */
  public function hasEval(): bool {
    return $this->file !== '';
  }

}
