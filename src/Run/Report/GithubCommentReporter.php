<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Run\Report;

use AlexSkrypnyk\SkillTest\Config\Data;

/**
 * Renders a results document as a GitHub-flavoured-markdown PR comment block.
 *
 * A status line, a summary table, an expanded failure list, and - when the run
 * carried llm results - a per-skill matrix grid, sized to fit inside GitHub's
 * comment limit. When the body would overflow the limit it is truncated and a
 * note says so, so the comment always posts. Every number comes from the
 * document; the reporter computes no verdicts of its own.
 */
final class GithubCommentReporter {

  /**
   * GitHub's hard maximum length, in characters, for a single comment body.
   */
  public const int LIMIT = 65536;

  /**
   * The deterministic groups scanned for failures, in per-skill order.
   */
  protected const array GROUPS = ['structure', 'security', 'transcript'];

  /**
   * The note appended when the body is truncated to fit the comment limit.
   */
  protected const string TRUNCATION_NOTE = "\n\n_Report truncated to fit GitHub's 65536-character comment limit._\n";

  /**
   * Renders the results document as a markdown comment body.
   *
   * @param array<mixed> $document
   *   The results document, as produced by RunReport::toResults().
   *
   * @return string
   *   The markdown block, capped at the comment limit.
   */
  public function render(array $document): string {
    $checks = Data::toIntOrNull(Data::get($document, 'totals', 'checks')) ?? 0;
    $failures = Data::toIntOrNull(Data::get($document, 'totals', 'failures')) ?? 0;

    $lines = ['### skilltest results', '', $this->status($document, $checks, $failures), ''];

    foreach ($this->summary($document, $checks, $failures) as $line) {
      $lines[] = $line;
    }

    foreach ($this->failures($document) as $line) {
      $lines[] = $line;
    }

    foreach ($this->matrix($document) as $line) {
      $lines[] = $line;
    }

    return $this->cap(implode("\n", $lines) . "\n");
  }

  /**
   * Builds the one-line pass or fail status, naming the run.
   *
   * @param array<mixed> $document
   *   The results document.
   * @param int $checks
   *   The total check count.
   * @param int $failures
   *   The total failure count.
   *
   * @return string
   *   The status line.
   */
  protected function status(array $document, int $checks, int $failures): string {
    $id = Data::toStringOrNull(Data::get($document, 'run', 'id')) ?? '';
    $command = Data::toStringOrNull(Data::get($document, 'run', 'command')) ?? '';
    $environment = Data::toStringOrNull(Data::get($document, 'run', 'environment')) ?? '';

    $verdict = $failures === 0 ? sprintf('✅ All %d checks passed', $checks) : sprintf('❌ %d of %d checks failed', $failures, $checks);

    return sprintf('%s - run `%s` (%s, %s)', $verdict, $id, $command, $environment);
  }

  /**
   * Builds the summary table, including trial, token, and cost rows when set.
   *
   * @param array<mixed> $document
   *   The results document.
   * @param int $checks
   *   The total check count.
   * @param int $failures
   *   The total failure count.
   *
   * @return string[]
   *   The markdown table lines.
   */
  protected function summary(array $document, int $checks, int $failures): array {
    $rows = [
      ['Checks', (string) $checks],
      ['Passed', (string) ($checks - $failures)],
      ['Failed', (string) $failures],
    ];

    $trials = Data::toIntOrNull(Data::get($document, 'totals', 'trials')) ?? 0;

    if ($trials > 0) {
      $rows[] = ['Trials', (string) $trials];
    }

    $tokens_in = Data::toIntOrNull(Data::get($document, 'totals', 'tokens', 'in')) ?? 0;
    $tokens_out = Data::toIntOrNull(Data::get($document, 'totals', 'tokens', 'out')) ?? 0;

    if ($tokens_in + $tokens_out > 0) {
      $rows[] = ['Tokens', sprintf('%d in / %d out', $tokens_in, $tokens_out)];
    }

    $cost = Data::toFloatOrNull(Data::get($document, 'totals', 'cost_usd')) ?? 0.0;

    if ($cost > 0) {
      $rows[] = ['Cost', sprintf('$%.2f', $cost)];
    }

    $lines = ['| Metric | Value |', '| --- | --- |'];

    foreach ($rows as [$metric, $value]) {
      $lines[] = sprintf('| %s | %s |', $metric, $value);
    }

    return $lines;
  }

