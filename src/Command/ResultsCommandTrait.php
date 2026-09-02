<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\ExitCode;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared option and error handling for the offline results commands.
 */
trait ResultsCommandTrait {

  use SharedCommandTrait;

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
