<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\ExitCode;
use AlexSkrypnyk\SkillTest\Migrate\Migrator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Migrate command.
 *
 * Checks one `eval.yaml`, `skilltest.yml`, or `results.json` against the
 * current schema and rewrites it when an older major requires it. A file
 * already at the current major is reported as current and left untouched; a
 * missing file, a malformed one, or a file from a newer major the tool cannot
 * read is a configuration error (exit 2). The rewrite is the only thing that
 * reads an unreadable-major file on purpose, so a repo carrying a stale config
 * has a supported path forward rather than a hard failure everywhere else.
 */
class MigrateCommand extends Command {

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setName('migrate')
      ->setDescription('Check a config or results file against the current schema and rewrite it when an older major requires it')
      ->addArgument(name: 'file', mode: InputArgument::REQUIRED, description: 'The eval.yaml, skilltest.yml, or results.json file to migrate');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $file = $input->getArgument('file');

    if (!is_string($file) || $file === '') {
      $output->writeln('ERROR migrate expects a file path.');

      return ExitCode::CONFIG_ERROR;
    }

    try {
      $result = (new Migrator())->migrate($file);
    }
    catch (ConfigException $config_exception) {
      $output->writeln('ERROR ' . $config_exception->getMessage());

      return ExitCode::CONFIG_ERROR;
    }

    $output->writeln($result->message);

    return ExitCode::PASS;
  }

}
