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
   * The reference harness alias from the config PRD.
   */
  protected const array HARNESS_ALIAS = ['harness' => '(?:php\s+)?(?:\S*/)?bin/harness\b'];

  #[DataProvider('dataProviderAllInvocationFormsCollapseToCanonical')]
  public function testAllInvocationFormsCollapseToCanonical(string $command): void {
    $this->assertSame('harness workflow start', Aliases::normalise($command, self::HARNESS_ALIAS));
  }

  public static function dataProviderAllInvocationFormsCollapseToCanonical(): \Iterator {
    yield 'php prefix' => ['php bin/harness workflow start'];
    yield 'relative path' => ['./bin/harness workflow start'];
    yield 'nested path' => ['tools/bin/harness workflow start'];
    yield 'bare canonical' => ['harness workflow start'];
  }

  #[DataProvider('dataProviderNormalise')]
  public function testNormalise(string $command, array $aliases, string $expected): void {
    $this->assertSame($expected, Aliases::normalise($command, $aliases));
  }

  public static function dataProviderNormalise(): \Iterator {
    yield 'no aliases is a passthrough' => ['git status', [], 'git status'];
    yield 'unrelated command untouched' => ['git push', self::HARNESS_ALIAS, 'git push'];
    yield 'multiple aliases both applied' => [
      'php bin/harness x && node tools/cli.js y',
      ['harness' => '(?:php\s+)?(?:\S*/)?bin/harness\b', 'cli' => '(?:node\s+)?\S*/cli\.js\b'],
      'harness x && cli y',
    ];
    yield 'repeated occurrence all replaced' => [
      'bin/harness a; bin/harness b',
      ['harness' => 'bin/harness\b'],
      'harness a; harness b',
    ];
  }

  public function testNormaliseAllReindexesAndNormalisesEach(): void {
    $commands = ['php bin/harness workflow start', 'git status'];

    $this->assertSame(['harness workflow start', 'git status'], Aliases::normaliseAll($commands, self::HARNESS_ALIAS));
  }

  public function testNormaliseAllOnEmptyListIsEmpty(): void {
    $this->assertSame([], Aliases::normaliseAll([], self::HARNESS_ALIAS));
  }

}