  /**
   * Builds the expanded failure list across skills, hooks, and coverage.
   *
   * @param array<mixed> $document
   *   The results document.
   *
   * @return string[]
   *   The failure section lines, or an empty array when nothing failed.
   */
  protected function failures(array $document): array {
    $failures = [];

    foreach (Data::toArrayList(Data::get($document, 'skills')) as $skill) {
      $name = Data::toStringOrNull(Data::get($skill, 'skill')) ?? '';

      foreach (self::GROUPS as $group) {
        foreach (Data::toArrayList(Data::get($skill, 'deterministic', $group)) as $check) {
          $this->collect($failures, $check, $name . '.' . $group);
        }
      }

      foreach ($this->trialFailures($skill, $name) as $failure) {
        $failures[] = $failure;
      }
    }

    foreach (Data::toArrayList(Data::get($document, 'hooks')) as $hook) {
      $this->collect($failures, $hook, 'hooks');
    }

    foreach (Data::toArrayList(Data::get($document, 'coverage', 'violations')) as $violation) {
      $this->collect($failures, $violation, 'coverage');
    }

    if ($failures === []) {
      return [];
    }

    return array_merge(['', '#### Failures', ''], $failures);
  }

  /**
   * Appends a rendered failure line for a check that did not pass.
   *
   * @param string[] $failures
   *   The accumulating list of rendered failure lines, appended in place.
   * @param array<mixed> $check
   *   The check row to inspect.
   * @param string $scope
   *   The scope label (skill.group, hooks, or coverage).
   */
  protected function collect(array &$failures, array $check, string $scope): void {
    if (Data::toBoolOrNull(Data::get($check, 'pass')) ?? FALSE) {
      return;
    }

    $id = Data::toStringOrNull(Data::get($check, 'check')) ?? '';
    $label = Data::toStringOrNull(Data::get($check, 'label')) ?? '';
    $evidence = Data::toStringOrNull(Data::get($check, 'evidence')) ?? '';
    $message = Data::toStringOrNull(Data::get($check, 'message')) ?? '';

    $failures[] = $this->line($id, $scope, $message !== '' ? $message : $label, $evidence);
  }

  /**
   * Builds the failure lines for one skill's failed llm trials.
   *
   * @param array<mixed> $skill
   *   One skill entry.
   * @param string $name
   *   The skill name.
   *
   * @return string[]
   *   The rendered trial failure lines.
   */
  protected function trialFailures(array $skill, string $name): array {
    $lines = [];

    foreach (Data::toArrayList(Data::get($skill, 'llm', 'tasks')) as $task) {
      $task_name = Data::toStringOrNull(Data::get($task, 'task')) ?? '';

      foreach (Data::toArrayList(Data::get($task, 'models')) as $model) {
        $model_id = Data::toStringOrNull(Data::get($model, 'model')) ?? '';
        $alias = Data::toStringOrNull(Data::get($model, 'alias')) ?? $model_id;

        foreach (Data::toArrayList(Data::get($model, 'trials')) as $trial) {
          if (Data::toBoolOrNull(Data::get($trial, 'pass')) ?? FALSE) {
            continue;
          }

          $number = Data::toIntOrNull(Data::get($trial, 'trial')) ?? 0;
          $id = sprintf('%s.%s.trial-%d', $task_name, $alias, $number);
          $lines[] = $this->line($id, $name . '.llm', TrialSummary::line($trial), $model_id);
        }
      }
    }

    return $lines;
  }

  /**
   * Formats one failure list item: id, scope, detail, and evidence code span.
   *
   * @param string $id
   *   The check id.
   * @param string $scope
   *   The scope label.
   * @param string $detail
   *   The message or label, when present.
   * @param string $evidence
   *   The matched or missing evidence, when present.
   *
   * @return string
   *   The rendered markdown list item.
   */
  protected function line(string $id, string $scope, string $detail, string $evidence): string {
    $line = sprintf('- `%s` (%s)', $id, $scope);

    if ($detail !== '') {
      $line .= ' - ' . $detail;
    }

    if ($evidence !== '') {
      $line .= '  `' . $this->codeSafe($evidence) . '`';
    }

    return $line;
  }

  /**
   * Builds a per-skill matrix grid for every skill that recorded llm results.
   *
   * @param array<mixed> $document
   *   The results document.
   *
   * @return string[]
   *   The matrix section lines, or an empty array when no skill ran llm tasks.
   */
  protected function matrix(array $document): array {
    $lines = [];

    foreach (Data::toArrayList(Data::get($document, 'skills')) as $skill) {
      $tasks = Data::toArrayList(Data::get($skill, 'llm', 'tasks'));

      if ($tasks === []) {
        continue;
      }

      $name = Data::toStringOrNull(Data::get($skill, 'skill')) ?? '';

      foreach ($this->matrixSection($skill, $tasks, $name) as $line) {
        $lines[] = $line;
      }
    }

    return $lines;
  }

