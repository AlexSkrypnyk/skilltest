<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\ExitCode;
use AlexSkrypnyk\SkillTest\Security\SecurityFinding;
use AlexSkrypnyk\SkillTest\Security\SecurityScanner;
use AlexSkrypnyk\SkillTest\Validation\ConfigValidator;
use AlexSkrypnyk\SkillTest\Validation\ValidationMessage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Security command.
 *
 * Runs the deterministic `security` group: a static supply-chain scan of every
 * file each skill ships, with the always-on baseline pack. Any finding fails
 * the gate with exit 1; a configuration error such as malformed YAML fails with
 * exit 2. Findings are always errors and can never be downgraded via config.
 */
class SecurityCommand extends Command {

  /**
   * The supported output formats.
   */
  public const array FORMATS = ['text', 'markdown', 'json'];

  /**
   * The findings-table column headers.
   */
  protected const array HEADERS = ['Check', 'Location', 'Description', 'Evidence'];

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setName('security')
      ->setDescription('Scan every shipped skill file for danger patterns (the always-on security baseline)')
      ->addOption(name: 'dir', mode: InputOption::VALUE_REQUIRED, description: 'Repository root (default: current directory)')
      ->addOption(name: 'format', mode: InputOption::VALUE_REQUIRED, description: 'Output format: text, markdown, or json', default: 'text');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $root = $this->resolveRoot($input);
    $format = $input->getOption('format');

    if (!is_string($format) || !in_array($format, self::FORMATS, TRUE)) {
      $output->writeln(sprintf('ERROR unknown format; expected one of: %s.', implode(', ', self::FORMATS)));

      return ExitCode::CONFIG_ERROR;
    }

    try {
      $loaded = (new ConfigLoader($root))->load();
    }
    catch (ConfigException $config_exception) {
      $message = ValidationMessage::error($config_exception->configFile(), $config_exception->pointer(), $config_exception->getMessage());

      return $this->reportErrors($output, $format, [$message]);
    }

    $result = (new ConfigValidator($root))->validate($loaded);

    if ($result->hasErrors()) {
      return $this->reportErrors($output, $format, $result->errors());
    }

    $findings = (new SecurityScanner($root))->scan($loaded);
    $skills = count($loaded->skills);

    if ($format === 'json') {
      $output->writeln($this->renderJson($findings, $skills));
    }
    else {
      $this->renderReport($output, $findings, $skills, $format);
    }

    return $findings === [] ? ExitCode::PASS : ExitCode::FAIL;
  }

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
   * Reports configuration errors in the requested format and returns exit 2.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param string $format
   *   The output format.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationMessage[] $errors
   *   The errors to report.
   *
   * @return int
   *   The config-error exit code.
   */
  protected function reportErrors(OutputInterface $output, string $format, array $errors): int {
    if ($format === 'json') {
      $payload = [
        'ok' => FALSE,
        'findings' => [],
        'errors' => array_map(static fn(ValidationMessage $message): array => $message->toArray(), $errors),
      ];
      $output->writeln($this->encode($payload));
    }
    else {
      foreach ($errors as $error) {
        $output->writeln('ERROR ' . $error->render());
      }
    }

    return ExitCode::CONFIG_ERROR;
  }

  /**
   * Renders the findings and summary as a single JSON document.
   *
   * @param \AlexSkrypnyk\SkillTest\Security\SecurityFinding[] $findings
   *   The findings.
   * @param int $skills
   *   The number of skills scanned.
   *
   * @return string
   *   The JSON document.
   */
  protected function renderJson(array $findings, int $skills): string {
    return $this->encode([
      'ok' => $findings === [],
      'findings' => array_map(static fn(SecurityFinding $finding): array => $finding->toArray(), $findings),
      'summary' => ['findings' => count($findings), 'skills' => $skills],
    ]);
  }

  /**
   * Renders the findings as text or a markdown table, then the summary line.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param \AlexSkrypnyk\SkillTest\Security\SecurityFinding[] $findings
   *   The findings.
   * @param int $skills
   *   The number of skills scanned.
   * @param string $format
   *   Either `text` or `markdown`.
   */
  protected function renderReport(OutputInterface $output, array $findings, int $skills, string $format): void {
    if ($findings !== []) {
      if ($format === 'markdown') {
        $this->renderMarkdown($output, $findings);
      }
      else {
        foreach ($findings as $finding) {
          $output->writeln($finding->render());
        }
      }

      $output->writeln('');
    }

    $output->writeln(sprintf('%d finding(s) across %d skill(s) scanned.', count($findings), $skills));
  }

  /**
   * Renders the findings as a markdown pipe table.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param \AlexSkrypnyk\SkillTest\Security\SecurityFinding[] $findings
   *   The findings.
   */
  protected function renderMarkdown(OutputInterface $output, array $findings): void {
    $output->writeln('| ' . implode(' | ', self::HEADERS) . ' |');
    $output->writeln('| ' . implode(' | ', array_fill(0, count(self::HEADERS), '---')) . ' |');

    foreach ($findings as $finding) {
      $cells = [
        $finding->check,
        sprintf('%s:%d', $finding->file, $finding->line),
        $finding->description,
        $finding->evidence,
      ];
      $escaped = array_map($this->escape(...), $cells);
      $output->writeln('| ' . implode(' | ', $escaped) . ' |');
    }
  }

  /**
   * Escapes a cell so a pipe or newline cannot break the markdown table.
   *
   * The matched evidence and descriptions can contain a literal pipe (e.g.
   * `curl | bash`), which would otherwise be read as a column separator.
   *
   * @param string $value
   *   The cell value.
   *
   * @return string
   *   The escaped, single-line value.
   */
  protected function escape(string $value): string {
    $single_line = str_replace(["\r\n", "\r", "\n"], ' ', $value);

    return str_replace('|', '\\|', $single_line);
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

}
