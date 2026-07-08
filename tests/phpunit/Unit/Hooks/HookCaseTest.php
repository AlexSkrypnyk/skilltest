<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Hooks;

use AlexSkrypnyk\SkillTest\Hooks\HookCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class HookCaseTest.
 *
 * Unit test for the hook case value object: the PreToolUse payload it renders,
 * its expect logic, and the input summary used as evidence.
 */
#[CoversClass(HookCase::class)]
final class HookCaseTest extends TestCase {

  public function testPayloadRendersPreToolUseEnvelope(): void {
    $case = new HookCase('Bash', ['command' => 'gh pr create --title x'], HookCase::EXPECT_BLOCK);

    $this->assertSame('{"hook_event_name":"PreToolUse","tool_name":"Bash","tool_input":{"command":"gh pr create --title x"}}', $case->payload());
  }

  public function testPayloadRendersEmptyInputAsObject(): void {
    $case = new HookCase('Write', [], HookCase::EXPECT_ALLOW);

    $this->assertSame('{"hook_event_name":"PreToolUse","tool_name":"Write","tool_input":{}}', $case->payload());
  }

  #[DataProvider('dataProviderExpectsBlock')]
  public function testExpectsBlock(string $expect, bool $expected): void {
    $this->assertSame($expected, (new HookCase('Bash', [], $expect))->expectsBlock());
  }

  public static function dataProviderExpectsBlock(): \Iterator {
    yield 'block' => [HookCase::EXPECT_BLOCK, TRUE];
    yield 'allow' => [HookCase::EXPECT_ALLOW, FALSE];
  }

  #[DataProvider('dataProviderInputSummary')]
  public function testInputSummary(array $input, string $expected): void {
    $this->assertSame($expected, (new HookCase('Bash', $input, HookCase::EXPECT_ALLOW))->inputSummary());
  }

  public static function dataProviderInputSummary(): \Iterator {
    yield 'string command shown verbatim' => [['command' => 'gh pr view 1'], 'gh pr view 1'];
    yield 'non-command input shown as json object' => [['file_path' => '/tmp/x'], '{"file_path":"/tmp/x"}'];
    yield 'empty input shown as empty object' => [[], '{}'];
    yield 'non-string command falls back to json' => [['command' => 123], '{"command":123}'];
  }

}
