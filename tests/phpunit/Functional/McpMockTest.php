<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Live\Mcp\McpMock;
use AlexSkrypnyk\SkillTest\Tests\Traits\ArrayPathTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class McpMockTest.
 *
 * Functional test for parsing and normalising a task's `mcp-mocks:` block; it
 * reads real `response-file` fixtures from a temp skill directory, so it lives
 * with the file-system tests rather than the pure unit ones.
 */
#[CoversClass(McpMock::class)]
final class McpMockTest extends TestCase {

  use ArrayPathTrait;

  /**
   * A temporary skill directory response files resolve against.
   */
  protected string $dir = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->dir = dirname(__DIR__, 3) . '/.artifacts/tmp/mcpmock-' . getmypid() . '-' . uniqid();
    mkdir($this->dir . '/fixtures', 0777, TRUE);
    file_put_contents($this->dir . '/fixtures/resp.json', '{"resp":true}');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->dir !== '' && is_dir($this->dir)) {
      array_map(unlink(...), glob($this->dir . '/fixtures/*') ?: []);
      rmdir($this->dir . '/fixtures');
      rmdir($this->dir);
    }

    parent::tearDown();
  }

  public function testEmptyWhenNoMocksDeclared(): void {
    $mock = $this->parse(['name' => 'invoked']);

    $this->assertTrue($mock->isEmpty());
    $this->assertSame([], $mock->servers());
    $this->assertSame([], $mock->toolNames());
  }

  public function testParsesEveryMatcherAndResponseSource(): void {
    $task = ['mcp-mocks' => [
      ['server' => 'github', 'tools' => [
        ['name' => 'create_issue', 'description' => 'Create', 'responses' => [
          ['match' => ['title' => 'Bug', 'repo' => 'a/b'], 'response' => 'Created #1'],
          ['match-regex' => ['title' => '^F'], 'response-file' => 'fixtures/resp.json'],
          ['match-schema' => ['type' => 'object'], 'response' => ['ok' => TRUE]],
        ]],
      ]],
    ]];

    $mock = $this->parse($task);
    $servers = $mock->servers();

    $this->assertFalse($mock->isEmpty());
    $this->assertSame('github', $servers[0]['server']);
    $this->assertSame('', $servers[0]['log']);

    $this->assertSame('create_issue', $this->path($servers, 0, 'tools', 0, 'name'));
    $this->assertSame('Create', $this->path($servers, 0, 'tools', 0, 'description'));

    $this->assertSame('exact', $this->path($servers, 0, 'tools', 0, 'responses', 0, 'kind'));
    $this->assertSame(['title' => 'Bug', 'repo' => 'a/b'], $this->path($servers, 0, 'tools', 0, 'responses', 0, 'matcher'));
    $this->assertSame('Created #1', $this->path($servers, 0, 'tools', 0, 'responses', 0, 'text'));
    $this->assertSame('match {title, repo}', $this->path($servers, 0, 'tools', 0, 'responses', 0, 'label'));

    $this->assertSame('regex', $this->path($servers, 0, 'tools', 0, 'responses', 1, 'kind'));
    $this->assertSame('{"resp":true}', $this->path($servers, 0, 'tools', 0, 'responses', 1, 'text'));
    $this->assertSame('match-regex {title}', $this->path($servers, 0, 'tools', 0, 'responses', 1, 'label'));

    $this->assertSame('schema', $this->path($servers, 0, 'tools', 0, 'responses', 2, 'kind'));
    $this->assertSame('{"ok":true}', $this->path($servers, 0, 'tools', 0, 'responses', 2, 'text'));
    $this->assertSame('match-schema', $this->path($servers, 0, 'tools', 0, 'responses', 2, 'label'));

    $this->assertSame(['mcp__github__create_issue'], $mock->toolNames());
  }

  public function testToolNamesSpanEveryServerAndTool(): void {
    $task = ['mcp-mocks' => [
      ['server' => 'github', 'tools' => [
        ['name' => 'create_issue', 'responses' => [['match' => [], 'response' => 'a']]],
        ['name' => 'add_comment', 'responses' => [['match' => [], 'response' => 'b']]],
      ]],
      ['server' => 'slack', 'tools' => [
        ['name' => 'post', 'responses' => [['match' => [], 'response' => 'c']]],
      ]],
    ]];

    $this->assertSame(['mcp__github__create_issue', 'mcp__github__add_comment', 'mcp__slack__post'], $this->parse($task)->toolNames());
  }

  public function testInlineScalarResponseBecomesText(): void {
    $task = ['mcp-mocks' => [['server' => 'g', 'tools' => [['name' => 't', 'responses' => [['match' => [], 'response' => 42]]]]]]];

    $this->assertSame('42', $this->path($this->parse($task)->servers(), 0, 'tools', 0, 'responses', 0, 'text'));
  }

  public function testAbsoluteResponseFileIsResolved(): void {
    $task = ['mcp-mocks' => [['server' => 'g', 'tools' => [['name' => 't', 'responses' => [['match' => [], 'response-file' => $this->dir . '/fixtures/resp.json']]]]]]];

    $this->assertSame('{"resp":true}', $this->path($this->parse($task)->servers(), 0, 'tools', 0, 'responses', 0, 'text'));
  }

  public function testToolWithoutDescriptionNormalisesToNull(): void {
    $task = ['mcp-mocks' => [['server' => 'g', 'tools' => [['name' => 't', 'responses' => [['match' => [], 'response' => 'a']]]]]]];

    $this->assertNull($this->parse($task)->servers()[0]['tools'][0]['description']);
  }

  #[DataProvider('dataProviderMalformedMocksThrow')]
  public function testMalformedMocksThrow(array $task, string $message): void {
    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage($message);

    $this->parse($task);
  }

  /**
   * Data provider of malformed mocks and the message each raises.
   *
   * @return \Iterator<string, array{array<mixed>, string}>
   *   The cases.
   */
  public static function dataProviderMalformedMocksThrow(): \Iterator {
    yield 'server without a name' => [
      ['mcp-mocks' => [['tools' => [['name' => 't', 'responses' => [['match' => [], 'response' => 'a']]]]]]],
      "an mcp-mocks entry requires a 'server'.",
    ];
    yield 'server without tools' => [
      ['mcp-mocks' => [['server' => 'g', 'tools' => []]]],
      "mcp-mocks server 'g' requires at least one tool.",
    ];
    yield 'tool without a name' => [
      ['mcp-mocks' => [['server' => 'g', 'tools' => [['responses' => [['match' => [], 'response' => 'a']]]]]]],
      "mcp-mocks server 'g' has a tool without a 'name'.",
    ];
    yield 'tool without responses' => [
      ['mcp-mocks' => [['server' => 'g', 'tools' => [['name' => 't', 'responses' => []]]]]],
      "mcp-mocks tool 'g/t' requires at least one response.",
    ];
    yield 'response without a matcher' => [
      ['mcp-mocks' => [['server' => 'g', 'tools' => [['name' => 't', 'responses' => [['response' => 'a']]]]]]],
      "requires one of 'match', 'match-regex', or 'match-schema'.",
    ];
    yield 'response with two matchers' => [
      ['mcp-mocks' => [['server' => 'g', 'tools' => [['name' => 't', 'responses' => [['match' => [], 'match-regex' => [], 'response' => 'a']]]]]]],
      'declares more than one matcher',
    ];
    yield 'non-mapping matcher' => [
      ['mcp-mocks' => [['server' => 'g', 'tools' => [['name' => 't', 'responses' => [['match' => 'oops', 'response' => 'a']]]]]]],
      "matcher 'match' for 'g/t' must be a mapping.",
    ];
    yield 'response without a source' => [
      ['mcp-mocks' => [['server' => 'g', 'tools' => [['name' => 't', 'responses' => [['match' => []]]]]]]],
      "requires 'response' or 'response-file'.",
    ];
    yield 'response with both sources' => [
      ['mcp-mocks' => [['server' => 'g', 'tools' => [['name' => 't', 'responses' => [['match' => [], 'response' => 'a', 'response-file' => 'f']]]]]]],
      "sets both 'response' and 'response-file'",
    ];
    yield 'missing response file' => [
      ['mcp-mocks' => [['server' => 'g', 'tools' => [['name' => 't', 'responses' => [['match' => [], 'response-file' => 'fixtures/nope.json']]]]]]],
      "mcp-mocks response file 'fixtures/nope.json' for 'g/t' was not found.",
    ];
  }

  /**
   * Parses a task against the temp skill directory.
   *
   * @param array<mixed> $task
   *   The raw task declaration.
   *
   * @return \AlexSkrypnyk\SkillTest\Live\Mcp\McpMock
   *   The parsed mock.
   */
  protected function parse(array $task): McpMock {
    return McpMock::fromTask($task, $this->dir . '/eval.yaml', $this->dir);
  }

}
