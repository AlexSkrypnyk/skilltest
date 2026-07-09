<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Run;

/**
 * One skill's deterministic-suite outcome: its per-group result rows.
 *
 * Bundles the structure results, security findings, and transcript assertions
 * produced for a single skill, plus the note explaining a skipped transcript
 * group, so reporting can walk one object per skill instead of re-grouping
 * flat engine output.
 */
final readonly class SkillRunResult {

  /**
   * Constructs a SkillRunResult.
   *
   * @param string $skill
   *   The skill name.
   * @param string $path
   *   The skill directory, relative to the repository root.
   * @param \AlexSkrypnyk\SkillTest\Structure\StructureResult[] $structure
   *   The structure group results.
   * @param \AlexSkrypnyk\SkillTest\Security\SecurityFinding[] $security
   *   The security group findings.
   * @param \AlexSkrypnyk\SkillTest\Contract\CheckResult[] $transcript
   *   The transcript group results: contract assertions and custom checks.
   * @param string $transcriptNote
   *   The reason the transcript group ran no checks, or an empty string.
   */
  public function __construct(
    public string $skill,
    public string $path,
    public array $structure,
    public array $security,
    public array $transcript,
    public string $transcriptNote = '',
  ) {}

}
