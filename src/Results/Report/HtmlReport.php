<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Results\Report;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Results\Metrics;

/**
 * Renders a results document as one self-contained HTML file.
 *
 * A single file a skill author opens straight from `file://`: the run summary,
 * a per-skill drill-down to each check's evidence (native `<details>`, so no
 * script runs), the task-by-model matrix grid with its minimal-model verdict
 * when the run carried llm results, and the cost totals. The stylesheet is
 * inlined and the page references no external asset - no stylesheet link, no
 * script, no image, no font - so opening it makes zero network requests and it
 * renders identically offline. Every value the document carries is escaped,
 * so a skill name or a piece of evidence can never break out of its cell.
 */
final readonly class HtmlReport {

  /**
   * The inlined stylesheet, kept free of any external reference.
   */
  protected const string STYLE = <<<'CSS'
    :root { color-scheme: light dark; --fg: #1a1a1a; --bg: #ffffff; --muted: #666; --line: #ddd; --pass: #1a7f37; --fail: #cf222e; --panel: #f6f8fa; }
    @media (prefers-color-scheme: dark) { :root { --fg: #e6e6e6; --bg: #0d1117; --muted: #9aa; --line: #30363d; --pass: #3fb950; --fail: #f85149; --panel: #161b22; } }
    * { box-sizing: border-box; }
    body { margin: 0; padding: 2rem; font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; color: var(--fg); background: var(--bg); line-height: 1.5; }
    main { max-width: 60rem; margin: 0 auto; }
    h1 { font-size: 1.6rem; margin: 0 0 .25rem; }
    h2 { font-size: 1.2rem; margin: 2rem 0 .75rem; border-bottom: 1px solid var(--line); padding-bottom: .25rem; }
    .run { color: var(--muted); margin: 0 0 1.5rem; font-size: .9rem; }
    .pass { color: var(--pass); font-weight: 600; }
    .fail { color: var(--fail); font-weight: 600; }
    table { border-collapse: collapse; width: 100%; margin: .5rem 0; font-size: .9rem; }
    th, td { text-align: left; padding: .4rem .6rem; border-bottom: 1px solid var(--line); vertical-align: top; }
    th { color: var(--muted); font-weight: 600; }
    td.num { text-align: right; font-variant-numeric: tabular-nums; }
    .summary { max-width: 26rem; }
    details { border: 1px solid var(--line); border-radius: 6px; margin: .5rem 0; background: var(--panel); }
    summary { cursor: pointer; padding: .6rem .8rem; font-weight: 600; }
    details > table { margin: 0; }
    details > table th, details > table td { padding: .4rem .8rem; }
    code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85em; }
    .verdict { color: var(--muted); font-size: .9rem; margin: .25rem 0 1rem; }
    .interpret { background: var(--panel); border-left: 3px solid var(--muted); padding: .75rem 1rem; border-radius: 0 6px 6px 0; }
    CSS;

  /**
   * The deterministic groups drilled into per skill, in order.
   */
  protected const array GROUPS = ['structure', 'security', 'transcript'];

  /**
   * Renders the results document as a complete HTML document.
   *
   * @param array<string, mixed> $document
   *   The results document.
   * @param string|null $interpretation
   *   An optional plain-language paragraph to embed, or NULL to omit it.
   *
   * @return string
   *   The self-contained HTML.
   */
  public function render(array $document, ?string $interpretation = NULL): string {
    $aggregate = Metrics::aggregate($document);
    $failing = $this->failingScopes($document);
    $id = Data::toStringOrNull(Data::get($document, 'run', 'id')) ?? '';

    $body = [
      $this->heading($aggregate, $failing === [] && $aggregate['failures'] === 0),
      $this->runLine($document),
      $this->interpretation($interpretation),
      $this->summary($aggregate),
      $this->skills($document, $failing),
      $this->matrix($document),
      $this->cost($document),
    ];

    return implode("\n", [
      '<!doctype html>',
      '<html lang="en">',
      '<head>',
      '<meta charset="utf-8">',
      '<meta name="viewport" content="width=device-width, initial-scale=1">',
      '<title>skilltest report ' . $this->esc($id) . '</title>',
      '<style>' . self::STYLE . '</style>',
      '</head>',
      '<body>',
      '<main>',
      ...array_filter($body, static fn(string $section): bool => $section !== ''),
      '</main>',
      '</body>',
      '</html>',
      '',
    ]);
  }

  /**
   * The page heading with the pass or fail verdict.
   *
   * @param array<string, int|float> $aggregate
   *   The aggregate figures.
   * @param bool $passed
   *   Whether nothing failed.
   *
   * @return string
   *   The heading markup.
   */
  protected function heading(array $aggregate, bool $passed): string {
    $verdict = $passed
      ? sprintf('<span class="pass">PASS</span> - all %d check(s) passed', $aggregate['checks'])
      : sprintf('<span class="fail">FAIL</span> - %d of %d check(s) failed', $aggregate['failures'], $aggregate['checks']);

    return '<h1>skilltest report</h1>' . "\n" . '<p>' . $verdict . '</p>';
  }

  /**
   * The run metadata line.
   *
   * @param array<string, mixed> $document
   *   The results document.
   *
   * @return string
   *   The run line markup.
   */
  protected function runLine(array $document): string {
    $id = Data::toStringOrNull(Data::get($document, 'run', 'id')) ?? '';
    $command = Data::toStringOrNull(Data::get($document, 'run', 'command')) ?? '';
    $environment = Data::toStringOrNull(Data::get($document, 'run', 'environment')) ?? '';
    $started = Data::toStringOrNull(Data::get($document, 'run', 'started')) ?? '';

    return sprintf('<p class="run">run %s &middot; %s &middot; %s &middot; %s</p>', $this->esc($id), $this->esc($command), $this->esc($environment), $this->esc($started));
  }

  /**
   * The optional interpretation panel.
   *
   * @param string|null $interpretation
   *   The paragraph, or NULL to omit it.
   *
   * @return string
   *   The panel markup, or an empty string when none.
   */
  protected function interpretation(?string $interpretation): string {
    if ($interpretation === NULL || $interpretation === '') {
      return '';
    }

    return '<p class="interpret">' . $this->esc($interpretation) . '</p>';
  }

  /**
   * The summary table of headline figures.
   *
   * @param array<string, int|float> $aggregate
   *   The aggregate figures.
   *
   * @return string
   *   The summary table markup.
   */
  protected function summary(array $aggregate): string {
    $rows = [
      ['Checks', (string) $aggregate['checks']],
      ['Passed', (string) $aggregate['passed']],
      ['Failed', (string) $aggregate['failures']],
    ];

    if ($aggregate['trials'] > 0) {
      $rows[] = ['Trials', (string) $aggregate['trials']];
    }

    if ($aggregate['tokens_in'] + $aggregate['tokens_out'] > 0) {
      $rows[] = ['Tokens', sprintf('%d in / %d out', $aggregate['tokens_in'], $aggregate['tokens_out'])];
      $rows[] = ['Cost', '$' . number_format((float) $aggregate['cost_usd'], 4)];
    }

    $rows[] = ['Duration', sprintf('%d ms', $aggregate['duration_ms'])];

    $cells = array_map(fn(array $row): string => sprintf('<tr><th>%s</th><td class="num">%s</td></tr>', $this->esc($row[0]), $this->esc($row[1])), $rows);

    return '<table class="summary">' . implode('', $cells) . '</table>';
  }

  /**
   * The per-skill drill-down sections.
   *
   * @param array<string, mixed> $document
   *   The results document.
   * @param string[] $failing
   *   The names of skills that carry a failure, opened by default.
   *
   * @return string
   *   The skills section markup, or an empty string when there are none.
   */
  protected function skills(array $document, array $failing): string {
    $sections = [];

    foreach (Data::toArrayList(Data::get($document, 'skills')) as $skill) {
      $sections[] = $this->skill($skill, $failing);
    }

    if ($sections === []) {
      return '';
    }

    return '<h2>Skills</h2>' . "\n" . implode("\n", $sections);
  }

  /**
   * One skill's drill-down: a table of its checks and their evidence.
   *
   * @param array<string, mixed> $skill
   *   The skill entry.
   * @param string[] $failing
   *   The names of skills that carry a failure.
   *
   * @return string
   *   The skill section markup.
   */
  protected function skill(array $skill, array $failing): string {
    $name = Data::toStringOrNull(Data::get($skill, 'skill')) ?? '';
    $checks = $this->checkRows($skill);
    $failed = count(array_filter($checks, static fn(array $row): bool => !$row['pass']));

    $open = in_array($name, $failing, TRUE) ? ' open' : '';
    $status = $failed === 0 ? sprintf('%d check(s), all passed', count($checks)) : sprintf('%d of %d check(s) failed', $failed, count($checks));

    $rows = array_map(fn(array $row): string => sprintf('<tr><td><code>%s</code></td><td class="%s">%s</td><td>%s</td></tr>', $this->esc($row['id']), $row['pass'] ? 'pass' : 'fail', $row['pass'] ? 'pass' : 'fail', $this->esc($row['detail'])), $checks);

    $table = $rows === []
      ? '<p class="verdict">No deterministic checks.</p>'
      : '<table><tr><th>check</th><th>status</th><th>detail</th></tr>' . implode('', $rows) . '</table>';

    return sprintf('<details%s><summary>%s &mdash; %s</summary>%s</details>', $open, $this->esc($name), $this->esc($status), $table);
  }

  /**
   * Flattens a skill's deterministic checks into rows with a detail column.
   *
   * @param array<string, mixed> $skill
   *   The skill entry.
   *
   * @return array<int, array{id: string, pass: bool, detail: string}>
   *   The flattened check rows, in group order.
   */
  protected function checkRows(array $skill): array {
    $rows = [];

    foreach (self::GROUPS as $group) {
      foreach (Data::toArrayList(Data::get($skill, 'deterministic', $group)) as $check) {
        $detail = Data::toStringOrNull(Data::get($check, 'message')) ?: (Data::toStringOrNull(Data::get($check, 'label')) ?? '');
        $evidence = Data::toStringOrNull(Data::get($check, 'evidence')) ?? '';

        $rows[] = [
          'id' => Data::toStringOrNull(Data::get($check, 'check')) ?? '',
          'pass' => Data::toBoolOrNull(Data::get($check, 'pass')) ?? FALSE,
          'detail' => $evidence === '' ? $detail : trim($detail . ' [' . $evidence . ']'),
        ];
      }
    }

    return $rows;
  }

  /**
   * The matrix section: one grid and verdict per skill that ran llm tasks.
   *
   * @param array<string, mixed> $document
   *   The results document.
   *
   * @return string
   *   The matrix section markup, or an empty string when no skill ran llm
   *   tasks.
   */
  protected function matrix(array $document): string {
    $sections = [];

    foreach (Data::toArrayList(Data::get($document, 'skills')) as $skill) {
      $tasks = Data::toArrayList(Data::get($skill, 'llm', 'tasks'));

      if ($tasks === []) {
        continue;
      }

      $name = Data::toStringOrNull(Data::get($skill, 'skill')) ?? '';
      $columns = MatrixGrid::columns($tasks);

      $head = '<tr><th>task</th>' . implode('', array_map(fn(string $column): string => '<th>' . $this->esc($column) . '</th>', $columns)) . '</tr>';
      $body = array_map(fn(array $task): string => $this->matrixRow($task, $columns), $tasks);

      $sections[] = sprintf('<h3>%s</h3><table>%s%s</table><p class="verdict">%s</p>', $this->esc($name), $head, implode('', $body), $this->esc(MatrixGrid::verdictLine($skill)));
    }

    if ($sections === []) {
      return '';
    }

    return '<h2>Matrix</h2>' . "\n" . implode("\n", $sections);
  }

  /**
   * One matrix row: the task name and its pass-rate cell per model column.
   *
   * @param array<mixed> $task
   *   The task entry.
   * @param string[] $columns
   *   The model columns.
   *
   * @return string
   *   The row markup.
   */
  protected function matrixRow(array $task, array $columns): string {
    $name = Data::toStringOrNull(Data::get($task, 'task')) ?? '';
    $cells = array_map(fn(string $cell): string => '<td class="num">' . $this->esc($cell) . '</td>', MatrixGrid::cells($task, $columns));

    return '<tr><td>' . $this->esc($name) . '</td>' . implode('', $cells) . '</tr>';
  }

  /**
   * The cost totals section: per-model cost and the grand total.
   *
   * @param array<string, mixed> $document
   *   The results document.
   *
   * @return string
   *   The cost section markup, or an empty string when nothing was spent.
   */
  protected function cost(array $document): string {
    $models = Metrics::perModel($document);

    if ($models === []) {
      return '';
    }

    $total = 0.0;
    $rows = [];

    foreach ($models as $alias => $model) {
      $cost = (float) $model['cost_usd'];
      $total += $cost;
      $rows[] = sprintf('<tr><td>%s</td><td class="num">$%s</td></tr>', $this->esc($alias), $this->esc(number_format($cost, 4)));
    }

    $rows[] = sprintf('<tr><th>total</th><td class="num">$%s</td></tr>', $this->esc(number_format($total, 4)));

    return '<h2>Cost</h2>' . "\n" . '<table class="summary">' . implode('', $rows) . '</table>';
  }

  /**
   * The names of skills that carry at least one failure.
   *
   * @param array<string, mixed> $document
   *   The results document.
   *
   * @return string[]
   *   The failing skill names.
   */
  protected function failingScopes(array $document): array {
    $scopes = [];

    foreach (Metrics::failures($document) as $finding) {
      $scope = Data::toStringOrNull(Data::get($finding, 'scope')) ?? '';

      if ($scope !== '' && $scope !== 'repo') {
        $scopes[$scope] = $scope;
      }
    }

    return array_values($scopes);
  }

  /**
   * Escapes a value for safe inclusion in HTML text or an attribute.
   *
   * @param string $value
   *   The raw value.
   *
   * @return string
   *   The escaped value.
   */
  protected function esc(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

}
