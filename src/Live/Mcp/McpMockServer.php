<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live\Mcp;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Version;

/**
 * A local stdio MCP server that answers tool calls from declared fixtures.
 *
 * Launched once per trial as the child process the agent connects to, this
 * speaks just enough of the Model Context Protocol over newline-delimited
 * JSON-RPC to stand in for a real MCP server: it completes the initialize
 * handshake, advertises the mocked tools, and answers `tools/call` from the
 * first response whose matcher accepts the arguments. Fidelity to the product
 * promise lives in two rules - a matched call returns its fixture as text, and
 * an unmatched or unknown call returns an `isError` result naming the tool and
 * the closest declared fixture, never an empty success - so a skill that drifts
 * onto an unmocked path fails loudly instead of silently. Every `tools/call` is
 * appended to the trial's mock log so the run artifacts record exactly what the
 * skill asked for and which fixture answered. The input and output streams are
 * injected, so the whole protocol is exercised in a test without a child
 * process or a socket.
 */
final class McpMockServer {

  /**
   * The MCP protocol version answered when the client names none.
   */
  public const string PROTOCOL_VERSION = '2025-06-18';

  /**
   * The JSON-RPC parse-error code.
   */
  public const int PARSE_ERROR = -32700;

  /**
   * The JSON-RPC invalid-request code.
   */
  public const int INVALID_REQUEST = -32600;

  /**
   * The JSON-RPC method-not-found code.
   */
  public const int METHOD_NOT_FOUND = -32601;

  /**
   * Constructs an McpMockServer.
   *
   * @param array{server: string, log: string, tools: array<int, array<mixed>>} $server
   *   The server definition read back from the per-trial JSON: its name, its
   *   mock log path (empty to disable logging), and its loosely-typed tools.
   * @param resource $in
   *   The input stream messages are read from.
   * @param resource $out
   *   The output stream responses are written to.
   */
  public function __construct(
    protected array $server,
    /**
     * The input stream JSON-RPC messages are read from, one per line.
     */
    protected $in,
    /**
     * The output stream JSON-RPC responses are written to.
     */
    protected $out,
  ) {
  }

  /**
   * Reads and answers messages until the input stream closes.
   */
  public function serve(): void {
    while (($line = fgets($this->in)) !== FALSE) {
      $line = trim($line);

      if ($line === '') {
        continue;
      }

      $this->handle($line);
    }
  }

  /**
   * Parses one line and dispatches it, writing a response when one is due.
   *
   * @param string $line
   *   The raw JSON-RPC message line.
   */
  protected function handle(string $line): void {
    $message = json_decode($line, TRUE);

    if (json_last_error() !== JSON_ERROR_NONE) {
      $this->writeError(NULL, self::PARSE_ERROR, 'Parse error');

      return;
    }

    if (!is_array($message)) {
      $this->writeError(NULL, self::INVALID_REQUEST, 'Invalid Request');

      return;
    }

    $method = is_string($message['method'] ?? NULL) ? $message['method'] : '';

    // A message without an `id` is a notification: it is acted on but never
    // answered, so an unknown notification is silently ignored.
    if (str_starts_with($method, 'notifications/')) {
      return;
    }

    $response = $this->route($method, is_array($message['params'] ?? NULL) ? $message['params'] : []);

    if (array_key_exists('id', $message)) {
      $this->write(['jsonrpc' => '2.0', 'id' => $message['id']] + $response);
    }
  }

  /**
   * Routes a request method to its result or JSON-RPC error.
   *
   * @param string $method
   *   The JSON-RPC method.
   * @param array<mixed> $params
   *   The request params.
   *
   * @return array{result: mixed}|array{error: array{code: int, message: string}}
   *   The response body, less the envelope.
   */
  protected function route(string $method, array $params): array {
    return match ($method) {
      'initialize' => ['result' => $this->initialize($params)],
      'tools/list' => ['result' => ['tools' => $this->toolList()]],
      'tools/call' => ['result' => $this->call($params)],
      'ping' => ['result' => (object) []],
      default => ['error' => ['code' => self::METHOD_NOT_FOUND, 'message' => sprintf('Method not found: %s', $method)]],
    };
  }

  /**
   * Builds the initialize result, echoing the client's protocol version.
   *
   * @param array<mixed> $params
   *   The initialize params.
   *
   * @return array<string, mixed>
   *   The initialize result.
   */
  protected function initialize(array $params): array {
    $version = is_string($params['protocolVersion'] ?? NULL) && $params['protocolVersion'] !== '' ? $params['protocolVersion'] : self::PROTOCOL_VERSION;

    return [
      'protocolVersion' => $version,
      'capabilities' => ['tools' => (object) []],
      'serverInfo' => ['name' => $this->server['server'], 'version' => Version::id()],
    ];
  }

