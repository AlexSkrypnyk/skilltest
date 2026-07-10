<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Results;

use AlexSkrypnyk\SkillTest\Results\Interpreter;
use AlexSkrypnyk\SkillTest\Tests\Traits\ResultsDocumentTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class InterpreterTest.
 *
 * Unit test for the plain-language interpretation: a passing run is confirmed
 * (with a price when tokens were spent), and a failing run names its
 * highest-priority failure and a concrete next step for it.
 */
#[CoversClass(Interpreter::class)]
#[Group('results')]
final class InterpreterTest extends TestCase {

  use ResultsDocumentTrait;

  public function testPassingRunIsConfirmedWithoutCostWhenTokenFree(): void {
    $document = $this->document([$this->skill('alpha', [$this->check('structure.frontmatter', TRUE)])], [], [], ['checks' => 4, 'failures' => 0]);

    $paragraph = Interpreter::paragraph($document);

    $this->assertStringContainsString('All 4 check(s) passed', $paragraph);
    $this->assertStringNotContainsString('cost', $paragraph);
  }

  public function testPassingRunReportsPriceWhenTokensWereSpent(): void {
    $document = $this->document([], [], [], ['checks' => 6, 'failures' => 0, 'trials' => 18, 'tokens' => ['in' => 500, 'out' => 200], 'cost_usd' => 0.42]);

    $paragraph = Interpreter::paragraph($document);

    $this->assertStringContainsString('All 6 check(s) passed', $paragraph);
    $this->assertStringContainsString('18 trial(s)', $paragraph);
    $this->assertStringContainsString('$0.4200', $paragraph);
  }

  public function testEmptyRunSaysNothingRan(): void {
    $paragraph = Interpreter::paragraph($this->document());

    $this->assertStringContainsString('Nothing ran', $paragraph);
  }

  public function testSecurityFailureIsNamedFirstWithRemoveStep(): void {
    $document = $this->document(
      [$this->skill('alpha', [$this->check('structure.frontmatter', FALSE)], [$this->check('security.danger', FALSE, 'rm -rf', 'rm -rf /')], [])],
      [],
      [],
      ['checks' => 2, 'failures' => 2],
    );

    $paragraph = Interpreter::paragraph($document);

    $this->assertStringContainsString('2 of 2 check(s) failed.', $paragraph);
    $this->assertStringContainsString('security finding first', $paragraph);
    $this->assertStringContainsString("security.danger flagged 'alpha'", $paragraph);
    $this->assertStringContainsString('Remove the flagged pattern', $paragraph);
  }

  public function testStructureFailureSuggestsCorrectingTheSkillFile(): void {
    $document = $this->document([$this->skill('alpha', [$this->check('structure.name', FALSE, 'name matches dir', '', 'name mismatch')])], [], [], ['checks' => 1, 'failures' => 1]);

    $paragraph = Interpreter::paragraph($document);

    $this->assertStringContainsString('structure failure structure.name', $paragraph);
    $this->assertStringContainsString('(name mismatch)', $paragraph);
    $this->assertStringContainsString('Correct the skill file', $paragraph);
  }

  public function testTranscriptFailureSuggestsReRecording(): void {
    $document = $this->document([$this->skill('alpha', [], [], [$this->check('contract.commands.required', FALSE, 'runs build', '', 'missing required command')])], [], [], ['checks' => 1, 'failures' => 1]);

    $paragraph = Interpreter::paragraph($document);

    $this->assertStringContainsString('contract failure contract.commands.required', $paragraph);
    $this->assertStringContainsString('skilltest record --skill alpha', $paragraph);
  }

  public function testHookFailureSuggestsFixingTheHook(): void {
    $document = $this->document([], [$this->check('hooks.guard', FALSE, 'blocks push', '', 'allowed but must block')], [], ['checks' => 1, 'failures' => 1]);

    $paragraph = Interpreter::paragraph($document);

    $this->assertStringContainsString('hook failure hooks.guard', $paragraph);
    $this->assertStringContainsString('fix the hook', $paragraph);
  }

  public function testCoverageFailureSuggestsAddingEvalYaml(): void {
    $document = $this->document([], [], [$this->check('coverage.eval-exists', FALSE, '', '', "skill 'gamma' has no eval.yaml")], ['checks' => 1, 'failures' => 1]);

    $paragraph = Interpreter::paragraph($document);

    $this->assertStringContainsString('coverage gap', $paragraph);
    $this->assertStringContainsString('Add an eval.yaml', $paragraph);
  }

  public function testLlmFailureNamesTaskModelAndThreshold(): void {
    $document = $this->document(
      [$this->skill('alpha', [], [], [], $this->llm([$this->task('invoked', 'claude-haiku-4-5', 'haiku', [], 0.33)], ['minimal_model' => NULL, 'threshold' => 0.8, 'trials' => 3]))],
      [],
      [],
      ['checks' => 1, 'failures' => 1],
    );

    $paragraph = Interpreter::paragraph($document);

    $this->assertStringContainsString("task 'invoked' on haiku in 'alpha'", $paragraph);
    $this->assertStringContainsString('passed 33%, below the 80% threshold', $paragraph);
    $this->assertStringContainsString("Strengthen the skill's guidance", $paragraph);
  }

  public function testHigherPriorityFailureOutranksLlm(): void {
    $document = $this->document(
      [$this->skill('alpha', [$this->check('structure.frontmatter', FALSE)], [], [], $this->llm([$this->task('invoked', 'claude-haiku-4-5', 'haiku', [], 0.2)], ['minimal_model' => NULL, 'threshold' => 0.8, 'trials' => 3]))],
      [],
      [],
      ['checks' => 2, 'failures' => 2],
    );

    $paragraph = Interpreter::paragraph($document);

    $this->assertStringContainsString('structure failure', $paragraph);
    $this->assertStringNotContainsString('Strengthen', $paragraph);
  }

}
