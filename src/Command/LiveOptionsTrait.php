<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\ExitCode;
use AlexSkrypnyk\SkillTest\Live\LlmReport;
use AlexSkrypnyk\SkillTest\Live\LlmSuite;
use AlexSkrypnyk\SkillTest\Run\Redactor;
use AlexSkrypnyk\SkillTest\Run\ResultsWriter;
use AlexSkrypnyk\SkillTest\Validation\ValidationMessage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The shared plumbing behind the token-spending live commands.
 *
 * Both `llm` and `matrix` resolve the same root and options, run the same host
 * preflight, spend tokens through the same suite, and persist the same redacted
 * results, so that machinery lives here once: option and root parsing, the
 * per-trial timeout, the CLI override map, the disabled-redaction-aware
 * persistence, and the config error contract (exit 2, a JSON error document
 * under `--json`). What differs - how a run is rendered and gated - stays in
 * each command.
 */
trait LiveOptionsTrait {

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
   * Reads an integer option, returning NULL when it is absent or non-numeric.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   * @param string $name
   *   The option name.
   *
   * @return int|null
   *   The option value, or NULL when unset or not an integer.
   */
  protected function intOption(InputInterface $input, string $name): ?int {
    return Data::toIntOrNull($input->getOption($name));
  }

  /**
   * Extracts the string-glob values of a repeatable option.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   * @param string $name
   *   The option name.
   *
   * @return string[]
   *   The non-empty glob strings.
   */
  protected function globs(InputInterface $input, string $name): array {
    $raw = $input->getOption($name);

    return array_values(array_filter(is_array($raw) ? $raw : [], static fn(mixed $glob): bool => is_string($glob) && $glob !== ''));
  }

  /**
   * Builds the CLI configuration overrides for the named options that are set.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   * @param array<string, string> $names
   *   The override key keyed by the input option name to read it from.
   *
   * @return array<string, string>
   *   The overrides keyed by configuration name.
   */
  protected function overridesFrom(InputInterface $input, array $names): array {
    $overrides = [];

    foreach ($names as $option => $key) {
      $value = $this->stringOption($input, $option);

      if ($value !== NULL) {
        $overrides[$key] = $value;
      }
    }

    return $overrides;
  }

  /**
   * The process environment as a name-keyed string map.
   *
   * @return array<string, string>
   *   The environment map.
   */
  protected function environmentMap(): array {
    return getenv();
  }

  /**
   * Builds the sink a failing teardown hook warns through.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $stderr
   *   The error output non-aborting hook failures are written to.
   *
   * @return \Closure
   *   The warn closure.
   */
  protected function warn(OutputInterface $stderr): \Closure {
    return static function (string $message) use ($stderr): void {
      $stderr->writeln('WARNING ' . $message);
    };
  }

  /**
   * Resolves the per-trial timeout from the environment, or the default.
   *
   * @return float
   *   The timeout in seconds.
   */
  protected function timeout(): float {
    $value = getenv(LlmSuite::ENV_TIMEOUT);

    return is_string($value) && is_numeric($value) ? (float) $value : LlmSuite::DEFAULT_TIMEOUT;
  }

  /**
   * Persists the results document and transcripts, redacted, to disk.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded
   *   The loaded configuration, carrying the repo `report` block.
   * @param \Symfony\Component\Console\Output\OutputInterface $stderr
   *   The error output for the disabled-redaction warning and write notices.
   * @param \AlexSkrypnyk\SkillTest\Live\LlmReport $report
   *   The run outcome, supplying the transcript artifacts.
   * @param array<string, mixed> $document
   *   The results document to persist.
   * @param string|null $file
   *   The `--output` file destination, when set.
   * @param string|null $dir
   *   The `--output-dir` parent directory, when set.
   */
  protected function persist(LoadedConfig $loaded, OutputInterface $stderr, LlmReport $report, array $document, ?string $file, ?string $dir): void {
    $redact = Data::toBoolOrNull(Data::get($loaded->repo->report, 'redact')) ?? TRUE;

    if (!$redact) {
      $stderr->writeln('WARNING redaction disabled (report.redact: false); environment secrets may be written to persisted artifacts.', OutputInterface::VERBOSITY_QUIET);
    }

    $writer = new ResultsWriter(Redactor::fromEnvironment(getenv(), $redact));

    if ($dir !== NULL) {
      $stderr->writeln(sprintf('results written to %s', $writer->writeDir($document, $dir, gmdate('Ymd-His'), $report->artifacts())));
    }

    if ($file !== NULL) {
      $stderr->writeln(sprintf('results written to %s', $writer->writeFile($document, $file)));
    }
  }

  /**
   * Converts a thrown configuration error to a reportable message.
   *
   * @param \AlexSkrypnyk\SkillTest\Exception\ConfigException $config_exception
   *   The thrown error.
   *
   * @return \AlexSkrypnyk\SkillTest\Validation\ValidationMessage
   *   The equivalent validation message.
   */
  protected function toMessage(ConfigException $config_exception): ValidationMessage {
    return ValidationMessage::error($config_exception->configFile(), $config_exception->pointer(), $config_exception->getMessage());
  }

  /**
   * Reports configuration errors and returns exit 2.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The standard output.
   * @param \Symfony\Component\Console\Output\OutputInterface $stderr
   *   The error output.
   * @param bool $json
   *   Whether the JSON output contract is in effect.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationMessage[] $errors
   *   The errors to report.
   *
   * @return int
   *   The config-error exit code.
   */
  protected function reportErrors(OutputInterface $output, OutputInterface $stderr, bool $json, array $errors): int {
    if ($json) {
      $payload = [
        'ok' => FALSE,
        'skills' => [],
        'errors' => array_map(static fn(ValidationMessage $message): array => $message->toArray(), $errors),
      ];
      $output->writeln($this->encode($payload), OutputInterface::VERBOSITY_QUIET);
    }
    else {
      foreach ($errors as $error) {
        $stderr->writeln('ERROR ' . $error->render(), OutputInterface::VERBOSITY_QUIET);
      }
    }

    return ExitCode::CONFIG_ERROR;
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
