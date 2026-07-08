<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Config;

/**
 * One discovered skill after loading: its file, raw data, and merged config.
 *
 * The raw parsed `eval.yaml` is kept next to the merged {@see EffectiveConfig}
 * so schema checks can inspect the keys as written while coherence checks work
 * from the normalised, merged view.
 */
final readonly class LoadedSkill {

  /**
   * Constructs a LoadedSkill.
   *
   * @param string $file
   *   The `eval.yaml` path.
   * @param array<mixed> $data
   *   The raw parsed `eval.yaml`.
   * @param \AlexSkrypnyk\SkillTest\Config\EffectiveConfig $effective
   *   The merged effective configuration.
   */
  public function __construct(
    public string $file,
    public array $data,
    public EffectiveConfig $effective,
  ) {}

}
