<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Config;

use AlexSkrypnyk\SkillTest\Config\Glob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class GlobTest.
 *
 * Unit test for the shared `*`/`?` selection matcher.
 */
#[CoversClass(Glob::class)]
final class GlobTest extends TestCase {

  #[DataProvider('dataProviderMatches')]
  public function testMatches(string $name, array $globs, bool $expected): void {
    $this->assertSame($expected, Glob::matches($name, $globs));
  }

  /**
   * Provides names, glob sets, and the expected match verdict.
   *
   * @return \Iterator<string, array{string, string[], bool}>
   *   The cases.
   */
  public static function dataProviderMatches(): \Iterator {
    yield 'empty set matches nothing' => ['invoked', [], FALSE];
    yield 'exact literal' => ['invoked', ['invoked'], TRUE];
    yield 'literal miss' => ['invoked', ['recorded'], FALSE];
    yield 'star prefix' => ['run-harness', ['run-*'], TRUE];
    yield 'star suffix' => ['harness-run', ['*-run'], TRUE];
    yield 'bare star matches all' => ['anything', ['*'], TRUE];
    yield 'question is single char' => ['ab', ['a?'], TRUE];
    yield 'question is not multi char' => ['abc', ['a?'], FALSE];
    yield 'any-of matches second' => ['recorded', ['invoked', 'rec*'], TRUE];
    yield 'dot is literal not wildcard' => ['axb', ['a.b'], FALSE];
    yield 'dot matches literal dot' => ['a.b', ['a.b'], TRUE];
  }

}
