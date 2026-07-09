<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Live\ResponderPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class ResponderPromptTest.
 *
 * Unit test for the responder prompt builder.
 */
#[CoversClass(ResponderPrompt::class)]
final class ResponderPromptTest extends TestCase {

  public function testRendersPersonaDialogueAndTheDecisionShape(): void {
    $dialogue = [
      ['role' => 'agent', 'text' => 'What board should I use?'],
      ['role' => 'user', 'text' => 'Team Board'],
      ['role' => 'agent', 'text' => 'And the label?'],
    ];

    $prompt = ResponderPrompt::build("You are the repo owner.\n", $dialogue);

    $this->assertStringContainsString('role-playing the USER', $prompt);
    $this->assertStringContainsString('PERSONA:', $prompt);
    $this->assertStringContainsString('You are the repo owner.', $prompt);
    $this->assertStringContainsString('AGENT: What board should I use?', $prompt);
    $this->assertStringContainsString('USER (you): Team Board', $prompt);
    $this->assertStringContainsString('"action":"reply"', $prompt);
    $this->assertStringContainsString('"action":"stop"', $prompt);
    $this->assertStringContainsString('"action":"abstain"', $prompt);
  }

  public function testEmptyDialogueRendersNone(): void {
    $prompt = ResponderPrompt::build('persona', []);

    $this->assertStringContainsString('CONVERSATION SO FAR:', $prompt);
    $this->assertStringContainsString('(none)', $prompt);
  }

  public function testEmptyTurnTextRendersPlaceholder(): void {
    $prompt = ResponderPrompt::build('persona', [['role' => 'agent', 'text' => '   ']]);

    $this->assertStringContainsString('AGENT: (no text)', $prompt);
  }

}
