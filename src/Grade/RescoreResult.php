<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Grade;

/**
 * The outcome of re-scoring a saved run: the new document and what moved.
 *
 * Carries the rebuilt results document alongside the counts that make a
 * re-grade legible: how many trials re-scored, and how many flipped in each
 * direction. A trial that newly fails is the signal a tightened contract or
 * rubric was looking for; a trial that newly passes says the run would clear a
 * loosened bar. The notes surface anything the re-grade could not do offline,
 * such as a skill no longer in the config.
 */
final readonly class RescoreResult {

  /**
   * Constructs a RescoreResult.
   *
   * @param array<mixed> $document
   *   The re-scored results document.
   * @param int $trialsRescored
   *   The number of trials whose verdict was recomputed.
   * @param int $newlyFailing
   *   The number of trials that passed before and fail now.
   * @param int $newlyPassing
   *   The number of trials that failed before and pass now.
   * @param string[] $notes
   *   Any advisories the re-grade produced.
   */
  public function __construct(
    public array $document,
    public int $trialsRescored,
    public int $newlyFailing,
    public int $newlyPassing,
    public array $notes,
  ) {}

  /**
   * Whether the re-grade changed any trial verdict.
   *
   * @return bool
   *   TRUE when at least one trial flipped in either direction.
   */
  public function changed(): bool {
    return $this->newlyFailing > 0 || $this->newlyPassing > 0;
  }

}
