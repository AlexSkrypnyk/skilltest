<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live\Mcp;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;

/**
 * Parses and normalises a task's `mcp-mocks:` block into server definitions.
 *
 * A task declares the MCP servers a skill would otherwise call for real; this
 * turns that declaration into the self-contained structure the stdio server
 * process is launched with - one entry per server, its tools, and each tool's
 * responses with the matcher normalised to a kind and the response body already
 * resolved to text (inline scalars as-is, structures as JSON, `response-file`
 * read from the skill directory). Every structural problem is a
 * {@see ConfigException} raised here, at parse time, so a malformed mock fails
 * the run with a pointer rather than a mock silently doing nothing - exactly
 * how `inputs:` and `repos:` are validated. The result is IO-free: writing
 * the server configs and the runtime wiring belongs to the workspace.
 */
final readonly class McpMock {

  /**
   * The config pointer every mock parse error is reported under.
   */
  public const string POINTER = 'llm.tasks.mcp-mocks';

  /**
   * The matcher keys, mapped to their normalised kind.
   */
  protected const array MATCHERS = [
    'match' => McpMatcher::EXACT,
    'match-regex' => McpMatcher::REGEX,
    'match-schema' => McpMatcher::SCHEMA,
  ];

  /**
   * Constructs an McpMock.
   *
   * @param array<int, array{server: string, log: string, tools: array<int, array{name: string, description: ?string, responses: array<int, array{kind: string, matcher: mixed, text: string, label: string}>}>}> $servers
   *   The normalised server definitions.
   */
  public function __construct(
    protected array $servers,
  ) {}

  /**
   * Parses a task's `mcp-mocks:` block into normalised server definitions.
   *
   * @param array<mixed> $task
   *   The raw task declaration.
   * @param string $config_file
   *   The declaring `eval.yaml`, for error context.
   * @param string $skill_dir
   *   The skill directory `response-file` paths resolve against.
   *
   * @return self
   *   The parsed mock, empty when the task declares no mocks.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When a mock, tool, or response is malformed, or a response file is
   *   missing.
   */
  public static function fromTask(array $task, string $config_file, string $skill_dir): self {
    $servers = [];

    foreach (Data::toArrayList(Data::get($task, 'mcp-mocks')) as $entry) {
      $name = Data::toStringOrNull(Data::get($entry, 'server'));

      if ($name === NULL || $name === '') {
        throw new ConfigException("an mcp-mocks entry requires a 'server'.", $config_file, self::POINTER);
      }

      $tools = self::parseTools($entry, $name, $config_file, $skill_dir);

      $servers[] = ['server' => $name, 'log' => '', 'tools' => $tools];
    }

    return new self($servers);
  }

  /**
   * Whether the task declared no mocks at all.
   *
   * @return bool
   *   TRUE when there is nothing to launch.
   */
  public function isEmpty(): bool {
    return $this->servers === [];
  }

  /**
   * The normalised server definitions.
   *
   * @return array<int, array{server: string, log: string, tools: array<int, array<string, mixed>>}>
   *   The server definitions.
   */
  public function servers(): array {
    return $this->servers;
  }

  /**
   * The fully-qualified tool names every mock advertises.
   *
   * @return string[]
   *   The `mcp__<server>__<tool>` identifiers, so the agent can be permitted to
   *   call exactly the mocked tools.
   */
  public function toolNames(): array {
    $names = [];

    foreach ($this->servers as $server) {
      foreach ($server['tools'] as $tool) {
        $names[] = sprintf('mcp__%s__%s', $server['server'], $tool['name']);
      }
    }

    return $names;
  }

  /**
   * Parses one server's `tools:` list into normalised tool definitions.
   *
   * @param array<mixed> $entry
   *   The raw server entry.
   * @param string $server
   *   The server name, for error context.
   * @param string $config_file
   *   The declaring `eval.yaml`.
   * @param string $skill_dir
   *   The skill directory `response-file` paths resolve against.
   *
   * @return array<int, array{name: string, description: ?string, responses: array<int, array{kind: string, matcher: mixed, text: string, label: string}>}>
   *   The normalised tools.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the server declares no tools or a tool is malformed.
   */
  protected static function parseTools(array $entry, string $server, string $config_file, string $skill_dir): array {
    $tools = [];

    foreach (Data::toArrayList(Data::get($entry, 'tools')) as $raw) {
      $name = Data::toStringOrNull(Data::get($raw, 'name'));

      if ($name === NULL || $name === '') {
        throw new ConfigException(sprintf("mcp-mocks server '%s' has a tool without a 'name'.", $server), $config_file, self::POINTER);
      }

      $responses = self::parseResponses($raw, $server, $name, $config_file, $skill_dir);

      $tools[] = [
        'name' => $name,
        'description' => Data::toStringOrNull(Data::get($raw, 'description')),
        'responses' => $responses,
      ];
    }

    if ($tools === []) {
      throw new ConfigException(sprintf("mcp-mocks server '%s' requires at least one tool.", $server), $config_file, self::POINTER);
    }

    return $tools;
  }

  /**
   * Parses one tool's `responses:` list into normalised responses.
   *
   * @param array<mixed> $raw
   *   The raw tool declaration.
   * @param string $server
   *   The server name, for error context.
   * @param string $tool
   *   The tool name, for error context.
   * @param string $config_file
   *   The declaring `eval.yaml`.
   * @param string $skill_dir
   *   The skill directory `response-file` paths resolve against.
   *
   * @return array<int, array{kind: string, matcher: mixed, text: string, label: string}>
   *   The normalised responses.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the tool declares no responses or a response is malformed.
   */
  protected static function parseResponses(array $raw, string $server, string $tool, string $config_file, string $skill_dir): array {
    $responses = [];

    foreach (Data::toArrayList(Data::get($raw, 'responses')) as $response) {
      [$kind, $matcher, $label] = self::parseMatcher($response, $server, $tool, $config_file);
      $text = self::resolveText($response, $server, $tool, $config_file, $skill_dir);

      $responses[] = ['kind' => $kind, 'matcher' => $matcher, 'text' => $text, 'label' => $label];
    }

    if ($responses === []) {
      throw new ConfigException(sprintf("mcp-mocks tool '%s/%s' requires at least one response.", $server, $tool), $config_file, self::POINTER);
    }

    return $responses;
  }

  /**
   * Extracts the single matcher from a response and normalises it.
   *
   * @param array<mixed> $response
   *   The raw response declaration.
   * @param string $server
   *   The server name, for error context.
   * @param string $tool
   *   The tool name, for error context.
   * @param string $config_file
   *   The declaring `eval.yaml`.
   *
   * @return array{0: string, 1: mixed, 2: string}
   *   The matcher kind, the matcher, and a human label.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When zero or more than one matcher key is present, or a matcher is not a
   *   mapping.
   */
  protected static function parseMatcher(array $response, string $server, string $tool, string $config_file): array {
    $present = array_values(array_filter(array_keys(self::MATCHERS), static fn(string $key): bool => array_key_exists($key, $response)));

    if ($present === []) {
      throw new ConfigException(sprintf("mcp-mocks response for '%s/%s' requires one of 'match', 'match-regex', or 'match-schema'.", $server, $tool), $config_file, self::POINTER);
    }

    if (count($present) > 1) {
      throw new ConfigException(sprintf("mcp-mocks response for '%s/%s' declares more than one matcher (%s); use exactly one.", $server, $tool, implode(', ', $present)), $config_file, self::POINTER);
    }

    $key = $present[0];
    $matcher = $response[$key];

    if (!is_array($matcher)) {
      throw new ConfigException(sprintf("mcp-mocks matcher '%s' for '%s/%s' must be a mapping.", $key, $server, $tool), $config_file, self::POINTER);
    }

    return [self::MATCHERS[$key], $matcher, self::label($key, $matcher)];
  }

  /**
   * Resolves a response's body to text: inline value or a file's contents.
   *
   * @param array<mixed> $response
   *   The raw response declaration.
   * @param string $server
   *   The server name, for error context.
   * @param string $tool
   *   The tool name, for error context.
   * @param string $config_file
   *   The declaring `eval.yaml`.
   * @param string $skill_dir
   *   The skill directory a relative `response-file` resolves against.
   *
   * @return string
   *   The response text.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When neither or both sources are present, or a response file is missing.
   */
  protected static function resolveText(array $response, string $server, string $tool, string $config_file, string $skill_dir): string {
    $has_inline = array_key_exists('response', $response);
    $file = Data::toStringOrNull(Data::get($response, 'response-file'));

    if ($has_inline && $file !== NULL) {
      throw new ConfigException(sprintf("mcp-mocks response for '%s/%s' sets both 'response' and 'response-file'; use one.", $server, $tool), $config_file, self::POINTER);
    }

    if ($has_inline) {
      return self::asText($response['response']);
    }

    if ($file === NULL || $file === '') {
      throw new ConfigException(sprintf("mcp-mocks response for '%s/%s' requires 'response' or 'response-file'.", $server, $tool), $config_file, self::POINTER);
    }

    $path = str_starts_with($file, '/') ? $file : rtrim($skill_dir, '/') . '/' . $file;

    if (!is_file($path)) {
      throw new ConfigException(sprintf("mcp-mocks response file '%s' for '%s/%s' was not found.", $file, $server, $tool), $config_file, self::POINTER);
    }

    return (string) file_get_contents($path);
  }

  /**
   * Renders an inline response value as text: a string verbatim, else JSON.
   *
   * @param mixed $value
   *   The inline response value.
   *
   * @return string
   *   The response text.
   */
  protected static function asText(mixed $value): string {
    if (is_string($value)) {
      return $value;
    }

    return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
  }

  /**
   * Builds a short human label naming a matcher for logs and errors.
   *
   * @param string $key
   *   The matcher key as authored.
   * @param array<mixed> $matcher
   *   The matcher mapping.
   *
   * @return string
   *   A label such as `match {title, repo}` or `match-schema`.
   */
  protected static function label(string $key, array $matcher): string {
    if ($key === 'match-schema') {
      return $key;
    }

    return sprintf('%s {%s}', $key, implode(', ', array_map(strval(...), array_keys($matcher))));
  }

}
