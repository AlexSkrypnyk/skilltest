<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Contract;

use AlexSkrypnyk\SkillTest\Contract\Aliases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class AliasesTest.
 *
 * Unit test for command alias normalisation.
 */
#[CoversClass(Aliases::class)]
final class AliasesTest extends TestCase {

  /**
   * The reference broker alias from the config PRD.
   */
  protected const array BROKER_ALIAS = ['broker' => '(?:php\s+)?(?:\S*/)?bin/broker\b'];

  #[DataProvider('dataProviderAllInvocationFormsCollapseToCanonical')]
  public function testAllInvocationFormsCollapseToCanonical(string $command): void {
    $this->assertSame('broker workflow start', Aliases::normalise($command, self::BROKER_ALIAS));
  }

  public static function dataProviderAllInvocationFormsCollapseToCanonical(): \Iterator {
    yield 'php prefix' => ['php bin/broker workflow start'];
    yield 'relative path' => ['./bin/broker workflow start'];
    yield 'nested path' => ['tools/bin/broker workflow start'];
    yield 'bare canonical' => ['broker workflow start'];
  }

  #[DataProvider('dataProviderNormalise')]
  public function testNormalise(string $command, array $aliases, string $expected): void {
    $this->assertSame($expected, Aliases::normalise($command, $aliases));
  }

  public static function dataProviderNormalise(): \Iterator {
    yield 'no aliases is a passthrough' => ['git status', [], 'git status'];
    yield 'unrelated command untouched' => ['git push', self::BROKER_ALIAS, 'git push'];
    yield 'multiple aliases both applied' => [
      'php bin/broker x && node tools/cli.js y',
      ['broker' => '(?:php\s+)?(?:\S*/)?bin/broker\b', 'cli' => '(?:node\s+)?\S*/cli\.js\b'],
      'broker x && cli y',
    ];
    yield 'repeated occurrence all replaced' => [
      'bin/broker a; bin/broker b',
      ['broker' => 'bin/broker\b'],
      'broker a; broker b',
    ];
  }

  public function testNormaliseAllReindexesAndNormalisesEach(): void {
    $commands = ['php bin/broker workflow start', 'git status'];

    $this->assertSame(['broker workflow start', 'git status'], Aliases::normaliseAll($commands, self::BROKER_ALIAS));
  }

  public function testNormaliseAllOnEmptyListIsEmpty(): void {
    $this->assertSame([], Aliases::normaliseAll([], self::BROKER_ALIAS));
  }

}
