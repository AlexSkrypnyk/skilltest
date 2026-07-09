<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Judge;

use AlexSkrypnyk\SkillTest\Judge\JudgeCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class JudgeCommandTest.
 *
 * Unit test for the one-shot judge command builder.
 */
#[CoversClass(JudgeCommand::class)]
final class JudgeCommandTest extends TestCase {

  public function testBuildsPinnedOneShotCommand(): void {
    $command = JudgeCommand::build('claude', 'score this', 'claude-haiku-4-5');

    $this->assertSame("claude -p 'score this' --model 'claude-haiku-4-5'", $command);
  }

  public function testEscapesPromptAndModel(): void {
    $command = JudgeCommand::build('php /stub', "it's here", 'weird model');

    $this->assertStringContainsString("-p 'it'\\''s here'", $command);
    $this->assertStringContainsString("--model 'weird model'", $command);
    $this->assertStringStartsWith('php /stub -p ', $command);
  }

}
