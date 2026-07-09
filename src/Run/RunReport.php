<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Run;

use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Coverage\CoverageRow;
use AlexSkrypnyk\SkillTest\Security\SecurityFinding;
use AlexSkrypnyk\SkillTest\Structure\StructureResult;

/**
 * The whole deterministic run's outcome, ready for rendering and totals.
 *
 * Aggregates every per-skill group result with the repo-level hook results and
 * coverage-gate violations, and owns the arithmetic every renderer shares:
 * how many checks ran, how many failed, and how many were suppressed. The
 * machine-readable document is produced here too, so the JSON reporter never
 * computes its own truths.
 */
final readonly class RunReport {

  /**
   * The check id coverage-gate violations render under.
   */
  public const string COVERAGE_CHECK = 'coverage.eval-exists';

  /**
   * Constructs a RunReport.
   *
   * @param \AlexSkrypnyk\SkillTest\Run\SkillRunResult[] $skills
   *   The per-skill results, in discovery order.
   * @param \AlexSkrypnyk\SkillTest\Contract\CheckResult[] $hooks
   *   The repo-level hook case results.
   * @param \AlexSkrypnyk\SkillTest\Coverage\CoverageRow[] $coverage
   *   The coverage-gate violations.
   */
  public function __construct(
    public array $skills,
    public array $hooks,
    public array $coverage,
  ) {}

  /**
   * The number of checks the run produced.
   *
   * @return int
   *   The check count, coverage violations included.
   */
  public function checks(): int {
    $count = count($this->hooks) + count($this->coverage);

    foreach ($this->skills as $skill) {
      $count += count($skill->structure) + count($skill->security) + count($skill->transcript);
    }

    return $count;
  }

  /**
   * The number of failed checks.
   *
   * Security findings and coverage violations only ever record failures, so
   * every one of them counts.
   *
   * @return int
   *   The failure count.
   */
  public function failures(): int {
    $count = count($this->coverage);
    $count += count(array_filter($this->hooks, static fn(CheckResult $result): bool => !$result->pass));

    foreach ($this->skills as $skill) {
      $count += count(array_filter($skill->structure, static fn(StructureResult $result): bool => $result->failed()));
      $count += count($skill->security);
      $count += count(array_filter($skill->transcript, static fn(CheckResult $result): bool => !$result->pass));
    }

    return $count;
  }

  /**
   * The number of suppressed checks.
   *
   * @return int
   *   The suppressed count.
   */
  public function suppressed(): int {
    $count = 0;

    foreach ($this->skills as $skill) {
      $count += count(array_filter($skill->structure, static fn(StructureResult $result): bool => $result->status === StructureResult::SUPPRESSED));
    }

    return $count;
  }

  /**
   * Whether any check failed.
   *
   * @return bool
   *   TRUE when at least one check failed.
   */
  public function failed(): bool {
    return $this->failures() > 0;
  }

  /**
   * Builds the machine-readable results document.
   *
   * @param string $schema_version
   *   The results schema version.
   * @param array<string, string> $tool
   *   The tool block: name and version.
   * @param array<string, mixed> $run
   *   The run block: id, started, duration_ms, command, environment.
   *
   * @return array<string, mixed>
   *   The full results document.
   */
  public function toResults(string $schema_version, array $tool, array $run): array {
    return [
      'version' => $schema_version,
      'tool' => $tool,
      'run' => $run,
      'skills' => array_map($this->skillRows(...), $this->skills),
      'hooks' => array_map(self::checkRow(...), $this->hooks),
      'coverage' => ['violations' => array_map(self::coverageRow(...), $this->coverage)],
      'totals' => [
        'checks' => $this->checks(),
        'failures' => $this->failures(),
        'trials' => 0,
        'tokens' => ['in' => 0, 'out' => 0],
        'cost_usd' => 0.0,
      ],
    ];
  }

  /**
   * Maps one skill's results to its document entry.
   *
   * @param \AlexSkrypnyk\SkillTest\Run\SkillRunResult $skill
   *   The skill's results.
   *
   * @return array<string, mixed>
   *   The per-skill document entry.
   */
  protected function skillRows(SkillRunResult $skill): array {
    return [
      'skill' => $skill->skill,
      'path' => $skill->path,
      'deterministic' => [
        'structure' => array_map(self::structureRow(...), $skill->structure),
        'security' => array_map(self::securityRow(...), $skill->security),
        'transcript' => array_map(self::checkRow(...), $skill->transcript),
      ],
    ];
  }

  /**
   * Maps a structure result to a document row carrying a pass verdict.
   *
   * @param \AlexSkrypnyk\SkillTest\Structure\StructureResult $result
   *   The structure result.
   *
   * @return array<string, mixed>
   *   The document row.
   */
  protected static function structureRow(StructureResult $result): array {
    return $result->toArray() + ['pass' => !$result->failed()];
  }

  /**
   * Maps a security finding to a document row; findings are always failures.
   *
   * @param \AlexSkrypnyk\SkillTest\Security\SecurityFinding $finding
   *   The security finding.
   *
   * @return array<string, mixed>
   *   The document row.
   */
  protected static function securityRow(SecurityFinding $finding): array {
    return $finding->toArray() + ['pass' => FALSE];
  }

  /**
   * Maps a contract, custom-check, or hook result to a document row.
   *
   * The stable id renders under the `check` key the results schema uses.
   *
   * @param \AlexSkrypnyk\SkillTest\Contract\CheckResult $result
   *   The check result.
   *
   * @return array<string, mixed>
   *   The document row.
   */
  protected static function checkRow(CheckResult $result): array {
    return $result->toCheckRow();
  }

  /**
   * Maps a coverage violation to a failing document row.
   *
   * @param \AlexSkrypnyk\SkillTest\Coverage\CoverageRow $row
   *   The violating coverage row.
   *
   * @return array<string, mixed>
   *   The document row.
   */
  protected static function coverageRow(CoverageRow $row): array {
    return $row->toArray() + ['check' => self::COVERAGE_CHECK, 'pass' => FALSE, 'message' => self::coverageMessage($row)];
  }

  /**
   * Builds the failure message for one coverage-gate violation.
   *
   * @param \AlexSkrypnyk\SkillTest\Coverage\CoverageRow $row
   *   The violating coverage row.
   *
   * @return string
   *   The failure message and fix direction.
   */
  public static function coverageMessage(CoverageRow $row): string {
    return sprintf("skill '%s' has no eval.yaml and is not excluded (add an eval.yaml or exclude it with a reason).", $row->skill);
  }

}