  /**
   * Builds one skill's matrix subsection: the verdict and a task-by-model grid.
   *
   * @param array<mixed> $skill
   *   One skill entry.
   * @param array<int, array<mixed>> $tasks
   *   The skill's llm tasks.
   * @param string $name
   *   The skill name.
   *
   * @return string[]
   *   The subsection lines.
   */
  protected function matrixSection(array $skill, array $tasks, string $name): array {
    $columns = $this->modelColumns($tasks);

    $lines = ['', sprintf('#### Matrix: %s', $name), '', $this->verdict($skill), ''];

    $lines[] = '| Task | ' . implode(' | ', $columns) . ' |';
    $lines[] = '| --- |' . str_repeat(' --- |', count($columns));

    foreach ($tasks as $task) {
      $rates = $this->passRates($task);
      $cells = array_map(static fn(string $column): string => $rates[$column] ?? '-', $columns);
      $lines[] = sprintf('| %s | %s |', Data::toStringOrNull(Data::get($task, 'task')) ?? '', implode(' | ', $cells));
    }

    return $lines;
  }

  /**
   * Collects the model columns across a skill's tasks, in first-seen order.
   *
   * @param array<int, array<mixed>> $tasks
   *   The skill's llm tasks.
   *
   * @return string[]
   *   The ordered, de-duplicated model column labels.
   */
  protected function modelColumns(array $tasks): array {
    $columns = [];

    foreach ($tasks as $task) {
      foreach (Data::toArrayList(Data::get($task, 'models')) as $model) {
        $label = Data::toStringOrNull(Data::get($model, 'alias')) ?? Data::toStringOrNull(Data::get($model, 'model')) ?? '';
        $columns[$label] = $label;
      }
    }

    return array_values($columns);
  }

  /**
   * Maps each of a task's model columns to its rendered pass-rate cell.
   *
   * @param array<mixed> $task
   *   One llm task entry.
   *
   * @return array<string, string>
   *   The pass-rate cell keyed by model column label.
   */
  protected function passRates(array $task): array {
    $rates = [];

    foreach (Data::toArrayList(Data::get($task, 'models')) as $model) {
      $label = Data::toStringOrNull(Data::get($model, 'alias')) ?? Data::toStringOrNull(Data::get($model, 'model')) ?? '';
      $rate = Data::toFloatOrNull(Data::get($model, 'pass_rate'));
      $rates[$label] = $rate === NULL ? '-' : round($rate * 100) . '%';
    }

    return $rates;
  }

  /**
   * Renders the minimal-model verdict line for one skill.
   *
   * @param array<mixed> $skill
   *   One skill entry.
   *
   * @return string
   *   The verdict line.
   */
  protected function verdict(array $skill): string {
    $minimal = Data::toStringOrNull(Data::get($skill, 'llm', 'verdict', 'minimal_model')) ?? 'none';
    $threshold = Data::toFloatOrNull(Data::get($skill, 'llm', 'verdict', 'threshold')) ?? 0.0;
    $trials = Data::toIntOrNull(Data::get($skill, 'llm', 'verdict', 'trials')) ?? 0;

    return sprintf('Minimal model: **%s** (threshold %s, %d trials)', $minimal, rtrim(rtrim(sprintf('%.2f', $threshold), '0'), '.'), $trials);
  }

  /**
   * Neutralises characters that would break an inline code span.
   *
   * @param string $evidence
   *   The evidence text.
   *
   * @return string
   *   The evidence with backticks and newlines flattened.
   */
  protected function codeSafe(string $evidence): string {
    return str_replace(['`', "\n", "\r"], ["'", ' ', ' '], $evidence);
  }

  /**
   * Caps the body at the comment limit, appending a note when it overflows.
   *
   * @param string $body
   *   The rendered markdown body.
   *
   * @return string
   *   The body, truncated to the character limit when necessary.
   */
  protected function cap(string $body): string {
    if (mb_strlen($body) <= self::LIMIT) {
      return $body;
    }

    return mb_substr($body, 0, self::LIMIT - mb_strlen(self::TRUNCATION_NOTE)) . self::TRUNCATION_NOTE;
  }

}
