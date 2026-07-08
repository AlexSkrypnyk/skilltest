<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Config;

/**
 * Discovers skills under the configured skills paths.
 *
 * A skill is any directory containing a `SKILL.md`. Discovery descends one
 * level below each skills path (matching the plugin convention), so both
 * `skills/<name>/SKILL.md` and `skills/<group>/<name>/SKILL.md` are found.
 */
final readonly class Discovery {

  /**
   * The marker file that identifies a skill directory.
   */
  public const string MARKER = 'SKILL.md';

  /**
   * The repository root, used to resolve the skills paths.
   */
  protected string $root;

  /**
   * Constructs a Discovery.
   *
   * @param string $root
   *   The repository root.
   * @param \AlexSkrypnyk\SkillTest\Config\RepoConfig $repo
   *   The repo configuration.
   */
  public function __construct(string $root, protected RepoConfig $repo) {
    $this->root = rtrim($root, '/');
  }

  /**
   * Returns the root-relative directories of every discovered skill.
   *
   * @return string[]
   *   Skill directories relative to the repository root, sorted.
   */
  public function skills(): array {
    $found = [];

    foreach ($this->repo->skillsPaths as $skills_path) {
      $relative_base = trim((string) $skills_path, '/');
      $absolute_base = $this->root . '/' . $relative_base;

      if (!is_dir($absolute_base)) {
        continue;
      }

      foreach ($this->childDirectories($absolute_base) as $child) {
        $child_relative = $relative_base . '/' . $child;

        if ($this->isSkill($child_relative)) {
          $found[$child_relative] = TRUE;

          continue;
        }

        foreach ($this->childDirectories($this->root . '/' . $child_relative) as $grandchild) {
          $grandchild_relative = $child_relative . '/' . $grandchild;

          if ($this->isSkill($grandchild_relative)) {
            $found[$grandchild_relative] = TRUE;
          }
        }
      }
    }

    $directories = array_keys($found);
    sort($directories);

    return $directories;
  }

  /**
   * Whether a root-relative directory contains the skill marker.
   *
   * @param string $relative_dir
   *   The directory relative to the repository root.
   *
   * @return bool
   *   TRUE when the directory is a skill.
   */
  protected function isSkill(string $relative_dir): bool {
    return is_file($this->root . '/' . $relative_dir . '/' . self::MARKER);
  }

  /**
   * Lists the immediate child directory names of a path.
   *
   * @param string $absolute_dir
   *   The absolute directory to scan.
   *
   * @return string[]
   *   The child directory basenames, sorted.
   */
  protected function childDirectories(string $absolute_dir): array {
    $entries = @scandir($absolute_dir);

    if ($entries === FALSE) {
      // @codeCoverageIgnoreStart
      return [];
      // @codeCoverageIgnoreEnd
    }

    $directories = [];

    foreach ($entries as $entry) {
      if ($entry === '.') {
        continue;
      }
      if ($entry === '..') {
        continue;
      }
      if (is_dir($absolute_dir . '/' . $entry)) {
        $directories[] = $entry;
      }
    }

    sort($directories);

    return $directories;
  }

}
