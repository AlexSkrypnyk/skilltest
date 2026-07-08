<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Config;

/**
 * One discovered skill after loading: its file, raw data, and merged config.
 *
 * The raw parsed `eval.yaml` is kept alongside the merged {@see EffectiveConfig}
 * so schema checks can inspect the keys as written while coherence checks work
 * from the normalised, merged view.
 */
final class LoadedSkill {

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
    public readonly string $file,
    public readonly array $data,
    public readonly EffectiveConfig $effective,
  ) {}

}
