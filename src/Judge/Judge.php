<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Judge;

use AlexSkrypnyk\SkillTest\Contract\Transcript;
use AlexSkrypnyk\SkillTest\Process\ProcessRunner;

/**
 * Scores one trial against a rubric with a pinned, one-shot judge model.
 *
 * This is the invocation seam the live suite drives when a skill declares a
 * rubric: it assembles the trial evidence from the transcript, builds the
 * strict-evaluator prompt, runs the judge through the same stubbable process
 * seam the runner uses, and parses the verdict. The judge model is pinned by
 * the caller and never derived from the execution model. A judge process that
 * exits non-zero and a verdict that cannot be parsed are both judge failures
 * raised as a {@see JudgeException}, so a broken judge is a distinct result
 * rather than a silent pass or a skill failure. The process seam is injectable
 * so scoring is tested against recorded verdicts without spending a token.
 */
final readonly class Judge {

  /**
   * The default wall-clock budget, in seconds, for one judge call.
   */
  public const float DEFAULT_TIMEOUT = 120.0;

  /**
   * Runs a command and returns its exit code and captured stdout.
   *
   * @var \Closure(string, string): array{0: int, 1: string}
   */
  protected \Closure $runner;

  /**
   * Constructs a Judge.
   *
   * @param string $binary
   *   The agent binary (or command prefix) invoked with `-p <prompt>`.
   * @param \Closure|null $runner
   *   A runner taking the assembled command and working directory and returning
   *   `[exitCode, stdout]`. Defaults to a real process run via ProcessRunner.
   * @param float $timeout
   *   The wall-clock budget, in seconds, before the judge call is terminated.
   */
  public function __construct(
    protected string $binary,
    ?\Closure $runner = NULL,
    float $timeout = self::DEFAULT_TIMEOUT,
  ) {
    $this->runner = $runner ?? (new ProcessRunner($timeout))->run(...);
  }

  /**
   * Scores a trial's transcript against a rubric.
   *
   * @param string[] $rubric
   *   The binary rubric criteria.
   * @param string $task_prompt
   *   The prompt the skill under test was given.
   * @param string $transcript
   *   The trial's raw stream-json transcript, the evidence to judge.
   * @param string $model
   *   The resolved judge model id.
   * @param string $cwd
   *   The working directory the judge call runs in.
   *
   * @return \AlexSkrypnyk\SkillTest\Judge\JudgeVerdict
   *   The parsed verdict, guaranteed to cover exactly the rubric.
   *
   * @throws \AlexSkrypnyk\SkillTest\Judge\JudgeException
   *   When the judge process fails, returns an unparseable verdict, or scores a
   *   set of criteria that does not match the rubric one-for-one.
   */
  public function evaluate(array $rubric, string $task_prompt, string $transcript, string $model, string $cwd): JudgeVerdict {
    $prompt = JudgePrompt::build($rubric, $task_prompt, self::evidence($transcript));
    $command = JudgeCommand::build($this->binary, $prompt, $model);

    [$exit_code, $stdout] = ($this->runner)($command, $cwd);

    if ($exit_code !== 0) {
      throw new JudgeException(sprintf('the judge run exited with code %d.', $exit_code));
    }

    $verdict = (new VerdictParser())->parse($stdout);

    self::assertCoversRubric($verdict, count($rubric));

    return $verdict;
  }

  /**
   * Rejects a verdict that does not score exactly the rubric, one id per line.
   *
   * A judge that omits, duplicates, or invents a criterion id has not produced
   * a usable verdict for the rubric, so gating on the criteria it did return
   * would silently pass a trial on an unproven criterion. Requiring the id set
   * to be exactly `1..N` makes an incomplete verdict a judge failure instead.
   *
   * @param \AlexSkrypnyk\SkillTest\Judge\JudgeVerdict $verdict
   *   The parsed verdict.
   * @param int $expected
   *   The number of rubric criteria the verdict must cover.
   *
   * @throws \AlexSkrypnyk\SkillTest\Judge\JudgeException
   *   When the verdict's criterion ids are not exactly `1..$expected`.
   */
  protected static function assertCoversRubric(JudgeVerdict $verdict, int $expected): void {
    $ids = array_map(static fn(JudgeCriterion $criterion): int => $criterion->id, $verdict->criteria);
    sort($ids);

    if ($ids !== range(1, $expected)) {
      throw new JudgeException(sprintf('the judge scored criteria [%s] but the rubric has %d.', implode(', ', $ids), $expected));
    }
  }

  /**
   * Renders the trial evidence a judge scores from a transcript.
   *
   * The judge scores evidence, not vibes: the tool calls the skill made and the
   * final output it produced. Both are pulled from the same stream-json
   * transcript the contract engine grades, so the judge sees exactly what the
   * run did. When the run was an interactive conversation, the responder turns
   * that drove it are surfaced too, so the judge scores the dialogue rather
   * than an agent talking to itself.
   *
   * @param string $transcript
   *   The raw stream-json transcript.
   *
   * @return string
   *   The rendered evidence block.
   */
  protected static function evidence(string $transcript): string {
    $parsed = new Transcript($transcript);
    $lines = [];
    $number = 0;

    foreach ($parsed->toolUses() as $use) {
      $number++;
      $lines[] = sprintf('  %d. %s %s', $number, $use['name'], json_encode($use['input'], JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    $output = $parsed->resultText();

    return implode("\n", [
      ...self::conversationSection($parsed->responderTurns()),
      'TOOL CALLS:',
      $lines === [] ? '  (none)' : implode("\n", $lines),
      'FINAL OUTPUT:',
      $output === '' ? '  (none)' : $output,
    ]);
  }

  /**
   * Renders the responder-turn section of the evidence, when there is one.
   *
   * @param list<string> $turns
   *   The responder turns recorded into the transcript, in order.
   *
   * @return string[]
   *   The section lines, or an empty array for a non-interactive run so the
   *   evidence of a single-shot trial is unchanged.
   */
  protected static function conversationSection(array $turns): array {
    if ($turns === []) {
      return [];
    }

    $lines = [];
    $number = 0;

    foreach ($turns as $turn) {
      $number++;
      $lines[] = sprintf('  %d. %s', $number, $turn);
    }

    return ['RESPONDER TURNS (the user the responder played):', implode("\n", $lines)];
  }

}
