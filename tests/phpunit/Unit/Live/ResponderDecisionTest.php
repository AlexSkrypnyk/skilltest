<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Live\ResponderAction;
use AlexSkrypnyk\SkillTest\Live\ResponderDecision;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class ResponderDecisionTest.
 *
 * Unit test for the responder decision value object.
 */
#[CoversClass(ResponderDecision::class)]
final class ResponderDecisionTest extends TestCase {

  public function testCarriesActionAndMessage(): void {
    $decision = new ResponderDecision(ResponderAction::Reply, 'answer');

    $this->assertSame(ResponderAction::Reply, $decision->action);
    $this->assertSame('answer', $decision->message);
  }

  public function testMessageDefaultsToEmpty(): void {
    $decision = new ResponderDecision(ResponderAction::Stop);

    $this->assertSame('', $decision->message);
  }

}
