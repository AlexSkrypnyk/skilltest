<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Run\Report;

use AlexSkrypnyk\SkillTest\Config\Data;

/**
 * Summarises why one llm trial failed, shared by every reporter.
 *
 * A failed trial fails because a contract check did not match or a judge
 * criterion was not met; both the JUnit and github-comment reporters render
 * that same reason, so the derivation lives in one place.
 */
final class TrialSummary {

  /**
   * Builds a one-line reason a trial failed: its contract and judge failures.
   *
   * @param array<mixed> $trial
   *   One trial entry from the results document.
   *
   * @return string
   *   A one-line summary, or an empty string when nothing failed.
   */
  public static function line(array $trial): string {
    $parts = [];

    foreach (Data::toArrayList(Data::get($trial, 'contract')) as $check) {
      if ((Data::toBoolOrNull(Data::get($check, 'pass')) ?? FALSE) === FALSE) {
        $parts[] = 'contract ' . (Data::toStringOrNull(Data::get($check, 'check')) ?? '');
      }
    }

    $criteria = [];

    foreach (Data::toArrayList(Data::get($trial, 'judge')) as $criterion) {
      if ((Data::toBoolOrNull(Data::get($criterion, 'pass')) ?? FALSE) === FALSE) {
        $criteria[] = (string) (Data::toIntOrNull(Data::get($criterion, 'criterion')) ?? 0);
      }
    }

    if ($criteria !== []) {
      $parts[] = 'judge criteria ' . implode(', ', $criteria);
    }

    return $parts === [] ? '' : 'failed: ' . implode('; ', $parts);
  }

}
