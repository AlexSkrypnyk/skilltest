<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\ExitCode;
use AlexSkrypnyk\SkillTest\Results\Interpreter;
use AlexSkrypnyk\SkillTest\Results\Report\HtmlReport;
use AlexSkrypnyk\SkillTest\Results\Report\ReportRenderer;
use AlexSkrypnyk\SkillTest\Results\ResultsFile;
use AlexSkrypnyk\SkillTest\Run\Report\ArtifactWriter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The `report` command: render a saved results document.
 *
 * A renderer, not a gate: it reads one `results.json` and shows it, so it exits
 * 0 whatever the saved run said and only a bad argument or an unreadable or
 * incompatible file is a configuration error (exit 2). By default it prints a
 * terminal summary; `--html <file>` writes a single self-contained HTML report
 * instead (path reported to stderr, stdout left clean); `--interpret` adds a
 * plain-language reading of the numbers - to stdout for the terminal summary,
 * and embedded in the page for the HTML report.
 */
class ReportCommand extends Command {

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setName('report')
      ->setDescription('Render a saved results.json: terminal summary, a self-contained HTML report, and a plain-language reading')
      ->addArgument(name: 'file', mode: InputArgument::REQUIRED, description: 'The results.json file to render')
      ->addOption(name: 'html', mode: InputOption::VALUE_REQUIRED, description: 'Write a single self-contained HTML report to this file instead of the terminal summary')
      ->addOption(name: 'interpret', mode: InputOption::VALUE_NONE, description: 'Add a plain-language reading of the numbers: the top failure and a concrete next step');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

    $file = $input->getArgument('file');

    if (!is_string($file) || $file === '') {
      $stderr->writeln('ERROR report expects a results file path.', OutputInterface::VERBOSITY_QUIET);

      return ExitCode::CONFIG_ERROR;
    }

    try {
      $document = ResultsFile::load($file);
    }
    catch (ConfigException $config_exception) {
      $stderr->writeln('ERROR ' . $this->errorLine($config_exception), OutputInterface::VERBOSITY_QUIET);

      return ExitCode::CONFIG_ERROR;
    }

    $interpret = (bool) $input->getOption('interpret');
    $interpretation = $interpret ? Interpreter::paragraph($document) : NULL;

    $html = $input->getOption('html');

    if (is_string($html) && $html !== '') {
      $written = (new ArtifactWriter())->write($html, (new HtmlReport())->render($document, $interpretation));
      $stderr->writeln(sprintf('report written to %s', $written));

      return ExitCode::PASS;
    }

    foreach ((new ReportRenderer())->text($document) as $line) {
      $output->writeln($line);
    }

    if ($interpretation !== NULL) {
      $output->writeln('');
      $output->writeln($interpretation);
    }

    return ExitCode::PASS;
  }

  /**
   * Renders a configuration error with its file context when it has one.
   *
   * @param \AlexSkrypnyk\SkillTest\Exception\ConfigException $config_exception
   *   The error.
   *
   * @return string
   *   The rendered line.
   */
  protected function errorLine(ConfigException $config_exception): string {
    $file = $config_exception->configFile();

    return $file === '' ? $config_exception->getMessage() : sprintf('%s: %s', $file, $config_exception->getMessage());
  }

}
