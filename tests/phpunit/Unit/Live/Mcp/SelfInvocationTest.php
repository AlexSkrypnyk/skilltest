<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live\Mcp;

use AlexSkrypnyk\SkillTest\Live\Mcp\SelfInvocation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class SelfInvocationTest.
 *
 * Unit test for resolving the command that re-launches skilltest as a mock.
 */
#[CoversClass(SelfInvocation::class)]
final class SelfInvocationTest extends TestCase {

  #[DataProvider('dataProviderCommand')]
  public function testCommand(string $php, ?string $phar, string $script, array $expected): void {
    $this->assertSame($expected, SelfInvocation::command($php, $phar, $script));
  }

  /**
   * Data provider for command resolution.
   *
   * @return \Iterator<string, array{string, (string | null), string, array{string, string}}>
   *   The cases.
   */
  public static function dataProviderCommand(): \Iterator {
    yield 'composer checkout uses the entry script' => ['/usr/bin/php', NULL, '/repo/skilltest', ['/usr/bin/php', '/repo/skilltest']];
    yield 'packaged run uses the phar' => ['/usr/bin/php', '/opt/skilltest.phar', '/repo/skilltest', ['/usr/bin/php', '/opt/skilltest.phar']];
    yield 'empty phar falls back to the script' => ['/usr/bin/php', '', '/repo/skilltest', ['/usr/bin/php', '/repo/skilltest']];
  }

}
