<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Init;

/**
 * A minimal line-oriented diff for the merge-safe preview.
 *
 * When `init` would overwrite an existing `eval.yaml` without `--force`, it
 * shows what it would have written instead of clobbering the file. A
 * longest-common-subsequence walk keeps unchanged lines aligned so the
 * additions (`+`) and removals (`-`) a reader sees are the real edits, not the
 * noise a naive positional compare produces when lines shift.
 */
final readonly class LineDiff {

  /**
   * Renders a unified-style line diff of two documents.
   *
   * @param string $old
   *   The current document (the existing file).
   * @param string $new
   *   The proposed document.
   *
   * @return string
   *   The diff: each line prefixed with ' ' (common), '-' (only in old), or
   *   '+' (only in new).
   */
  public static function unified(string $old, string $new): string {
    $a = explode("\n", $old);
    $b = explode("\n", $new);
    $table = self::table($a, $b);

    $rows = count($a);
    $cols = count($b);
    $i = 0;
    $j = 0;
    $lines = [];

    while ($i < $rows && $j < $cols) {
      if ($a[$i] === $b[$j]) {
        $lines[] = ' ' . $a[$i];
        $i++;
        $j++;

        continue;
      }

      if ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
        $lines[] = '-' . $a[$i];
        $i++;

        continue;
      }

      $lines[] = '+' . $b[$j];
      $j++;
    }

    while ($i < $rows) {
      $lines[] = '-' . $a[$i];
      $i++;
    }

    while ($j < $cols) {
      $lines[] = '+' . $b[$j];
      $j++;
    }

    return implode("\n", $lines);
  }

  /**
   * Builds the longest-common-subsequence length table.
   *
   * `table[i][j]` holds the LCS length of `a[i..]` and `b[j..]`, so a forward
   * walk can choose at each step whether a line was removed or added.
   *
   * @param string[] $a
   *   The old lines.
   * @param string[] $b
   *   The new lines.
   *
   * @return array<int, array<int, int>>
   *   The length table, sized (|a|+1) x (|b|+1).
   */
  protected static function table(array $a, array $b): array {
    $rows = count($a);
    $cols = count($b);
    $table = array_fill(0, $rows + 1, array_fill(0, $cols + 1, 0));

    for ($i = $rows - 1; $i >= 0; $i--) {
      for ($j = $cols - 1; $j >= 0; $j--) {
        $table[$i][$j] = $a[$i] === $b[$j]
          ? $table[$i + 1][$j + 1] + 1
          : max($table[$i + 1][$j], $table[$i][$j + 1]);
      }
    }

    return $table;
  }

}
