<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Structure;

use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Structure\CommandCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class CommandCatalogTest.
 *
 * Unit test for the command catalog, with an injected process runner.
 */
#[CoversClass(CommandCatalog::class)]
final class CommandCatalogTest extends TestCase {

  #[DataProvider('dataProviderParsesFirstTokens')]
  public function testParsesFirstTokens(string $stdout, array $expected): void {
    $catalog = new CommandCatalog('/repo', 'bin/harness', ['list', '--json'], fn(): array => [0, $stdout]);

    $this->assertSame($expected, $catalog->firstTokens());
  }

  public static function dataProviderParsesFirstTokens(): \Iterator {
    yield 'json array of strings' => ['["build","test"]', ['build', 'test']];
    yield 'json array of name objects' => ['[{"name":"build"},{"name":"test:unit"}]', ['build', 'test']];
    yield 'symfony commands wrapper' => ['{"commands":[{"name":"workflow:start"},{"name":"workflow:next"}]}', ['workflow']];
    yield 'space-separated names collapse to first token' => ['["workflow start","build"]', ['workflow', 'build']];
    yield 'plain text list, one per line' => ["build   Build the thing\ntest    Run tests\n", ['build', 'test']];
    yield 'blank lines are ignored' => ["\nbuild\n\ntest\n", ['build', 'test']];
  }

  public function testBinaryNameIsBasename(): void {
    $catalog = new CommandCatalog('/repo', 'bin/harness', [], fn(): array => [0, '["x"]']);

    $this->assertSame('harness', $catalog->binaryName());
  }

  public function testResolutionIsMemoised(): void {
    $calls = 0;
    $runner = function () use (&$calls): array {
      $calls++;

      return [0, '["build"]'];
    };

    $catalog = new CommandCatalog('/repo', 'bin/harness', [], $runner);
    $catalog->firstTokens();
    $catalog->firstTokens();

    $this->assertSame(1, $calls);
  }

  public function testBuildsEscapedListCommand(): void {
    $captured = '';
    $runner = function (string $command) use (&$captured): array {
      $captured = $command;

      return [0, '["build"]'];
    };

    (new CommandCatalog('/repo', 'bin/harness', ['list', '--json'], $runner))->firstTokens();

    $this->assertSame("'bin/harness' 'list' '--json'", $captured);
  }

  public function testNonZeroExitThrows(): void {
    $catalog = new CommandCatalog('/repo', 'bin/harness', [], fn(): array => [3, '']);

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage("command binary 'bin/harness' failed (exit 3)");

    $catalog->firstTokens();
  }

  #[DataProvider('dataProviderUnparseableOutput')]
  public function testUnparseableOutputThrows(string $stdout): void {
    $catalog = new CommandCatalog('/repo', 'bin/harness', [], fn(): array => [0, $stdout]);

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('produced no parseable command list');

    $catalog->firstTokens();
  }

  public static function dataProviderUnparseableOutput(): \Iterator {
    yield 'empty output' => [''];
    yield 'whitespace only' => ["  \n  \n"];
    yield 'empty json array' => ['[]'];
    yield 'empty json object' => ['{}'];
  }

}
