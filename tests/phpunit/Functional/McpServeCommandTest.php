<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\SkillTest\Command\McpServeCommand;
use AlexSkrypnyk\SkillTest\ExitCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Class McpServeCommandTest.
 *
 * Functional test for the internal `mcp-serve` command: its definition-file
 * guards over a command tester, its serve path over injected in-memory streams,
 * and a real child process to prove a mocked tool answers over true stdio with
 * no network.
 */
#[CoversClass(McpServeCommand::class)]
#[Group('command')]
final class McpServeCommandTest extends TestCase {

  /**
   * A temporary directory the definition and log files are written under.
   */
  protected string $dir = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->dir = dirname(__DIR__, 3) . '/.artifacts/tmp/mcpserve-' . getmypid() . '-' . uniqid();
    mkdir($this->dir, 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->dir !== '' && is_dir($this->dir)) {
      array_map(unlink(...), glob($this->dir . '/*') ?: []);
      rmdir($this->dir);
    }

    parent::tearDown();
  }

  public function testMissingDefinitionFileFails(): void {
    $tester = new CommandTester(new McpServeCommand());
    $tester->execute(['config' => $this->dir . '/nope.json'], ['capture_stderr_separately' => TRUE]);

    $this->assertSame(ExitCode::CONFIG_ERROR, $tester->getStatusCode());
    $this->assertStringContainsString('definition file not found', $tester->getErrorOutput());
  }

  public function testInvalidJsonDefinitionFails(): void {
    $file = $this->dir . '/bad.json';
    file_put_contents($file, '{not valid json');
    $tester = new CommandTester(new McpServeCommand());
    $tester->execute(['config' => $file], ['capture_stderr_separately' => TRUE]);

    $this->assertSame(ExitCode::CONFIG_ERROR, $tester->getStatusCode());
    $this->assertStringContainsString('not a valid mock', $tester->getErrorOutput());
  }

  public function testDefinitionWithoutServerFails(): void {
    $file = $this->dir . '/noserver.json';
    file_put_contents($file, '{"tools":[]}');
    $tester = new CommandTester(new McpServeCommand());
    $tester->execute(['config' => $file], ['capture_stderr_separately' => TRUE]);

    $this->assertSame(ExitCode::CONFIG_ERROR, $tester->getStatusCode());
    $this->assertStringContainsString('not a valid mock', $tester->getErrorOutput());
  }

  public function testServesMatchedCallOverInjectedStreams(): void {
    $log = $this->dir . '/github.log.jsonl';
    $file = $this->writeDefinition($log);

    $in = $this->memoryStream($this->initialize() . "\n" . $this->call() . "\n");
    $out = $this->memoryStream();

    $tester = new CommandTester(new McpServeCommand($in, $out));
    $tester->execute(['config' => $file]);

    $this->assertSame(ExitCode::PASS, $tester->getStatusCode());
    rewind($out);
    $this->assertStringContainsString('created-hermetically', (string) stream_get_contents($out));
    fclose($in);
    fclose($out);
    $this->assertFileExists($log);
  }

  public function testAnswersMockedToolOverRealStdio(): void {
    $file = $this->writeDefinition($this->dir . '/github.log.jsonl');
    $bin = dirname(__DIR__, 3) . '/skilltest';

    $process = proc_open([PHP_BINARY, $bin, 'mcp-serve', $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $this->assertIsResource($process);

    fwrite($pipes[0], $this->initialize() . "\n" . $this->call() . "\n");
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    $this->assertSame(0, $exit, $stderr);
    $this->assertStringContainsString('created-hermetically', $stdout);
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
   * Writes a one-tool mock definition and returns its path.
   *
   * @param string $log
   *   The call log path baked into the definition.
   *
   * @return string
   *   The definition file path.
   */
  protected function writeDefinition(string $log): string {
    $file = $this->dir . '/github.json';
    file_put_contents($file, (string) json_encode([
      'server' => 'github',
      'log' => $log,
      'tools' => [
        ['name' => 'create_issue', 'description' => 'Create', 'responses' => [
          ['kind' => 'exact', 'matcher' => ['title' => 'Bug'], 'text' => 'created-hermetically', 'label' => 'match {title}'],
        ]],
      ],
    ], JSON_UNESCAPED_SLASHES));

    return $file;
  }

  /**
   * The initialize request line.
   *
   * @return string
   *   The encoded request.
   */
  protected function initialize(): string {
    return (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []], JSON_UNESCAPED_SLASHES);
  }

  /**
   * A matching `tools/call` request line.
   *
   * @return string
   *   The encoded request.
   */
  protected function call(): string {
    return (string) json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'create_issue', 'arguments' => ['title' => 'Bug']]], JSON_UNESCAPED_SLASHES);
  }

}
