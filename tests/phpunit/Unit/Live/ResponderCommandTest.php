<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Live\ResponderCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class ResponderCommandTest.
 *
 * Unit test for the one-shot responder command builder.
 */
#[CoversClass(ResponderCommand::class)]
final class ResponderCommandTest extends TestCase {

  #[DataProvider('dataProviderBuild')]
  public function testBuild(string $binary, string $prompt, string $model, string $expected): void {
    $this->assertSame($expected, ResponderCommand::build($binary, $prompt, $model));
  }

  /**
   * Data provider for command building.
   *
   * @return \Iterator<string, array{string, string, string, string}>
   *   The cases.
   */
  public static function dataProviderBuild(): \Iterator {
    yield 'prompt and model are pinned and escaped' => [
      'claude', 'play the user', 'claude-haiku-4-5',
      "claude -p 'play the user' --model 'claude-haiku-4-5'",
    ];
    yield 'command-prefix binary is used verbatim' => [
      'php /tmp/stub.php', 'go', 'm',
      "php /tmp/stub.php -p 'go' --model 'm'",
    ];
    yield 'quotes in the prompt are escaped' => [
      'claude', "it's you", 'm',
      "claude -p 'it'\\''s you' --model 'm'",
    ];
  }

}
