<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Migrate;

/**
 * The outcome of checking one file against the current schema.
 *
 * A file whose major already matches the tool is reported as current with
 * `changed` false and nothing written; an older-major file is rewritten and
 * reported with `changed` true and the versions it moved between. The message
 * is the human line the command prints, so the same wording is asserted once
 * here rather than reconstructed at the call site.
 */
final readonly class MigrateResult {

  /**
   * Constructs a MigrateResult.
   *
   * @param bool $changed
   *   TRUE when the file was rewritten, FALSE when it was already current.
   * @param string $from
   *   The schema version the file declared, in MAJOR.MINOR form.
   * @param string $to
   *   The schema version the file now declares, in MAJOR.MINOR form.
   * @param string $message
   *   The human-readable outcome line.
   */
  public function __construct(
    public bool $changed,
    public string $from,
    public string $to,
    public string $message,
  ) {}

}
