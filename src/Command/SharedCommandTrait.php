<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared option, stream, and encoding plumbing used by every command family.
 */
trait SharedCommandTrait {

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
   * Encodes a payload as a single JSON line.
   *
   * @param array<string, mixed> $payload
   *   The payload to encode.
   *
   * @return string
   *   The JSON encoding.
   */
  protected function encode(array $payload): string {
    return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
   * Renders one failed check as an indented line with its evidence.
   *
   * @param \AlexSkrypnyk\SkillTest\Contract\CheckResult $failure
   *   The failed check.
   *
   * @return string
   *   The rendered line.
   */
  protected function failureLine(CheckResult $failure): string {
    $line = sprintf('  %s FAIL - %s', $failure->id, $failure->message);

    return $failure->evidence === '' ? $line : sprintf('%s [%s]', $line, $failure->evidence);
  }

}
