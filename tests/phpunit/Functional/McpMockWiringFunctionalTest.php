<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\SkillTest\Live\Mcp\McpMockWiring;
use AlexSkrypnyk\SkillTest\Tests\Traits\ArrayPathTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class McpMockWiringFunctionalTest.
 *
 * Writes the per-server definitions and MCP config into a real workspace, then
 * reads a server's call log back, so the mock materialisation is exercised
 * without an environment or an agent.
 */
#[CoversClass(McpMockWiring::class)]
#[Group('live')]
final class McpMockWiringFunctionalTest extends TestCase {

  use ArrayPathTrait;

  /**
   * A temporary workspace the mock configs are written under.
   */
  protected string $dir = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->dir = dirname(__DIR__, 3) . '/.artifacts/tmp/mcpwiring-' . getmypid() . '-' . uniqid();
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

  public function testNoConfigWhenNoServers(): void {
    $this->assertNull(McpMockWiring::write($this->dir, [], ['php', '/entry/skilltest']));
    $this->assertSame([], McpMockWiring::logs($this->dir, []));
  }

  public function testWritesConfigsAndReadsBackTheLog(): void {
    $servers = [[
      'server' => 'github',
      'log' => '',
      'tools' => [['name' => 'create_issue', 'description' => NULL, 'responses' => [['kind' => 'exact', 'matcher' => ['title' => 'Bug'], 'text' => 'ok', 'label' => 'match {title}']]]],
    ]];

    $config_path = McpMockWiring::write($this->dir, $servers, ['php', '/entry/skilltest']);

    $this->assertNotNull($config_path);
    $this->assertFileExists($config_path);

    $config = $this->decode((string) file_get_contents($config_path));
    $this->assertSame('php', $this->path($config, 'mcpServers', 'github', 'command'));
    $this->assertSame(['/entry/skilltest', 'mcp-serve', $this->dir . '/' . McpMockWiring::MOCKS_DIR . '/github.json'], $this->path($config, 'mcpServers', 'github', 'args'));

    $definition = $this->decode((string) file_get_contents($this->pathString($config, 'mcpServers', 'github', 'args', 2)));
    $this->assertSame('github', $this->path($definition, 'server'));
    $this->assertStringEndsWith('github.log.jsonl', $this->pathString($definition, 'log'));
    $this->assertSame('create_issue', $this->path($definition, 'tools', 0, 'name'));

    // The child process writes the log; logs() reads it back.
    file_put_contents($this->pathString($definition, 'log'), '{"tool":"create_issue","matched":true}' . "\n");
    $this->assertStringContainsString('create_issue', McpMockWiring::logs($this->dir, $servers)['github']);
  }

  /**
   * Decodes a JSON string into an array, failing when it is not one.
   *
   * @param string $json
   *   The JSON string.
   *
   * @return array<mixed>
   *   The decoded array.
   */
  protected function decode(string $json): array {
    $decoded = json_decode($json, TRUE);

    if (!is_array($decoded)) {
      // @codeCoverageIgnoreStart
      $this->fail('Expected JSON to decode to an array.');
      // @codeCoverageIgnoreEnd
    }

    return $decoded;
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
