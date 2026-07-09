<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live\Matrix;

use AlexSkrypnyk\SkillTest\Render\Table;

/**
 * Renders a matrix report as terminal text or GitHub-flavoured markdown.
 *
 * The two formats carry the same content - each skill's per-model grid and
 * minimal-model verdict, the repo grid across skills, and the cost totals - and
 * differ only in shape: the terminal form indents each grid under its skill
 * heading, while the markdown form emits headings, pipe tables, and bulleted
 * detail so a PR or doc renders it natively. The verdict always states the
 * threshold and trial count, and a single-trial verdict is labelled an
 * estimate, so a reader never mistakes one run for a settled answer.
 */
final readonly class MatrixRenderer {

  /**
   * The per-skill grid column headers.
   */
  public const array SKILL_HEADERS = ['model', 'trials', 'contract', 'judge', 'pass rate', 'verdict'];

  /**
   * Constructs a MatrixRenderer.
   *
   * @param \AlexSkrypnyk\SkillTest\Live\Matrix\MatrixReport $report
   *   The report to render.
   */
  public function __construct(
    protected MatrixReport $report,
  ) {}

  /**
   * Renders the whole report as a list of output lines.
   *
   * @param string $format
   *   Either `text` (default terminal) or `markdown`.
   *
   * @return string[]
   *   The rendered lines.
   */
  public function render(string $format): array {
    $markdown = $format === 'markdown';
    $lines = [];

    foreach ($this->report->skills as $skill) {
      $lines = array_merge($lines, $this->skillSection($skill, $markdown), ['']);
    }

    if (count($this->report->skills) > 1) {
      $lines = array_merge($lines, $this->repoSection($markdown), ['']);
    }

    return array_merge($lines, $this->costSection($markdown));
  }

  /**
   * Renders one skill's grid, verdict, failure modes, and cost delta.
   *
   * @param \AlexSkrypnyk\SkillTest\Live\Matrix\SkillMatrix $skill
   *   The skill's matrix.
   * @param bool $markdown
   *   Whether to render markdown.
   *
   * @return string[]
   *   The section lines.
   */
  protected function skillSection(SkillMatrix $skill, bool $markdown): array {
    $rows = array_map(static fn(MatrixModelRow $row): array => [$row->alias, (string) $row->trials, $row->contractCell(), $row->judgeCell(), $row->rate(), $row->verdict()], $skill->rows);

    $lines = $markdown ? ['### ' . $skill->skill, '', ...Table::markdown(self::SKILL_HEADERS, $rows), ''] : [$skill->skill, ...$this->indent(Table::text(self::SKILL_HEADERS, $rows)), ''];

    $lines[] = $this->text($this->verdictLine($skill), $markdown);

    $details = $this->detailLines($skill);

    if ($details !== []) {
      $lines[] = '';
      $lines = array_merge($lines, array_map(fn(string $detail): string => $this->bullet($detail, $markdown), $details));
    }

    return $lines;
  }

  /**
   * The minimal-model verdict line, with the trial-confidence caveat.
   *
   * @param \AlexSkrypnyk\SkillTest\Live\Matrix\SkillMatrix $skill
   *   The skill's matrix.
   *
   * @return string
   *   The verdict sentence.
   */
  protected function verdictLine(SkillMatrix $skill): string {
    $bar = sprintf('threshold %s, %d %s', number_format($skill->threshold, 2), $skill->trials, $skill->trials === 1 ? 'trial (a 1-trial verdict is an estimate)' : 'trials');

    if ($skill->minimal === NULL) {
      return sprintf('no minimal model: no ladder model passed every task (%s)', $bar);
    }

    return sprintf('minimal model: %s (%s)', $skill->minimal, $bar);
  }

  /**
   * The per-skill detail lines: each failing model's modes, then the delta.
   *
   * @param \AlexSkrypnyk\SkillTest\Live\Matrix\SkillMatrix $skill
   *   The skill's matrix.
   *
   * @return string[]
   *   The detail lines, in order.
   */
  protected function detailLines(SkillMatrix $skill): array {
    $lines = [];

    foreach ($skill->rows as $row) {
      $modes = $skill->failureModes[$row->alias] ?? NULL;
      if ($row->passed) {
        continue;
      }
      if (!$modes instanceof MatrixFailureModes) {
        continue;
      }
      if ($modes->isEmpty()) {
        continue;
      }

      $lines[] = sprintf('%s failure modes: %s', $row->alias, $modes->describe());
    }

    $delta = $this->costDeltaLine($skill);

    if ($delta !== NULL) {
      $lines[] = $delta;
    }

    return $lines;
  }

  /**
   * The economic punchline: the minimal model's per-run cost vs the default.
   *
   * @param \AlexSkrypnyk\SkillTest\Live\Matrix\SkillMatrix $skill
   *   The skill's matrix.
   *
   * @return string|null
   *   The cost line, or NULL when no model supports the skill.
   */
  protected function costDeltaLine(SkillMatrix $skill): ?string {
    if ($skill->minimal === NULL) {
      return NULL;
    }

    $minimal_cost = $skill->row($skill->minimal)?->perRunCost() ?? 0.0;
    $default = $this->report->defaultModel;

    if ($default === NULL) {
      return sprintf('minimal %s costs $%s/run', $skill->minimal, number_format($minimal_cost, 4));
    }

    $default_row = $skill->row($default);

    if (!$default_row instanceof MatrixModelRow) {
      return sprintf('minimal %s costs $%s/run (default %s was not run in this matrix)', $skill->minimal, number_format($minimal_cost, 4), $default);
    }

    $default_cost = $default_row->perRunCost();
    $delta = round($default_cost - $minimal_cost, 4);
    $economics = $delta >= 0 ? sprintf('saves $%s/run', number_format($delta, 4)) : sprintf('costs $%s/run more', number_format(-$delta, 4));

    return sprintf('minimal %s $%s/run vs default %s $%s/run (%s)', $skill->minimal, number_format($minimal_cost, 4), $default, number_format($default_cost, 4), $economics);
  }

  /**
   * Renders the repo-level grid across skills.
   *
   * @param bool $markdown
   *   Whether to render markdown.
   *
   * @return string[]
   *   The section lines.
   */
  protected function repoSection(bool $markdown): array {
    $headers = ['skill', ...$this->report->columns(), 'minimal'];
    $grid = $this->report->repoGrid();

    if ($markdown) {
      return ['### all skills', '', ...Table::markdown($headers, $grid)];
    }

    return ['all skills', ...$this->indent(Table::text($headers, $grid))];
  }

  /**
   * Renders the per-model and total cost line.
   *
   * @param bool $markdown
   *   Whether to render markdown.
   *
   * @return string[]
   *   The cost line.
   */
  protected function costSection(bool $markdown): array {
    $parts = [];

    foreach ($this->report->costPerModel() as $alias => $cost) {
      $parts[] = sprintf('%s $%s', $alias, number_format($cost, 4));
    }

    $summary = $parts === [] ? 'no cost recorded' : implode(', ', $parts);
    $line = sprintf('cost per model: %s. total $%s.', $summary, number_format($this->report->totalCost(), 4));

    return [$markdown ? '**' . $line . '**' : $line];
  }

  /**
   * Indents grid lines by two spaces for the terminal layout.
   *
   * @param string[] $lines
   *   The table lines.
   *
   * @return string[]
   *   The indented lines.
   */
  protected function indent(array $lines): array {
    return array_map(static fn(string $line): string => '  ' . $line, $lines);
  }

  /**
   * Renders a verdict-style line, indented in the terminal layout.
   *
   * @param string $line
   *   The line text.
   * @param bool $markdown
   *   Whether the markdown layout is in effect.
   *
   * @return string
   *   The formatted line.
   */
  protected function text(string $line, bool $markdown): string {
    return $markdown ? $line : '  ' . $line;
  }

  /**
   * Renders a detail line as a markdown bullet or an indented terminal line.
   *
   * @param string $line
   *   The line text.
   * @param bool $markdown
   *   Whether the markdown layout is in effect.
   *
   * @return string
   *   The formatted line.
   */
  protected function bullet(string $line, bool $markdown): string {
    return $markdown ? '- ' . $line : '  ' . $line;
  }

}
