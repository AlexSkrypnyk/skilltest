<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\ExitCode;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared option and error plumbing for the offline results commands.
 *
 * The token-free commands that consume a saved `results.json` - `grade` and
 * `gate` - resolve their root and options and report a configuration error the
 * same way, so that small machinery lives here once rather than being copied
 * into each command.
 */
trait ResultsCommandTrait {

  /**
   * Resolves the repository root from the option or the current directory.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   *
   * @return string
   *   The repository root.
   */
  protected function resolveRoot(InputInterface $input): string {
    $dir = $input->getOption('dir');

    if (is_string($dir) && $dir !== '') {
      return $dir;
    }

    $cwd = getcwd();

    // @codeCoverageIgnoreStart
    if ($cwd === FALSE) {
      return '.';
    }
    // @codeCoverageIgnoreEnd
    return $cwd;
  }

  /**
   * Reads a string option, returning NULL when it is absent or empty.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   * @param string $name
   *   The option name.
   *
   * @return string|null
   *   The option value, or NULL when it is unset or blank.
   */
  protected function stringOption(InputInterface $input, string $name): ?string {
    $value = $input->getOption($name);

    return is_string($value) && $value !== '' ? $value : NULL;
  }

  /**
   * The error output stream, split from stdout when the console supports it.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   *
   * @return \Symfony\Component\Console\Output\OutputInterface
   *   The error output.
   */
  protected function stderr(OutputInterface $output): OutputInterface {
    return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
  }

  /**
   * Reports one configuration error to stderr and returns exit 2.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $stderr
   *   The error output.
   * @param string $message
   *   The error message.
   *
   * @return int
   *   The config-error exit code.
   */
  protected function configError(OutputInterface $stderr, string $message): int {
    $stderr->writeln('ERROR ' . $message, OutputInterface::VERBOSITY_QUIET);

    return ExitCode::CONFIG_ERROR;
  }

}
