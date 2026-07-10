<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Results;

use AlexSkrypnyk\SkillTest\Config\Data;

/**
 * Turns a results document into one plain-language paragraph for the author.
 *
 * The numbers a run prints answer "what happened"; this answers "what do I do
 * now". A green run gets a one-sentence confirmation with the price when tokens
 * were spent. A red run names the single most important failure - the one
 * `Metrics` ranked first - and states a concrete next step for it, written for
 * the person who owns the skill rather than the person who owns the tool. It is
 * templated and reads only the document, so it never spends a token of its own.
 */
final class Interpreter {

  /**
   * Builds the interpretation paragraph for a results document.
   *
   * @param array<string, mixed> $document
   *   The results document.
   *
   * @return string
   *   A single-line, plain-language paragraph.
   */
  public static function paragraph(array $document): string {
    $aggregate = Metrics::aggregate($document);
    $failures = Metrics::failures($document);

    if ($failures === []) {
      return self::passing($aggregate);
    }

    return self::failing($aggregate, $failures[0]);
  }

  /**
   * The paragraph for a run with nothing failed.
   *
   * @param array<string, int|float> $aggregate
   *   The aggregate figures.
   *
   * @return string
   *   The paragraph.
   */
  protected static function passing(array $aggregate): string {
    if ($aggregate['checks'] === 0) {
      return 'Nothing ran: no checks were selected, so there is nothing to interpret.';
    }

    $line = sprintf('All %d check(s) passed; the selected skills met every gate this run covered.', $aggregate['checks']);
    $cost = self::costNote($aggregate);

    return $cost === '' ? $line : $line . ' ' . $cost;
  }

  /**
   * The paragraph for a run with at least one failure.
   *
   * @param array<string, int|float> $aggregate
   *   The aggregate figures.
   * @param array<string, mixed> $top
   *   The top-ranked finding.
   *
   * @return string
   *   The paragraph.
   */
  protected static function failing(array $aggregate, array $top): string {
    $lead = sprintf('%d of %d check(s) failed.', $aggregate['failures'], $aggregate['checks']);

    return $lead . ' ' . self::topFailure($top);
  }

  /**
   * The sentence naming the top failure and its concrete next step.
   *
   * @param array<string, mixed> $finding
   *   The top-ranked finding.
   *
   * @return string
   *   The sentence.
   */
  protected static function topFailure(array $finding): string {
    $scope = Data::toStringOrNull(Data::get($finding, 'scope')) ?? '';
    $id = Data::toStringOrNull(Data::get($finding, 'id')) ?? '';
    $detail = self::detail($finding);

    return match ($finding['kind']) {
      'security' => sprintf("Fix the security finding first: %s flagged '%s'%s. Remove the flagged pattern from the skill before anything else.", $id, $scope, self::suffix($detail)),
      'structure' => sprintf("Start with the structure failure %s in '%s'%s. Correct the skill file so the check passes, then re-run.", $id, $scope, self::suffix($detail)),
      'transcript' => sprintf("Start with the contract failure %s in '%s'%s. Re-record the fixture with `skilltest record --skill %s` once the skill drives the expected commands.", $id, $scope, self::suffix($detail), $scope),
      'hooks' => sprintf('Start with the hook failure %s%s. The repo hook did not behave as required; fix the hook or the behaviour it guards.', $id, self::suffix($detail)),
      'coverage' => sprintf("Start with the coverage gap in '%s'%s. Add an eval.yaml to that skill or exclude it in skilltest.yml with a reason.", $scope, self::suffix($detail)),
      default => self::llmFailure($finding, $scope),
    };
  }

  /**
   * The sentence for an llm model-on-task falling short of its threshold.
   *
   * @param array<string, mixed> $finding
   *   The llm finding.
   * @param string $scope
   *   The skill name.
   *
   * @return string
   *   The sentence.
   */
  protected static function llmFailure(array $finding, string $scope): string {
    $task = Data::toStringOrNull(Data::get($finding, 'task')) ?? '';
    $model = Data::toStringOrNull(Data::get($finding, 'model')) ?? '';
    $rate = (int) round((Data::toFloatOrNull(Data::get($finding, 'pass_rate')) ?? 0.0) * 100);
    $threshold = (int) round((Data::toFloatOrNull(Data::get($finding, 'threshold')) ?? 0.0) * 100);

    return sprintf("Start with task '%s' on %s in '%s': it passed %d%%, below the %d%% threshold. Strengthen the skill's guidance for that case, or inspect the failed trial transcripts.", $task, $model, $scope, $rate, $threshold);
  }

  /**
   * The best available human detail for a finding: message, then label.
   *
   * @param array<string, mixed> $finding
   *   The finding.
   *
   * @return string
   *   The detail, or an empty string when none is recorded.
   */
  protected static function detail(array $finding): string {
    $message = Data::toStringOrNull(Data::get($finding, 'message')) ?? '';

    if ($message !== '') {
      return $message;
    }

    return Data::toStringOrNull(Data::get($finding, 'label')) ?? '';
  }

  /**
   * Renders a detail as a parenthetical suffix, or nothing when empty.
   *
   * @param string $detail
   *   The detail text.
   *
   * @return string
   *   The suffix, e.g. ` (detail)`, or an empty string.
   */
  protected static function suffix(string $detail): string {
    return $detail === '' ? '' : sprintf(' (%s)', $detail);
  }

  /**
   * The optional price note, present only when a run spent tokens.
   *
   * @param array<string, int|float> $aggregate
   *   The aggregate figures.
   *
   * @return string
   *   The note, or an empty string when nothing was spent.
   */
  protected static function costNote(array $aggregate): string {
    if ($aggregate['tokens_in'] + $aggregate['tokens_out'] <= 0) {
      return '';
    }

    return sprintf('That cost %d trial(s) and $%s in tokens.', $aggregate['trials'], number_format((float) $aggregate['cost_usd'], 4));
  }

}
