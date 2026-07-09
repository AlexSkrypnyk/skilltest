<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Contract;

/**
 * Grades one transcript file against a skill's contract and custom checks.
 *
 * The single kernel the deterministic transcript group, the record command, and
 * the offline grader all share: parse a transcript file, assert the skill's
 * normalised contract against it, then run every declared custom-check script
 * against the same file. Contract assertions come first and custom checks after,
 * in declaration order - the order a report renders them in. Command aliases and
 * the custom-check process runner are injected, so the grading is unit-testable
 * without spawning a process and normalises commands the same way the live suite
 * does.
 */
final readonly class TranscriptGrader {

  /**
   * Constructs a TranscriptGrader.
   *
   * @param string $root
   *   The repository root; custom-check `run` commands execute with this as the
   *   working directory, so relative script paths resolve against it.
   * @param array<string, string> $aliases
   *   The repo command aliases folded into command normalisation before
   *   matching.
   * @param \Closure|null $checkRunner
   *   An override for the custom-check process runner, for tests.
   */
  public function __construct(
    protected string $root,
    protected array $aliases = [],
    protected ?\Closure $checkRunner = NULL,
  ) {}

  /**
   * Grades a transcript file against a contract and its custom checks.
   *
   * @param string $transcript_path
   *   The transcript path to grade; a missing file grades as an empty run.
   * @param array<string, mixed> $contract
   *   The normalised contract (tools, commands, skills).
   * @param array<int, array<mixed>> $checks
   *   The declared custom-check entries, each a `name` and a `run` command.
   * @param string $skill_dir
   *   The skill directory passed to each check script as its second argument.
   *
   * @return \AlexSkrypnyk\SkillTest\Contract\CheckResult[]
   *   The contract results followed by the custom-check results, in order.
   */
  public function grade(string $transcript_path, array $contract, array $checks, string $skill_dir): array {
    $transcript = Transcript::fromFile($transcript_path);
    $results = (new ContractChecker($this->aliases))->check($transcript, $contract);

    if ($checks === []) {
      return $results;
    }

    $custom = new CustomCheck($this->root, $this->checkRunner);

    foreach ($checks as $entry) {
      $result = $custom->run($entry, $transcript_path, $skill_dir);

      if ($result instanceof CheckResult) {
        $results[] = $result;
      }
    }

    return $results;
  }

}
