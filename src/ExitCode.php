<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest;

/**
 * The tool-wide exit code contract.
 *
 * Exit codes are a documented API: CI scripts rely on them, and changing
 * them is a breaking change.
 */
final class ExitCode {

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
