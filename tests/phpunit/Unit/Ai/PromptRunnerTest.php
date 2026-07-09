<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Ai;

use AlexSkrypnyk\SkillTest\Ai\PromptRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class PromptRunnerTest.
 *
 * Unit test for the one-shot prompt seam, with an injected process runner.
 */
#[CoversClass(PromptRunner::class)]
final class PromptRunnerTest extends TestCase {

  public function testReturnsStdoutOnSuccessAndBuildsCommand(): void {
    $captured = [];
    $runner = function (string $command, string $cwd) use (&$captured): array {
      $captured = ['command' => $command, 'cwd' => $cwd];

      return [0, 'the model reply'];
    };

    $result = (new PromptRunner($runner))->run('draft this');

    $this->assertSame('the model reply', $result);
    $this->assertSame('.', $captured['cwd']);
    $this->assertStringStartsWith('claude -p ', $captured['command']);
    $this->assertStringContainsString(escapeshellarg('draft this'), $captured['command']);
  }

  public function testReturnsNullOnNonZeroExit(): void {
    $runner = fn(string $command, string $cwd): array => [1, 'half a reply'];

    $this->assertNull((new PromptRunner($runner))->run('draft this'));
  }

  public function testUsesConfiguredBinary(): void {
    $captured = '';
    $runner = function (string $command, string $cwd) use (&$captured): array {
      $captured = $command;

      return [0, ''];
    };

    (new PromptRunner($runner, 'php fake-claude.php'))->run('x');

    $this->assertStringStartsWith('php fake-claude.php -p ', $captured);
  }

}
