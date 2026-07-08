<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Coverage;

use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedSkill;

/**
 * Computes the skill-to-eval coverage grid and the coverage-gate violations.
 *
 * Cross-references every discovered skill - those that loaded an `eval.yaml`
 * and those that did not - against the repo's exclusions, so the grid names
 * every skill exactly once and the gate can name each unexplained hole. The
 * rows are computed once at construction and shared by every accessor.
 */
final readonly class Coverage {

  /**
   * The coverage rows, one per discovered skill, sorted by path.
   *
   * @var \AlexSkrypnyk\SkillTest\Coverage\CoverageRow[]
   */
  public array $rows;

  /**
   * Constructs a Coverage.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $config
   *   The loaded configuration, including skills that lack an `eval.yaml`.
   */
  public function __construct(LoadedConfig $config) {
    $this->rows = self::build($config);
  }

  /**
   * Returns the rows that fail the coverage gate.
   *
   * @return \AlexSkrypnyk\SkillTest\Coverage\CoverageRow[]
   *   The uncovered, non-excluded rows.
   */
  public function violations(): array {
    return array_values(array_filter($this->rows, static fn(CoverageRow $row): bool => $row->isViolation()));
  }

  /**
   * Returns per-status counts across the grid.
   *
   * @return array<string, int>
   *   The total and the covered, excluded, and uncovered counts.
   */
  public function summary(): array {
    $statuses = array_map(static fn(CoverageRow $row): string => $row->status(), $this->rows);

    return [
      'total' => count($this->rows),
      'covered' => count(array_keys($statuses, CoverageRow::STATUS_COVERED, TRUE)),
      'excluded' => count(array_keys($statuses, CoverageRow::STATUS_EXCLUDED, TRUE)),
      'uncovered' => count(array_keys($statuses, CoverageRow::STATUS_UNCOVERED, TRUE)),
    ];
  }

  /**
   * Builds the coverage rows from the loaded configuration.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $config
   *   The loaded configuration.
   *
   * @return \AlexSkrypnyk\SkillTest\Coverage\CoverageRow[]
   *   The rows, sorted by path.
   */
  protected static function build(LoadedConfig $config): array {
    $excluded = self::excludedReasons($config);
    $rows = [];

    foreach ($config->skills as $skill) {
      $name = $skill->effective->skill;
      $rows[] = new CoverageRow(
        $name,
        $skill->effective->path,
        TRUE,
        self::transcriptExists($skill),
        count($skill->effective->tasks),
        array_key_exists($name, $excluded),
        $excluded[$name] ?? NULL,
      );
    }

    foreach ($config->skillsWithoutEval as $dir) {
      $name = basename($dir);
      $rows[] = new CoverageRow(
        $name,
        $dir,
        FALSE,
        FALSE,
        0,
        array_key_exists($name, $excluded),
        $excluded[$name] ?? NULL,
      );
    }

    usort($rows, static fn(CoverageRow $a, CoverageRow $b): int => strcmp($a->path, $b->path));

    return $rows;
  }

  /**
   * Maps excluded skill names to their reasons.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $config
   *   The loaded configuration.
   *
   * @return array<string, string|null>
   *   Exclusion reasons keyed by skill name.
   */
  protected static function excludedReasons(LoadedConfig $config): array {
    $map = [];

    foreach ($config->repo->excludes as $entry) {
      if ($entry->skill !== '') {
        $map[$entry->skill] = $entry->reason;
      }
    }

    return $map;
  }

  /**
   * Whether a skill's declared transcript fixture exists on disk.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $skill
   *   The loaded skill.
   *
   * @return bool
   *   TRUE when a transcript is declared and the file exists.
   */
  protected static function transcriptExists(LoadedSkill $skill): bool {
    $transcript = $skill->effective->transcript;

    if ($transcript === NULL) {
      return FALSE;
    }

    $path = str_starts_with($transcript, '/') ? $transcript : dirname($skill->file) . '/' . $transcript;

    return is_file($path);
  }

}
