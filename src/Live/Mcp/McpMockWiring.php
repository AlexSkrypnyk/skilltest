<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live\Mcp;

/**
 * Materialises a task's MCP mocks into a trial workspace and reads their logs.
 *
 * The environment owns where a trial's workspace lives and how its command
 * runs; mocks layer on top of that workspace without the environment needing to
 * know about them. Given an assembled workspace, this writes one self-contained
 * definition per server plus the `mcpServers` config the agent is pointed at,
 * both under the workspace so they are torn down with it, and returns the
 * config path to fold into the agent command. After the trial it reads each
 * server's call log back from the same workspace, so an unmatched call can fail
 * the trial and every call can be captured as an artifact. Every path is
 * absolute so the mock child process writes its log regardless of the working
 * directory the agent started it from.
 */
final class McpMockWiring {

  /**
   * The directory the per-server definitions and logs are written under.
   */
  public const string MOCKS_DIR = '.skilltest-mocks';

  /**
   * The MCP config file the agent is pointed at when a task declares mocks.
   */
  public const string MCP_CONFIG = '.skilltest-mcp.json';

  /**
   * Writes each mock's definition and the MCP config into a workspace.
   *
   * @param string $workspace
   *   The assembled workspace directory the configs are written under.
   * @param array<int, array{server: string, log: string, tools: array<int, array<mixed>>}> $servers
   *   The normalised mock server definitions.
   * @param array{0: string, 1: string} $command
   *   The `[php, entry]` command re-launching skilltest in `mcp-serve` mode.
   *
   * @return string|null
   *   The absolute MCP config path, or NULL when there are no mocks to launch.
   */
  public static function write(string $workspace, array $servers, array $command): ?string {
    if ($servers === []) {
      return NULL;
    }

    $mocks_dir = rtrim($workspace, '/') . '/' . self::MOCKS_DIR;

    if (!is_dir($mocks_dir)) {
      mkdir($mocks_dir, 0777, TRUE);
    }

    $config = [];

    foreach ($servers as $server) {
      $name = $server['server'];
      $slug = self::slug($name);
      $log = $mocks_dir . '/' . $slug . '.log.jsonl';
      $definition = $mocks_dir . '/' . $slug . '.json';

      file_put_contents($definition, self::encode(['server' => $name, 'log' => $log, 'tools' => $server['tools']]));
      $config[$name] = ['command' => $command[0], 'args' => [$command[1], 'mcp-serve', $definition]];
    }

    $config_path = rtrim($workspace, '/') . '/' . self::MCP_CONFIG;
    file_put_contents($config_path, self::encode(['mcpServers' => $config]));

    return $config_path;
  }

  /**
   * Reads each server's call log back from a workspace, by server name.
   *
   * A server whose tools were never called leaves no log and is absent, so an
   * empty result means the trial made no mocked calls at all.
   *
   * @param string $workspace
   *   The workspace the mocks were written into.
   * @param array<int, array{server: string, log: string, tools: array<int, array<mixed>>}> $servers
   *   The normalised mock server definitions.
   *
   * @return array<string, string>
   *   The raw JSONL log content, keyed by server name.
   */
  public static function logs(string $workspace, array $servers): array {
    $logs = [];

    foreach ($servers as $server) {
      $log = rtrim($workspace, '/') . '/' . self::MOCKS_DIR . '/' . self::slug($server['server']) . '.log.jsonl';

      if (is_file($log)) {
        $logs[$server['server']] = (string) file_get_contents($log);
      }
    }

    return $logs;
  }

  /**
   * Encodes a value as compact JSON for a written config file.
   *
   * @param array<string, mixed> $value
   *   The value to encode.
   *
   * @return string
   *   The JSON encoding.
   */
  protected static function encode(array $value): string {
    return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
  }

  /**
   * Reduces a server name to a filesystem-safe slug for its files.
   *
   * @param string $value
   *   The server name.
   *
   * @return string
   *   The slug, with every unsafe run collapsed to a hyphen.
   */
  protected static function slug(string $value): string {
    return (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $value);
  }

}