  /**
   * Advertises the mocked tools with a permissive input schema.
   *
   * @return array<int, array{name: string, description: string, inputSchema: array<string, string>}>
   *   The advertised tools.
   */
  protected function toolList(): array {
    return array_map(static fn(array $tool): array => [
      'name' => Data::toStringOrNull($tool['name'] ?? NULL) ?? '',
      'description' => Data::toStringOrNull($tool['description'] ?? NULL) ?? '',
      'inputSchema' => ['type' => 'object'],
    ], $this->server['tools']);
  }

  /**
   * Answers a `tools/call`, logging the call and matched or missing fixture.
   *
   * @param array<mixed> $params
   *   The call params: the tool `name` and its `arguments`.
   *
   * @return array{content: array<int, array{type: string, text: string}>, isError: bool}
   *   The MCP tool result: the fixture text on a match, or an `isError` result
   *   naming the closest fixture on a miss.
   */
  protected function call(array $params): array {
    $name = is_string($params['name'] ?? NULL) ? $params['name'] : '';
    $arguments = is_array($params['arguments'] ?? NULL) ? $params['arguments'] : [];
    $tool = $this->findTool($name);

    if ($tool === NULL) {
      $names = implode(', ', array_map(static fn(array $entry): string => Data::toStringOrNull($entry['name'] ?? NULL) ?? '', $this->server['tools']));
      $message = sprintf("skilltest mock: unknown tool '%s'; mocked tools: %s.", $name, $names);
      $this->log(['tool' => $name, 'arguments' => $arguments, 'matched' => FALSE, 'error' => $message]);

      return $this->errorResult($message);
    }

    $responses = Data::toArrayList($tool['responses'] ?? NULL);

    foreach ($responses as $response) {
      if (McpMatcher::matches($response, $arguments)) {
        $this->log(['tool' => $name, 'arguments' => $arguments, 'matched' => TRUE, 'fixture' => Data::toStringOrNull($response['label'] ?? NULL) ?? '']);

        return $this->textResult(Data::toStringOrNull($response['text'] ?? NULL) ?? '');
      }
    }

    $closest = $this->closest($responses, $arguments);
    $message = sprintf("skilltest mock: no fixture matched tool '%s'; closest: %s.", $name, Data::toStringOrNull($closest['label'] ?? NULL) ?? '');
    $this->log(['tool' => $name, 'arguments' => $arguments, 'matched' => FALSE, 'error' => $message]);

    return $this->errorResult($message);
  }

  /**
   * Finds a mocked tool by name.
   *
   * @param string $name
   *   The requested tool name.
   *
   * @return array<mixed>|null
   *   The tool, or NULL when it is not mocked.
   */
  protected function findTool(string $name): ?array {
    foreach ($this->server['tools'] as $tool) {
      if ((Data::toStringOrNull($tool['name'] ?? NULL) ?? '') === $name) {
        return $tool;
      }
    }

    return NULL;
  }

  /**
   * Picks the response that came closest to matching the arguments.
   *
   * @param array<int, array<mixed>> $responses
   *   The tool's responses.
   * @param array<mixed> $arguments
   *   The call arguments.
   *
   * @return array<mixed>
   *   The nearest response by score, the first declared on a tie.
   */
  protected function closest(array $responses, array $arguments): array {
    $best = $responses[0] ?? [];
    $best_score = McpMatcher::score($best, $arguments);

    foreach ($responses as $response) {
      $score = McpMatcher::score($response, $arguments);

      if ($score > $best_score) {
        $best = $response;
        $best_score = $score;
      }
    }

    return $best;
  }

  /**
   * Wraps fixture text as a successful MCP tool result.
   *
   * @param string $text
   *   The fixture text.
   *
   * @return array{content: array<int, array{type: string, text: string}>, isError: bool}
   *   The tool result.
   */
  protected function textResult(string $text): array {
    return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => FALSE];
  }

  /**
   * Wraps a message as a failing MCP tool result the model can see.
   *
   * @param string $message
   *   The error message.
   *
   * @return array{content: array<int, array{type: string, text: string}>, isError: bool}
   *   The failing tool result.
   */
  protected function errorResult(string $message): array {
    return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => TRUE];
  }

  /**
   * Appends one call record to the mock log, when logging is enabled.
   *
   * @param array<string, mixed> $entry
   *   The call record.
   */
  protected function log(array $entry): void {
    $path = $this->server['log'];

    if ($path === '') {
      return;
    }

    $dir = dirname($path);

    if (!is_dir($dir)) {
      mkdir($dir, 0777, TRUE);
    }

    file_put_contents($path, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
  }

  /**
   * Writes a JSON-RPC error envelope with the given id.
   *
   * @param mixed $id
   *   The request id, or NULL when it is unknown.
   * @param int $code
   *   The JSON-RPC error code.
   * @param string $message
   *   The error message.
   */
  protected function writeError(mixed $id, int $code, string $message): void {
    $this->write(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
  }

  /**
   * Encodes and writes one message as a single line, then flushes.
   *
   * @param array<string, mixed> $message
   *   The message to write.
   */
  protected function write(array $message): void {
    fwrite($this->out, json_encode($message, JSON_UNESCAPED_SLASHES) . "\n");
    fflush($this->out);
  }

}
