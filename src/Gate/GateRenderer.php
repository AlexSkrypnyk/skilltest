<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Gate;

/**
 * Renders a gate report in each of the gate's output formats.
 *
 * One report, four shapes: a terse `human` summary for a terminal, a `json`
 * document for tooling, a `markdown` block for a PR comment, and
 * `github-actions` workflow annotations that surface each finding inline on the
 * diff. Every format reads the same {@see GateReport}, so the verdict and the
 * findings never diverge between them.
 */
final readonly class GateRenderer {

  /**
   * The supported output formats.
   */
  public const array FORMATS = ['human', 'json', 'markdown', 'github-actions'];

  /**
   * Renders a gate report in the requested format.
   *
   * @param \AlexSkrypnyk\SkillTest\Gate\GateReport $report
   *   The gate report.
   * @param string $format
   *   One of {@see FORMATS}.
   *
   * @return string
   *   The rendered report.
   */
  public function render(GateReport $report, string $format): string {
    return match ($format) {
      'json' => $this->json($report),
      'markdown' => $this->markdown($report),
      'github-actions' => $this->githubActions($report),
      default => $this->human($report),
    };
  }

  /**
   * Renders the terse human summary.
   *
   * @param \AlexSkrypnyk\SkillTest\Gate\GateReport $report
   *   The gate report.
   *
   * @return string
   *   The human report.
   */
  protected function human(GateReport $report): string {
    $lines = [sprintf('gate: %s', $this->verdict($report)), $this->rateLine($report)];

    if ($report->findings === []) {
      $lines[] = 'no regressions.';

      return implode("\n", $lines);
    }

    $lines[] = 'findings:';

    foreach ($report->findings as $finding) {
      $lines[] = sprintf('  %s [%s] %s', strtoupper($finding->severity), $finding->category, $finding->message);
    }

    return implode("\n", $lines);
  }

  /**
   * Renders the machine-readable JSON document.
   *
   * @param \AlexSkrypnyk\SkillTest\Gate\GateReport $report
   *   The gate report.
   *
   * @return string
   *   The pretty-printed JSON.
   */
  protected function json(GateReport $report): string {
    return json_encode([
      'gate' => $report->failed() ? 'fail' : 'pass',
      'baseline_pass_rate' => round($report->baselineRate, 4),
      'current_pass_rate' => round($report->currentRate, 4),
      'drop' => round($report->drop(), 4),
      'max_regression' => $report->maxRegression,
      'findings' => array_map(static fn(GateFinding $finding): array => $finding->toArray(), $report->findings),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
  }

  /**
   * Renders the markdown block for a PR comment.
   *
   * @param \AlexSkrypnyk\SkillTest\Gate\GateReport $report
   *   The gate report.
   *
   * @return string
   *   The markdown block.
   */
  protected function markdown(GateReport $report): string {
    $lines = [sprintf('### skilltest gate: %s', $this->verdict($report)), '', $this->rateLine($report)];

    if ($report->findings === []) {
      $lines[] = '';
      $lines[] = 'No regressions.';

      return implode("\n", $lines);
    }

    $lines[] = '';
    $lines[] = '| Severity | Category | Finding |';
    $lines[] = '| --- | --- | --- |';

    foreach ($report->findings as $finding) {
      $lines[] = sprintf('| %s | %s | %s |', strtoupper($finding->severity), $finding->category, $finding->message);
    }

    return implode("\n", $lines);
  }

  /**
   * Renders the GitHub Actions workflow annotations.
   *
   * @param \AlexSkrypnyk\SkillTest\Gate\GateReport $report
   *   The gate report.
   *
   * @return string
   *   The annotation lines and a closing notice summary.
   */
  protected function githubActions(GateReport $report): string {
    $lines = [];

    foreach ($report->findings as $finding) {
      $level = $finding->failed() ? 'error' : 'warning';
      $lines[] = sprintf('::%s title=skilltest gate::%s', $level, $this->escape($finding->message));
    }

    $lines[] = sprintf('::notice title=skilltest gate::%s', $this->escape(sprintf('gate %s - %s', $report->failed() ? 'failed' : 'passed', $this->rateSummary($report))));

    return implode("\n", $lines);
  }

  /**
   * The PASS/FAIL verdict word.
   *
   * @param \AlexSkrypnyk\SkillTest\Gate\GateReport $report
   *   The gate report.
   *
   * @return string
   *   `PASS` or `FAIL`.
   */
  protected function verdict(GateReport $report): string {
    return $report->failed() ? 'FAIL' : 'PASS';
  }

  /**
   * The human rate line: the two rates, the delta, and the tolerance.
   *
   * @param \AlexSkrypnyk\SkillTest\Gate\GateReport $report
   *   The gate report.
   *
   * @return string
   *   The rate line.
   */
  protected function rateLine(GateReport $report): string {
    return sprintf('pass rate: %s (%s; %s allowed).', $this->rates($report), $this->delta($report), Format::number($report->maxRegression));
  }

  /**
   * The `A%% -> B%% (delta)` summary shared by the annotation notice.
   *
   * @param \AlexSkrypnyk\SkillTest\Gate\GateReport $report
   *   The gate report.
   *
   * @return string
   *   The rate summary.
   */
  protected function rateSummary(GateReport $report): string {
    return sprintf('%s (%s)', $this->rates($report), $this->delta($report));
  }

  /**
   * The bare `A%% -> B%%` fragment both rate renderings build on.
   *
   * @param \AlexSkrypnyk\SkillTest\Gate\GateReport $report
   *   The gate report.
   *
   * @return string
   *   The two rates.
   */
  protected function rates(GateReport $report): string {
    return sprintf('%s%% -> %s%%', Format::number($report->baselineRate * 100), Format::number($report->currentRate * 100));
  }

  /**
   * The signed delta phrase for the rate line.
   *
   * @param \AlexSkrypnyk\SkillTest\Gate\GateReport $report
   *   The gate report.
   *
   * @return string
   *   `unchanged`, `down N points`, or `up N points`.
   */
  protected function delta(GateReport $report): string {
    $drop = $report->drop();

    if (abs($drop) < 0.05) {
      return 'unchanged';
    }

    return $drop > 0 ? sprintf('down %s points', Format::number($drop)) : sprintf('up %s points', Format::number(-$drop));
  }

  /**
   * Escapes a message for a GitHub Actions workflow command's data segment.
   *
   * @param string $message
   *   The message to escape.
   *
   * @return string
   *   The escaped message, with `%`, carriage returns, and newlines encoded.
   */
  protected function escape(string $message): string {
    return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $message);
  }

}
