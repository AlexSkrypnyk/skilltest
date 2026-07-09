<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Live\Responder;
use AlexSkrypnyk\SkillTest\Live\ResponderAction;
use AlexSkrypnyk\SkillTest\Live\ResponderConfig;
use AlexSkrypnyk\SkillTest\Live\ResponderDecision;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class ResponderTest.
 *
 * Unit test for the responder invocation seam, through an injected process seam
 * so no token is spent.
 */
#[CoversClass(Responder::class)]
final class ResponderTest extends TestCase {

  /**
   * The command the last responder invocation was given.
   */
  protected string $command = '';

  /**
   * The working directory the last responder invocation was given.
   */
  protected string $cwd = '';

  public function testRespondsAndPinsTheModelWithPersonaAndDialogue(): void {
    $responder = new Responder('claude', $this->runner([0, '{"action":"reply","message":"Team Board"}']));
    $config = new ResponderConfig('You are the repo owner.', 6, 'haiku-responder');

    $decision = $responder->respond($config, [['role' => 'agent', 'text' => 'Which board?']], '/repo');

    $this->assertInstanceOf(ResponderDecision::class, $decision);
    $this->assertSame(ResponderAction::Reply, $decision->action);
    $this->assertSame('Team Board', $decision->message);
    $this->assertSame('/repo', $this->cwd);
    $this->assertStringContainsString("--model 'haiku-responder'", $this->command);
    $this->assertStringContainsString('You are the repo owner.', $this->command);
    $this->assertStringContainsString('Which board?', $this->command);
  }

  public function testNonZeroExitYieldsNoDecision(): void {
    $responder = new Responder('claude', $this->runner([1, '{"action":"stop"}']));
    $config = new ResponderConfig('persona', 3, 'm');

    $this->assertNotInstanceOf(ResponderDecision::class, $responder->respond($config, [], '/repo'));
  }

  public function testUnparseableResponseYieldsNoDecision(): void {
    $responder = new Responder('claude', $this->runner([0, 'I cannot tell what to do.']));
    $config = new ResponderConfig('persona', 3, 'm');

    $this->assertNotInstanceOf(ResponderDecision::class, $responder->respond($config, [], '/repo'));
  }

  /**
   * Builds a runner that records its command and returns a queued outcome.
   *
   * @param array{int, string} $outcome
   *   The `[exit, stdout]` the runner returns.
   *
   * @return \Closure
   *   The runner closure.
   */
  protected function runner(array $outcome): \Closure {
    return function (string $command, string $cwd) use ($outcome): array {
      $this->command = $command;
      $this->cwd = $cwd;

      return $outcome;
    };
  }

}
