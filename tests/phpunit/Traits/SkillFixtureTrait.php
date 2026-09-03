<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Traits;

use AlexSkrypnyk\SkillTest\Config\EffectiveConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedSkill;
use AlexSkrypnyk\SkillTest\Config\RepoConfig;

/**
 * Trait SkillFixtureTrait.
 *
 * Builds loaded skill fixtures for unit tests.
 */
trait SkillFixtureTrait {

  /**
   * Builds a loaded skill rooted at a directory with the given eval data.
   *
   * @param string $root
   *   The repository root URL.
   * @param string $dir
   *   The skill directory, relative to the root.
   * @param array<mixed> $eval
   *   The parsed `eval.yaml`.
   *
   * @return \AlexSkrypnyk\SkillTest\Config\LoadedSkill
   *   The loaded skill.
   */
  protected function skill(string $root, string $dir, array $eval): LoadedSkill {
    $absolute_dir = $root . '/' . $dir;
    $effective = EffectiveConfig::resolve(RepoConfig::fromArray([]), $eval, [], basename($dir), $dir);

    return new LoadedSkill($absolute_dir . '/eval.yaml', $eval, $effective, $absolute_dir);
  }

  /**
   * Builds a loaded skill for a directory that ships no `eval.yaml`.
   *
   * @param string $root
   *   The repository root URL.
   * @param string $dir
   *   The skill directory, relative to the root.
   *
   * @return \AlexSkrypnyk\SkillTest\Config\LoadedSkill
   *   The loaded skill.
   */
  protected function skillWithoutEval(string $root, string $dir): LoadedSkill {
    $effective = EffectiveConfig::resolve(RepoConfig::fromArray([]), [], [], basename($dir), $dir);

    return new LoadedSkill('', [], $effective, $root . '/' . $dir);
  }

}
