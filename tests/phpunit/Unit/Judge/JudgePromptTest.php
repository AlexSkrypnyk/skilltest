<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Judge;

use AlexSkrypnyk\SkillTest\Judge\JudgePrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class JudgePromptTest.
 *
 * Unit test for the strict-evaluator judge prompt builder.
 */
#[CoversClass(JudgePrompt::class)]
final class JudgePromptTest extends TestCase {

  public function testNumbersRubricAndCarriesInputAndEvidence(): void {
    $prompt = JudgePrompt::build(
      ['Names the issue being fixed.', 'Lists the real code changes.'],
      'Write the PR description.',
      'TOOL CALLS:\n  1. Bash {"command":"git diff"}',
    );

    $this->assertStringContainsString('  1. Names the issue being fixed.', $prompt);
    $this->assertStringContainsString('  2. Lists the real code changes.', $prompt);
    $this->assertStringContainsString('strict evaluator', $prompt);
    $this->assertStringContainsString('Write the PR description.', $prompt);
    $this->assertStringContainsString('git diff', $prompt);
    $this->assertStringContainsString('Return JSON only', $prompt);
    $this->assertStringContainsString('"unknown":false', $prompt);
  }

  public function testEmptyRubricStillProducesTheContract(): void {
    $prompt = JudgePrompt::build([], 'task', 'evidence');

    $this->assertStringContainsString('RUBRIC (binary criteria):', $prompt);
    $this->assertStringContainsString('Return JSON only', $prompt);
  }

}
