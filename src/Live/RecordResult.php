<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

/**
 * The raw outcome of the one live trial `skilltest record` runs.
 *
 * A recording is graded from the fixture it writes, not from the live run, so
 * this carries only what the command persists and then re-grades: the raw
 * transcript, the agent's exit code, and the wall-clock duration.
 */
final readonly class RecordResult {

  /**
   * Constructs a RecordResult.
   *
   * @param string $transcript
   *   The raw stream-json transcript captured from the agent run.
   * @param int $exitCode
   *   The agent process exit code; non-zero (or the timeout code) means the
   *   recording is untrustworthy.
   * @param int $durationMs
   *   The measured wall-clock duration in milliseconds.
   */
  public function __construct(
    public string $transcript,
    public int $exitCode,
    public int $durationMs,
  ) {}

}
