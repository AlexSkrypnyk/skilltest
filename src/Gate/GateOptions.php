<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Gate;

use AlexSkrypnyk\SkillTest\Config\Data;

/**
 * The parsed, validated policy the regression gate enforces.
 *
 * Holds the aggregate regression tolerance in percentage points and the drift
 * policy for tasks the current run added or dropped. Parsing is total: it
 * returns either a valid policy or the list of reasons it could not be built, so
 * a bad flag surfaces as a configuration error before any comparison runs.
 */
final readonly class GateOptions {

  /**
   * The drift policies a task-set change can be held to.
   */
  public const array POLICIES = ['allow', 'warn', 'fail'];

  /**
   * The default drift policy: visible but non-failing.
   */
  public const string DEFAULT_POLICY = 'warn';

  /**
   * Constructs a GateOptions.
   *
   * @param float $maxRegression
   *   The tolerated aggregate pass-rate drop, in percentage points.
   * @param string $newTasks
   *   The policy for tasks present in the current run but not the baseline.
   * @param string $removedTasks
   *   The policy for tasks present in the baseline but not the current run.
   */
  public function __construct(
    public float $maxRegression,
    public string $newTasks,
    public string $removedTasks,
  ) {}

  /**
   * Parses the raw option strings into a policy or a list of errors.
   *
   * @param string|null $max_regression
   *   The `--max-regression` value, or NULL for the default of zero.
   * @param string|null $new_tasks
   *   The `--on-new-tasks` value, or NULL for the default policy.
   * @param string|null $removed_tasks
   *   The `--on-removed-tasks` value, or NULL for the default policy.
   *
   * @return array{0: self|null, 1: string[]}
   *   The parsed policy and an empty error list, or NULL and the errors.
   */
  public static function parse(?string $max_regression, ?string $new_tasks, ?string $removed_tasks): array {
    $errors = [];

    $regression = 0.0;

    if ($max_regression !== NULL) {
      $parsed = Data::toFloatOrNull($max_regression);

      if ($parsed === NULL || $parsed < 0) {
        $errors[] = '--max-regression must be a non-negative number of percentage points.';
      }
      else {
        $regression = $parsed;
      }
    }

    $new = $new_tasks ?? self::DEFAULT_POLICY;
    $removed = $removed_tasks ?? self::DEFAULT_POLICY;

    if (!in_array($new, self::POLICIES, TRUE)) {
      $errors[] = sprintf('--on-new-tasks must be one of: %s.', implode(', ', self::POLICIES));
    }

    if (!in_array($removed, self::POLICIES, TRUE)) {
      $errors[] = sprintf('--on-removed-tasks must be one of: %s.', implode(', ', self::POLICIES));
    }

    if ($errors !== []) {
      return [NULL, $errors];
    }

    return [new self($regression, $new, $removed), []];
  }

}
