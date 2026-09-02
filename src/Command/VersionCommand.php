<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\ExitCode;
use AlexSkrypnyk\SkillTest\Version;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Version command.
 *
 * Prints the tool version, the supported schema versions, and build info.
 */
class VersionCommand extends Command {

  use SharedCommandTrait;

  /**
   * The supported output formats.
   */
  public const array FORMATS = ['text', 'json'];

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setName('version')
      ->setDescription('Print the tool version, supported schema versions, and build info')
      ->addOption(name: 'format', mode: InputOption::VALUE_REQUIRED, description: 'Output format: text or json', default: 'text')
      ->addOption(name: 'json', mode: InputOption::VALUE_NONE, description: 'Shorthand for --format=json');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $format = $input->getOption('format');
    $format = is_string($format) && $format !== '' ? $format : 'text';
    $format = (bool) $input->getOption('json') ? 'json' : $format;

    if (!in_array($format, self::FORMATS, TRUE)) {
      $this->stderr($output)->writeln('ERROR ' . sprintf('unknown format; expected one of: %s.', implode(', ', self::FORMATS)), OutputInterface::VERBOSITY_QUIET);

      return ExitCode::CONFIG_ERROR;
    }

    $json = $format === 'json';

    $info = [
      'tool' => ['name' => Version::NAME, 'version' => Version::id()],
      'schemas' => ['config' => Version::CONFIG_SCHEMA_VERSION, 'results' => Version::RESULTS_SCHEMA_VERSION],
      'build' => ['php' => PHP_VERSION, 'runtime' => Version::runtime()],
    ];

    if ($json) {
      $output->writeln(json_encode($info, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), OutputInterface::VERBOSITY_QUIET);

      return ExitCode::PASS;
    }

    $output->writeln(sprintf('%s %s', $info['tool']['name'], $info['tool']['version']));
    $output->writeln(sprintf('Config schema:  %s', $info['schemas']['config']));
    $output->writeln(sprintf('Results schema: %s', $info['schemas']['results']));
    $output->writeln(sprintf('PHP:            %s (%s)', $info['build']['php'], $info['build']['runtime']));

    return ExitCode::PASS;
  }

}
