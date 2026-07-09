<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live\Mcp;

use AlexSkrypnyk\SkillTest\Live\Mcp\McpMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class McpMatcherTest.
 *
 * Unit test for the three tool-argument matcher kinds and the closeness score.
 */
#[CoversClass(McpMatcher::class)]
final class McpMatcherTest extends TestCase {

  #[DataProvider('dataProviderExact')]
  public function testExact(array $matcher, array $arguments, bool $expected): void {
    $this->assertSame($expected, McpMatcher::exact($matcher, $arguments));
  }

  /**
   * Data provider for exact matching.
   *
   * @return \Iterator<string, array{array<mixed>, array<mixed>, bool}>
   *   The cases.
   */
  public static function dataProviderExact(): \Iterator {
    yield 'equal, same order' => [['title' => 'Bug', 'repo' => 'a/b'], ['title' => 'Bug', 'repo' => 'a/b'], TRUE];
    yield 'equal, key order ignored' => [['title' => 'Bug', 'repo' => 'a/b'], ['repo' => 'a/b', 'title' => 'Bug'], TRUE];
    yield 'extra argument field fails' => [['title' => 'Bug'], ['title' => 'Bug', 'repo' => 'a/b'], FALSE];
    yield 'missing argument field fails' => [['title' => 'Bug', 'repo' => 'a/b'], ['title' => 'Bug'], FALSE];
    yield 'different value fails' => [['title' => 'Bug'], ['title' => 'Feature'], FALSE];
    yield 'type-strict: string is not the number' => [['n' => 1], ['n' => '1'], FALSE];
    yield 'nested equal ignoring order' => [['a' => ['x' => 1, 'y' => 2]], ['a' => ['y' => 2, 'x' => 1]], TRUE];
    yield 'both empty' => [[], [], TRUE];
  }

  #[DataProvider('dataProviderRegex')]
  public function testRegex(array $matcher, array $arguments, bool $expected): void {
    $this->assertSame($expected, McpMatcher::regex($matcher, $arguments));
  }

  /**
   * Data provider for per-field regex matching.
   *
   * @return \Iterator<string, array{array<mixed>, array<mixed>, bool}>
   *   The cases.
   */
  public static function dataProviderRegex(): \Iterator {
    yield 'single field matches' => [['title' => '^Bug'], ['title' => 'Bug report'], TRUE];
    yield 'single field does not match' => [['title' => '^Bug'], ['title' => 'A Bug'], FALSE];
    yield 'unmentioned fields are ignored' => [['title' => 'Bug'], ['title' => 'Bug', 'extra' => 'x'], TRUE];
    yield 'all named fields must match' => [['a' => 'x', 'b' => 'y'], ['a' => 'xx', 'b' => 'zz'], FALSE];
    yield 'missing field never matches' => [['title' => '.*'], ['other' => 'v'], FALSE];
    yield 'non-string value is stringified' => [['n' => '^4'], ['n' => 42], TRUE];
    yield 'structured value is matched as json' => [['tags' => 'bug'], ['tags' => ['bug', 'p1']], TRUE];
    yield 'empty matcher matches anything' => [[], ['anything' => 1], TRUE];
  }

  #[DataProvider('dataProviderSchema')]
  public function testSchema(array $schema, array $arguments, bool $expected): void {
    $this->assertSame($expected, McpMatcher::schema($schema, $arguments));
  }

  /**
   * Data provider for JSON Schema matching.
   *
   * @return \Iterator<string, array{array<mixed>, array<mixed>, bool}>
   *   The cases.
   */
  public static function dataProviderSchema(): \Iterator {
    $schema = ['type' => 'object', 'required' => ['title'], 'properties' => ['title' => ['type' => 'string']]];

    yield 'valid against schema' => [$schema, ['title' => 'Bug'], TRUE];
    yield 'missing required property' => [$schema, ['body' => 'x'], FALSE];
    yield 'wrong property type' => [$schema, ['title' => 42], FALSE];
    yield 'empty schema matches anything' => [[], ['whatever' => TRUE], TRUE];
  }

  #[DataProvider('dataProviderMatchesDispatchesByKind')]
  public function testMatchesDispatchesByKind(array $response, array $arguments, bool $expected): void {
    $this->assertSame($expected, McpMatcher::matches($response, $arguments));
  }

  /**
   * Data provider for the kind-dispatching entry point.
   *
   * @return \Iterator<string, array{array<mixed>, array<mixed>, bool}>
   *   The cases.
   */
  public static function dataProviderMatchesDispatchesByKind(): \Iterator {
    yield 'exact hit' => [self::response(McpMatcher::EXACT, ['a' => 1]), ['a' => 1], TRUE];
    yield 'exact miss' => [self::response(McpMatcher::EXACT, ['a' => 1]), ['a' => 2], FALSE];
    yield 'regex hit' => [self::response(McpMatcher::REGEX, ['a' => '^x']), ['a' => 'xy'], TRUE];
    yield 'schema hit' => [self::response(McpMatcher::SCHEMA, ['type' => 'object']), ['a' => 1], TRUE];
    yield 'non-array matcher falls back to empty' => [self::response(McpMatcher::EXACT, 'oops'), [], TRUE];
  }

  #[DataProvider('dataProviderScore')]
  public function testScore(array $response, array $arguments, int $expected): void {
    $this->assertSame($expected, McpMatcher::score($response, $arguments));
  }

  /**
   * Data provider for closeness scoring.
   *
   * @return \Iterator<string, array{array<mixed>, array<mixed>, int}>
   *   The cases.
   */
  public static function dataProviderScore(): \Iterator {
    yield 'exact counts agreeing fields' => [self::response(McpMatcher::EXACT, ['a' => 1, 'b' => 2]), ['a' => 1, 'b' => 9], 1];
    yield 'exact ignores absent fields' => [self::response(McpMatcher::EXACT, ['a' => 1, 'z' => 2]), ['a' => 1], 1];
    yield 'regex counts matching patterns' => [self::response(McpMatcher::REGEX, ['a' => '^x', 'b' => '^y']), ['a' => 'xx', 'b' => 'zz'], 1];
    yield 'schema counts declared names present' => [self::response(McpMatcher::SCHEMA, ['required' => ['a'], 'properties' => ['b' => []]]), ['a' => 1, 'b' => 2], 2];
    yield 'schema with no declared names scores zero' => [self::response(McpMatcher::SCHEMA, ['type' => 'object']), ['a' => 1], 0];
  }

  /**
   * Builds a normalised response with the given matcher.
   *
   * @param string $kind
   *   The matcher kind.
   * @param mixed $matcher
   *   The matcher.
   *
   * @return array{kind: string, matcher: mixed, text: string, label: string}
   *   The normalised response.
   */
  protected static function response(string $kind, mixed $matcher): array {
    return ['kind' => $kind, 'matcher' => $matcher, 'text' => 'ok', 'label' => 'label'];
  }

}
