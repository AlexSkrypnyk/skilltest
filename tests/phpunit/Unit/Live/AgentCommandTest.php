<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Live\AgentCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class AgentCommandTest.
 *
 * Unit test for the headless agent command builder.
 */
#[CoversClass(AgentCommand::class)]
final class AgentCommandTest extends TestCase {

  #[DataProvider('dataProviderBuild')]
  public function testBuild(string $binary, string $prompt, ?string $model, ?int $max_turns, array $allowed, string $expected): void {
    $this->assertSame($expected, AgentCommand::build($binary, $prompt, $model, $max_turns, $allowed));
  }

  public function testAppendsMcpConfigWithTheStrictFlag(): void {
    $command = AgentCommand::build('claude', 'go', NULL, NULL, ['Bash'], '/ws/.skilltest-mcp.json');

    $this->assertStringContainsString('--allowedTools ' . escapeshellarg('Bash'), $command);
    $this->assertStringContainsString('--mcp-config ' . escapeshellarg('/ws/.skilltest-mcp.json') . ' --strict-mcp-config', $command);
  }

  #[DataProvider('dataProviderMcpConfigOmittedWhenEmpty')]
  public function testMcpConfigOmittedWhenEmpty(?string $mcp_config): void {
    $this->assertStringNotContainsString('--mcp-config', AgentCommand::build('claude', 'go', NULL, NULL, [], $mcp_config));
  }

  /**
   * Data provider for the omitted MCP config.
   *
   * @return \Iterator<string, array{string|null}>
   *   The cases.
   */
  public static function dataProviderMcpConfigOmittedWhenEmpty(): \Iterator {
    yield 'null is omitted' => [NULL];
    yield 'empty string is omitted' => [''];
  }

  /**
   * Data provider for command building.
   *
   * @return \Iterator<string, array{string, string, (string | null), (int | null), array<string>, string}>
   *   The cases.
   */
  public static function dataProviderBuild(): \Iterator {
    yield 'bare prompt only' => [
      'claude', 'do the thing', NULL, NULL, [],
      "claude -p 'do the thing' --output-format stream-json --verbose",
    ];
    yield 'model, turns, and tools' => [
      'claude', 'go', 'claude-haiku-4-5', 6, ['Bash', 'Edit'],
      "claude -p 'go' --output-format stream-json --verbose --model 'claude-haiku-4-5' --max-turns 6 --allowedTools 'Bash,Edit'",
    ];
    yield 'empty model is omitted' => [
      'claude', 'go', '', NULL, [],
      "claude -p 'go' --output-format stream-json --verbose",
    ];
    yield 'zero turns is still emitted' => [
      'claude', 'go', NULL, 0, [],
      "claude -p 'go' --output-format stream-json --verbose --max-turns 0",
    ];
    yield 'command-prefix binary is used verbatim' => [
      'php /tmp/stub.php', 'go', NULL, NULL, ['Bash'],
      "php /tmp/stub.php -p 'go' --output-format stream-json --verbose --allowedTools 'Bash'",
    ];
    yield 'prompt with quotes is escaped' => [
      'claude', "it's go time", NULL, NULL, [],
      "claude -p 'it'\\''s go time' --output-format stream-json --verbose",
    ];
  }

  #[DataProvider('dataProviderResume')]
  public function testResume(?string $resume, string $expected): void {
    $this->assertSame($expected, AgentCommand::build('claude', 'reply', 'claude-haiku-4-5', 6, ['Bash'], resume: $resume));
  }

  /**
   * Data provider for the resume flag.
   *
   * @return \Iterator<string, array{(string | null), string}>
   *   The cases.
   */
  public static function dataProviderResume(): \Iterator {
    $base = "claude -p 'reply' --output-format stream-json --verbose --model 'claude-haiku-4-5' --max-turns 6 --allowedTools 'Bash'";

    yield 'session id is appended' => ['sess-123', $base . " --resume 'sess-123'"];
    yield 'null session adds nothing' => [NULL, $base];
    yield 'empty session adds nothing' => ['', $base];
  }

}
