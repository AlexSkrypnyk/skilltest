<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Render;

/**
 * Renders a header-and-rows grid as aligned text or as a GitHub markdown table.
 *
 * A reporting primitive shared by every grid the tool prints: the text form
 * pads each column to its widest cell and separates columns with two spaces,
 * trimming trailing space so a row carries no dangling whitespace; the markdown
 * form emits a valid GFM pipe table with the header underline and pipes escaped
 * inside cells. Both flatten newlines within a cell to a space, so an
 * author-controlled value can never split one logical row across lines.
 */
final readonly class Table {

  /**
   * Renders the grid as aligned, space-separated text lines.
   *
   * @param string[] $headers
   *   The column headers.
   * @param array<int, string[]> $rows
   *   The body rows, each aligned with the headers.
   *
   * @return string[]
   *   The header row followed by the body rows.
   */
  public static function text(array $headers, array $rows): array {
    $headers = array_map(self::flatten(...), $headers);
    $rows = array_map(static fn(array $cells): array => array_map(self::flatten(...), $cells), $rows);

    $widths = self::widths($headers, $rows);
    $lines = [self::textRow($headers, $widths)];

    foreach ($rows as $cells) {
      $lines[] = self::textRow($cells, $widths);
    }

    return $lines;
  }

  /**
   * Renders the grid as a GitHub-flavoured markdown pipe table.
   *
   * @param string[] $headers
   *   The column headers.
   * @param array<int, string[]> $rows
   *   The body rows, each aligned with the headers.
   *
   * @return string[]
   *   The header line, the underline, and one line per body row.
   */
  public static function markdown(array $headers, array $rows): array {
    $lines = [
      '| ' . implode(' | ', array_map(self::cell(...), $headers)) . ' |',
      '| ' . implode(' | ', array_fill(0, count($headers), '---')) . ' |',
    ];

    foreach ($rows as $cells) {
      $lines[] = '| ' . implode(' | ', array_map(self::cell(...), $cells)) . ' |';
    }

    return $lines;
  }

  /**
   * Computes each column's width from the headers and body.
   *
   * @param string[] $headers
   *   The column headers.
   * @param array<int, string[]> $rows
   *   The body rows.
   *
   * @return int[]
   *   The width of each column.
   */
  protected static function widths(array $headers, array $rows): array {
    $widths = array_map(strlen(...), $headers);

    foreach ($rows as $cells) {
      foreach ($cells as $column => $cell) {
        $widths[$column] = max($widths[$column] ?? 0, strlen($cell));
      }
    }

    return $widths;
  }

  /**
   * Renders one padded, space-separated text row without trailing whitespace.
   *
   * @param string[] $cells
   *   The row cells.
   * @param int[] $widths
   *   The column widths.
   *
   * @return string
   *   The rendered row.
   */
  protected static function textRow(array $cells, array $widths): string {
    $padded = [];

    foreach ($cells as $column => $cell) {
      $padded[] = str_pad($cell, $widths[$column] ?? strlen($cell));
    }

    return rtrim(implode('  ', $padded));
  }

  /**
   * Escapes a markdown cell so an embedded pipe stays inside the cell.
   *
   * @param string $value
   *   The cell value.
   *
   * @return string
   *   The escaped, single-line value.
   */
  protected static function cell(string $value): string {
    return str_replace('|', '\\|', self::flatten($value));
  }

  /**
   * Collapses newlines to spaces so a cell stays on its own row.
   *
   * @param string $value
   *   The cell value.
   *
   * @return string
   *   The single-line value.
   */
  protected static function flatten(string $value): string {
    return str_replace(["\r\n", "\r", "\n"], ' ', $value);
  }

}
