<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\SkillTest\Live\Mcp\McpMockServer;
use AlexSkrypnyk\SkillTest\Tests\Traits\ArrayPathTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class McpMockServerFunctionalTest.
 *
 * Drives the stdio MCP server over in-memory streams through a full JSON-RPC
 * session - handshake, tool listing, the three matcher kinds, unmatched and
 * unknown calls, and protocol errors - and asserts the call log it writes,
 * without a child process or a socket.
 */
#[CoversClass(McpMockServer::class)]
#[Group('live')]
final class McpMockServerFunctionalTest extends TestCase {

  use ArrayPathTrait;

  /**
   * A temporary directory the mock call log is written under.
   */
  protected string $dir = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->dir = dirname(__DIR__, 3) . '/.artifacts/tmp/mcpserver-' . getmypid() . '-' . uniqid();
    mkdir($this->dir, 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->dir !== '' && is_dir($this->dir)) {
      $this->remove($this->dir);
    }

    parent::tearDown();
  }

  public function testInitializeEchoesTheClientProtocolAndAdvertisesTools(): void {
    $responses = $this->drive($this->server(), [$this->rpc(1, 'initialize', ['protocolVersion' => '2099-01-01'])]);

    $this->assertSame('2099-01-01', $this->path($responses[0], 'result', 'protocolVersion'));
    $this->assertArrayHasKey('tools', $this->pathArray($responses[0], 'result', 'capabilities'));
    $this->assertSame('github', $this->path($responses[0], 'result', 'serverInfo', 'name'));
  }

  public function testInitializeDefaultsTheProtocolWhenTheClientOmitsIt(): void {
    $responses = $this->drive($this->server(), [$this->rpc(1, 'initialize')]);

    $this->assertSame(McpMockServer::PROTOCOL_VERSION, $this->path($responses[0], 'result', 'protocolVersion'));
  }

  public function testInitializedNotificationIsNotAnswered(): void {
    $responses = $this->drive($this->server(), ['{"jsonrpc":"2.0","method":"notifications/initialized"}']);

    $this->assertSame([], $responses);
  }

  public function testToolsListAdvertisesEveryTool(): void {
    $responses = $this->drive($this->server(), [$this->rpc(2, 'tools/list')]);

    $this->assertSame('create_issue', $this->path($responses[0], 'result', 'tools', 0, 'name'));
    $this->assertSame('Create an issue', $this->path($responses[0], 'result', 'tools', 0, 'description'));
    $this->assertSame(['type' => 'object'], $this->path($responses[0], 'result', 'tools', 0, 'inputSchema'));
  }

  public function testExactMatchReturnsTheFixtureText(): void {
    $responses = $this->drive($this->server(), [$this->call(3, 'create_issue', ['title' => 'Bug'])]);

    $this->assertSame('exact-hit', $this->path($responses[0], 'result', 'content', 0, 'text'));
    $this->assertFalse($this->path($responses[0], 'result', 'isError'));
  }

  public function testRegexMatchReturnsTheFixtureText(): void {
    $responses = $this->drive($this->server(), [$this->call(3, 'create_issue', ['title' => 'Feature: X'])]);

    $this->assertSame('regex-hit', $this->path($responses[0], 'result', 'content', 0, 'text'));
  }

  public function testSchemaMatchReturnsTheFixtureText(): void {
    $responses = $this->drive($this->server(), [$this->call(3, 'create_issue', ['urgent' => TRUE])]);

    $this->assertSame('schema-hit', $this->path($responses[0], 'result', 'content', 0, 'text'));
  }

  public function testUnmatchedCallErrorsNamingTheClosestFixture(): void {
    $responses = $this->drive($this->server(), [$this->call(4, 'create_issue', ['foo' => 'bar'])]);

    $this->assertTrue($this->path($responses[0], 'result', 'isError'));
    $this->assertStringContainsString("no fixture matched tool 'create_issue'", $this->pathString($responses[0], 'result', 'content', 0, 'text'));
    $this->assertStringContainsString('closest: match {title}', $this->pathString($responses[0], 'result', 'content', 0, 'text'));
  }

  public function testUnknownToolErrorsListingTheMockedTools(): void {
    $responses = $this->drive($this->server(), [$this->call(4, 'delete_repo', [])]);

    $this->assertTrue($this->path($responses[0], 'result', 'isError'));
    $this->assertStringContainsString("unknown tool 'delete_repo'", $this->pathString($responses[0], 'result', 'content', 0, 'text'));
    $this->assertStringContainsString('create_issue', $this->pathString($responses[0], 'result', 'content', 0, 'text'));
  }

  public function testPingReturnsAnEmptyResult(): void {
    $responses = $this->drive($this->server(), [$this->rpc(5, 'ping')]);

    $this->assertSame([], $this->path($responses[0], 'result'));
  }

  public function testUnknownMethodReturnsMethodNotFound(): void {
    $responses = $this->drive($this->server(), [$this->rpc(6, 'resources/list')]);

    $this->assertSame(McpMockServer::METHOD_NOT_FOUND, $this->path($responses[0], 'error', 'code'));
  }

  public function testUnknownRequestWithoutIdIsNotAnswered(): void {
    $responses = $this->drive($this->server(), ['{"jsonrpc":"2.0","method":"resources/list"}']);

    $this->assertSame([], $responses);
  }

  public function testInvalidJsonIsParseError(): void {
    $responses = $this->drive($this->server(), ['{not json']);

    $this->assertSame(McpMockServer::PARSE_ERROR, $this->path($responses[0], 'error', 'code'));
    $this->assertNull($this->path($responses[0], 'id'));
  }

  public function testNonObjectMessageIsAnInvalidRequest(): void {
    $responses = $this->drive($this->server(), ['123']);

    $this->assertSame(McpMockServer::INVALID_REQUEST, $this->path($responses[0], 'error', 'code'));
    $this->assertNull($this->path($responses[0], 'id'));
  }

  public function testBlankLinesAreSkipped(): void {
    $responses = $this->drive($this->server(), ['', $this->rpc(7, 'ping')]);

    $this->assertCount(1, $responses);
    $this->assertSame(7, $this->path($responses[0], 'id'));
  }

  public function testClosestFixtureIsTheHigherScoringOne(): void {
    $server = ['server' => 'github', 'log' => '', 'tools' => [
      ['name' => 'edit', 'description' => '', 'responses' => [
        ['kind' => 'exact', 'matcher' => ['a' => 1], 'text' => 'r0', 'label' => 'match {a}'],
        ['kind' => 'exact', 'matcher' => ['b' => 2, 'c' => 3], 'text' => 'r1', 'label' => 'match {b, c}'],
      ]],
    ]];

    $responses = $this->drive($server, [$this->call(1, 'edit', ['b' => 2, 'c' => 3, 'd' => 9])]);

    $this->assertTrue($this->path($responses[0], 'result', 'isError'));
    $this->assertStringContainsString('closest: match {b, c}', $this->pathString($responses[0], 'result', 'content', 0, 'text'));
  }

  public function testCallLogRecordsMatchedUnmatchedAndUnknownCalls(): void {
    // A log path whose parent does not yet exist, so the server creates it.
    $log = $this->dir . '/logs/github.log.jsonl';

    $this->drive($this->server($log), [
      $this->call(1, 'create_issue', ['title' => 'Bug']),
      $this->call(2, 'create_issue', ['foo' => 'bar']),
      $this->call(3, 'delete_repo', []),
    ]);

    $lines = array_values(array_filter(array_map($this->decodeLine(...), explode("\n", trim((string) file_get_contents($log))))));

    $this->assertCount(3, $lines);
    $this->assertTrue($this->path($lines[0], 'matched'));
    $this->assertSame('match {title}', $this->path($lines[0], 'fixture'));
    $this->assertFalse($this->path($lines[1], 'matched'));
    $this->assertStringContainsString('no fixture matched', $this->pathString($lines[1], 'error'));
    $this->assertFalse($this->path($lines[2], 'matched'));
    $this->assertStringContainsString("unknown tool 'delete_repo'", $this->pathString($lines[2], 'error'));
  }

  /**
   * Runs a session of request lines through a fresh server and decodes replies.
   *
   * @param array{server: string, log: string, tools: array<int, array<mixed>>} $server
   *   The server definition.
   * @param string[] $requests
   *   The raw JSON-RPC message lines to feed in.
   *
   * @return array<int, array<mixed>>
   *   The decoded responses, in write order.
   */
  protected function drive(array $server, array $requests): array {
    $in = $this->memoryStream(implode("\n", $requests) . "\n");
    $out = $this->memoryStream();

    (new McpMockServer($server, $in, $out))->serve();

    rewind($out);
    $raw = (string) stream_get_contents($out);
    fclose($in);
    fclose($out);
    $responses = [];

    foreach (explode("\n", $raw) as $line) {
      if ($line !== '') {
        $responses[] = $this->decodeLine($line);
      }
    }

    return $responses;
  }

  /**
   * Opens an in-memory stream, optionally primed with content and rewound.
   *
   * @param string $contents
   *   The content to write, then rewind to the start.
   *
   * @return resource
   *   The stream.
   */
  protected function memoryStream(string $contents = '') {
    $stream = fopen('php://memory', 'r+');

    if ($stream === FALSE) {
      // @codeCoverageIgnoreStart
      $this->fail('Could not open an in-memory stream.');
      // @codeCoverageIgnoreEnd
    }

    if ($contents !== '') {
      fwrite($stream, $contents);
      rewind($stream);
    }

    return $stream;
  }

  /**
   * Decodes one JSON line into an array, failing when it is not one.
   *
   * @param string $line
   *   The JSON line.
   *
   * @return array<mixed>
   *   The decoded array.
   */
  protected function decodeLine(string $line): array {
    $decoded = json_decode($line, TRUE);

    if (!is_array($decoded)) {
      // @codeCoverageIgnoreStart
      $this->fail(sprintf('Expected a JSON object, got: %s', $line));
      // @codeCoverageIgnoreEnd
    }

    return $decoded;
  }

  /**
   * A server definition mocking one tool with all three matcher kinds.
   *
   * @param string $log
   *   The call log path, empty to disable logging.
   *
   * @return array{server: string, log: string, tools: array<int, array<mixed>>}
   *   The server definition.
   */
  protected function server(string $log = ''): array {
    return ['server' => 'github', 'log' => $log, 'tools' => [
      ['name' => 'create_issue', 'description' => 'Create an issue', 'responses' => [
        ['kind' => 'exact', 'matcher' => ['title' => 'Bug'], 'text' => 'exact-hit', 'label' => 'match {title}'],
        ['kind' => 'regex', 'matcher' => ['title' => '^Feature'], 'text' => 'regex-hit', 'label' => 'match-regex {title}'],
        ['kind' => 'schema', 'matcher' => ['type' => 'object', 'required' => ['urgent']], 'text' => 'schema-hit', 'label' => 'match-schema'],
      ]],
    ]];
  }

  /**
   * Builds a JSON-RPC request line.
   *
   * @param int $id
   *   The request id.
   * @param string $method
   *   The method.
   * @param array<mixed> $params
   *   The params.
   *
   * @return string
   *   The encoded request.
   */
  protected function rpc(int $id, string $method, array $params = []): string {
    return (string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], JSON_UNESCAPED_SLASHES);
  }

  /**
   * Builds a `tools/call` request line.
   *
   * @param int $id
   *   The request id.
   * @param string $name
   *   The tool name.
   * @param array<mixed> $arguments
   *   The call arguments.
   *
   * @return string
   *   The encoded request.
   */
  protected function call(int $id, string $name, array $arguments): string {
    return $this->rpc($id, 'tools/call', ['name' => $name, 'arguments' => $arguments]);
  }

  /**
   * Recursively removes a directory tree.
   *
   * @param string $dir
   *   The directory to remove.
   */
  protected function remove(string $dir): void {
    foreach (scandir($dir) ?: [] as $item) {
      if ($item === '.') {
        continue;
      }
      if ($item === '..') {
        continue;
      }
      $path = $dir . '/' . $item;

      if (is_dir($path) && !is_link($path)) {
        $this->remove($path);

        continue;
      }

      unlink($path);
    }

    rmdir($dir);
  }

}
