<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\ExitCode;
use AlexSkrypnyk\SkillTest\Live\Mcp\McpMockServer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The internal `mcp-serve` command: run one MCP mock over stdio.
 *
 * Not a user-facing command - it is the process the per-trial MCP config
 * launches so the agent has a real stdio server to connect to. It reads the
 * server definition skilltest wrote for the trial, then hands stdin and stdout
 * to {@see McpMockServer}, which speaks the protocol until the agent
 * disconnects. Its own responsibility is narrow: fail with exit `2` when the
 * definition file is missing or is not a valid mock, otherwise serve. The
 * streams default to the real stdin and stdout but are constructor-injectable,
 * so the serve path is exercised in a test with in-memory streams rather than
 * the real stdin the loop would block on.
 */
class McpServeCommand extends Command {

  /**
   * Constructs an McpServeCommand.
   *
   * @param resource|null $in
   *   The stream the server reads messages from; defaults to stdin.
   * @param resource|null $out
   *   The stream the server writes responses to; defaults to stdout.
   */
  public function __construct(
    protected mixed $in = NULL,
    protected mixed $out = NULL,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setName('mcp-serve')
      ->setHidden(TRUE)
      ->setDescription('Serve one MCP mock over stdio (internal; launched per trial)')
      ->addArgument('config', InputArgument::REQUIRED, 'The mock server definition JSON file');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    $argument = $input->getArgument('config');
    $config = is_string($argument) ? $argument : '';

    if (!is_file($config)) {
      $stderr->writeln(sprintf('ERROR mcp-serve: definition file not found: %s', $config), OutputInterface::VERBOSITY_QUIET);

      return ExitCode::CONFIG_ERROR;
    }

    $data = json_decode((string) file_get_contents($config), TRUE);
    $name = is_array($data) ? Data::toStringOrNull(Data::get($data, 'server')) : NULL;

    if (!is_array($data) || $name === NULL || $name === '') {
      $stderr->writeln(sprintf('ERROR mcp-serve: definition file is not a valid mock: %s', $config), OutputInterface::VERBOSITY_QUIET);

      return ExitCode::CONFIG_ERROR;
    }

    $server = [
      'server' => $name,
      'log' => Data::toStringOrNull(Data::get($data, 'log')) ?? '',
      'tools' => Data::toArrayList(Data::get($data, 'tools')),
    ];

    (new McpMockServer($server, $this->inStream(), $this->outStream()))->serve();

    return ExitCode::PASS;
  }

  /**
   * The stream the server reads messages from: the injected one, or stdin.
   *
   * @return resource
   *   The input stream.
   */
  protected function inStream() {
    // @codeCoverageIgnoreStart
    return is_resource($this->in) ? $this->in : STDIN;
    // @codeCoverageIgnoreEnd
  }

  /**
   * The stream the server writes responses to: the injected one, or stdout.
   *
   * @return resource
   *   The output stream.
   */
  protected function outStream() {
    // @codeCoverageIgnoreStart
    return is_resource($this->out) ? $this->out : STDOUT;
    // @codeCoverageIgnoreEnd
  }

}
