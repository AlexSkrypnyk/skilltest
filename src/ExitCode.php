<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest;

/**
 * The tool-wide exit code contract.
 *
 * Exit codes are a documented API: changing a value is a breaking change.
 */
final readonly class ExitCode {

  /**
   * Everything selected passed.
   */
  public const int PASS = 0;

  /**
   * One or more checks, trials, or gates failed.
   */
  public const int FAIL = 1;

  /**
   * Configuration error.
   *
   * Invalid schema, unresolvable reference, missing file, or no skills found.
   */
  public const int CONFIG_ERROR = 2;

}
