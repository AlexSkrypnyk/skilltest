<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Judge;

/**
 * Builds the strict-evaluator prompt the judge scores a trial with.
 *
 * The prompt frames the model as a strict evaluator scoring only against the
 * rubric, numbers each binary criterion so the verdict can reference it by id,
 * and shows the task input and the trial evidence it must judge. It ends with
 * the exact JSON shape the verdict parser expects and an instruction to return
 * only that, with per-criterion abstention allowed - so the judge grades the
 * evidence rather than guessing when the evidence is silent.
 */
final readonly class JudgePrompt {

  /**
   * Builds the judge prompt.
   *
   * @param string[] $criteria
   *   The binary rubric criteria, in order.
   * @param string $task_input
   *   The prompt the skill under test was given.
   * @param string $evidence
   *   The trial evidence: the transcript's tool calls and final output.
   *
   * @return string
   *   The assembled judge prompt.
   */
  public static function build(array $criteria, string $task_input, string $evidence): string {
    $lines = [];
    $number = 0;

    foreach ($criteria as $criterion) {
      $number++;
      $lines[] = sprintf('  %d. %s', $number, $criterion);
    }

    return implode("\n", [
      'SYSTEM: You are a strict evaluator. Score ONLY against the rubric below.',
      '        Judge each criterion from the evidence. If the evidence does not',
      '        show the answer, set that criterion\'s "unknown" to true rather',
      '        than guessing.',
      'RUBRIC (binary criteria):',
      implode("\n", $lines),
      'TASK INPUT:',
      $task_input,
      'EVIDENCE:',
      $evidence,
      'Return JSON only, with no prose and no code fences:',
      '  {"criteria":[{"id":1,"pass":true,"unknown":false}], "reasoning":"...", "unknown":false}',
    ]);
  }

}
