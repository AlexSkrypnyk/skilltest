<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\ExitCode;
use AlexSkrypnyk\SkillTest\Results\Compare\Comparison;
use AlexSkrypnyk\SkillTest\Results\Compare\CompareRenderer;
use AlexSkrypnyk\SkillTest\Results\ResultsFile;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The `compare` command: two or more results files, side by side.
 *
 * Diagnosis, not policy: it loads each `results.json`, lines the runs up, and
 * shows the per-task, per-model, and aggregate deltas so a reader can see what
 * moved between two branches, two skill revisions, or two models. It never
 * decides whether a change is acceptable - that is `gate` - so it exits 0
 * whenever every file loaded, and only a bad argument or an unreadable or
 * incompatible file is a configuration error (exit 2). The first file is the
 * baseline every delta is measured against.
 */
class CompareCommand extends Command {

  /**
   * The supported output formats.
   */
  public const array FORMATS = ['table', 'json'];

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setName('compare')
      ->setDescription('Compare two or more results.json files: per-task, per-model, and aggregate deltas')
      ->addArgument(name: 'files', mode: InputArgument::REQUIRED | InputArgument::IS_ARRAY, description: 'Two or more results.json files, the first the baseline')
      ->addOption(name: 'format', mode: InputOption::VALUE_REQUIRED, description: 'Output format: table or json', default: 'table');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

    $raw = $input->getArgument('files');
    $paths = array_values(array_filter(is_array($raw) ? $raw : [], static fn(mixed $path): bool => is_string($path) && $path !== ''));

    if (count($paths) < 2) {
      $stderr->writeln('ERROR compare needs at least two results files.', OutputInterface::VERBOSITY_QUIET);

      return ExitCode::CONFIG_ERROR;
    }

    $format = $input->getOption('format');
    $format = is_string($format) && $format !== '' ? $format : 'table';

    if (!in_array($format, self::FORMATS, TRUE)) {
      $stderr->writeln(sprintf('ERROR unknown format; expected one of: %s.', implode(', ', self::FORMATS)), OutputInterface::VERBOSITY_QUIET);

      return ExitCode::CONFIG_ERROR;
    }

    try {
      $files = $this->loadFiles($paths);
    }
    catch (ConfigException $config_exception) {
      $stderr->writeln('ERROR ' . $this->errorLine($config_exception), OutputInterface::VERBOSITY_QUIET);

      return ExitCode::CONFIG_ERROR;
    }

    $comparison = Comparison::of($files);

    if ($format === 'json') {
      $output->writeln(json_encode($comparison->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), OutputInterface::VERBOSITY_QUIET);

      return ExitCode::PASS;
    }

    foreach ((new CompareRenderer($comparison))->text() as $line) {
      $output->writeln($line);
    }

    return ExitCode::PASS;
  }

  /**
   * Loads each results file and pairs it with a unique display label.
   *
   * @param string[] $paths
   *   The file paths, in order.
   *
   * @return array<int, array{label: string, document: array<string, mixed>}>
   *   The labelled documents.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When any file is missing, unreadable, or incompatible.
   */
  protected function loadFiles(array $paths): array {
    $files = [];
    $seen = [];

    foreach ($paths as $index => $path) {
      $label = pathinfo($path, PATHINFO_FILENAME);
      $label = $label === '' ? 'file' : $label;

      if (isset($seen[$label])) {
        $label .= '#' . ($index + 1);
      }

      $seen[$label] = TRUE;
      $files[] = ['label' => $label, 'document' => ResultsFile::load($path)];
    }

    return $files;
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
