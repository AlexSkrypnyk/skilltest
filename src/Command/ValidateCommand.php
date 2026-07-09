<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Coverage\Coverage;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\ExitCode;
use AlexSkrypnyk\SkillTest\Validation\ConfigValidator;
use AlexSkrypnyk\SkillTest\Validation\ValidationMessage;
use AlexSkrypnyk\SkillTest\Validation\ValidationResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Validate command.
 *
 * Schema- and coherence-validates the repo `skilltest.yml` and every discovered
 * `eval.yaml`, and with `--show-config` prints the effective merged
 * configuration per skill so precedence is observable. Exits 2 on any error.
 */
class ValidateCommand extends Command {

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setName('validate')
      ->setDescription('Schema- and coherence-validate the repo config and every eval.yaml')
      ->addOption(name: 'dir', mode: InputOption::VALUE_REQUIRED, description: 'Repository root (default: current directory)')
      ->addOption(name: 'show-config', mode: InputOption::VALUE_NONE, description: 'Print the effective merged configuration per skill')
      ->addOption(name: 'models', mode: InputOption::VALUE_REQUIRED, description: 'Override the model list (comma-separated) shown by --show-config')
      ->addOption(name: 'json', mode: InputOption::VALUE_NONE, description: 'Output as JSON');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $root = $this->resolveRoot($input);
    $is_json = $input->getOption('json') === TRUE;
    $show_config = $input->getOption('show-config') === TRUE;

    try {
      $loaded = (new ConfigLoader($root))->load($this->cliOverrides($input));
    }
    catch (ConfigException $config_exception) {
      return $this->reportLoadError($config_exception, $output, $is_json);
    }

    $result = (new ConfigValidator($root))->validate($loaded);
    $uncovered = $this->warnUncovered($loaded, $result);

    if ($is_json) {
      $this->writeJson($output, $loaded, $result, $show_config);
    }
    else {
      $this->writeHuman($output, $loaded, $result, $show_config, $uncovered);
    }

    return $result->hasErrors() ? ExitCode::CONFIG_ERROR : ExitCode::PASS;
  }

  /**
   * Warns about every discovered skill that ships without an `eval.yaml`.
   *
   * validate loads the whole repo, so it already knows which skill directories
   * have no `eval.yaml`. Surfacing them keeps validate honest: an unconfigured
   * repo no longer reports a clean "validated 0 skill(s)." while the coverage
   * gate would fail that very same repo. The exclusion set is the coverage
   * gate's own, so validate never disagrees with the gate about which holes are
   * unexplained. These are warnings, not errors: the coverage gate owns the
   * hard failure, and validate's config-error exit stays reserved for malformed
   * or incoherent config.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded_config
   *   The loaded configuration.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $validation_result
   *   The result to append warnings to.
   *
   * @return int
   *   The number of uncovered skills warned about.
   */
  protected function warnUncovered(LoadedConfig $loaded_config, ValidationResult $validation_result): int {
    $violations = (new Coverage($loaded_config))->violations();

    foreach ($violations as $violation) {
      $validation_result->addWarning($violation->path, '', sprintf("skill '%s' has no eval.yaml and is not excluded (add an eval.yaml or exclude it with a reason).", $violation->skill));
    }

    return count($violations);
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
   * Collects the CLI configuration overrides.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   *
   * @return array<string, mixed>
   *   The overrides keyed by name.
   */
  protected function cliOverrides(InputInterface $input): array {
    $overrides = [];
    $models = $input->getOption('models');

    if (is_string($models) && $models !== '') {
      $overrides['models'] = $models;
    }

    return $overrides;
  }

  /**
   * Reports a fatal load error and returns the config-error exit code.
   *
   * @param \AlexSkrypnyk\SkillTest\Exception\ConfigException $config_exception
   *   The load error.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param bool $is_json
   *   Whether to emit JSON.
   *
   * @return int
   *   The config-error exit code.
   */
  protected function reportLoadError(ConfigException $config_exception, OutputInterface $output, bool $is_json): int {
    $message = ValidationMessage::error($config_exception->configFile(), $config_exception->pointer(), $config_exception->getMessage());

    if ($is_json) {
      $output->writeln($this->encode(['ok' => FALSE, 'errors' => [$message->toArray()], 'warnings' => []]));
    }
    else {
      $output->writeln('ERROR ' . $message->render());
    }

    return ExitCode::CONFIG_ERROR;
  }

  /**
   * Writes the machine-readable result.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded_config
   *   The loaded configuration.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $validation_result
   *   The validation result.
   * @param bool $show_config
   *   Whether to include the merged configuration.
   */
  protected function writeJson(OutputInterface $output, LoadedConfig $loaded_config, ValidationResult $validation_result, bool $show_config): void {
    $payload = [
      'ok' => !$validation_result->hasErrors(),
      'errors' => array_map(static fn(ValidationMessage $validation_message): array => $validation_message->toArray(), $validation_result->errors()),
      'warnings' => array_map(static fn(ValidationMessage $validation_message): array => $validation_message->toArray(), $validation_result->warnings()),
    ];

    if ($show_config) {
      $config = [];

      foreach ($loaded_config->skills as $skill) {
        $config[$skill->effective->skill] = $skill->effective->toArray();
      }

      $payload['config'] = $config;
    }

    $output->writeln($this->encode($payload));
  }

  /**
   * Writes the human-readable result.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded_config
   *   The loaded configuration.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationResult $validation_result
   *   The validation result.
   * @param bool $show_config
   *   Whether to print the merged configuration.
   * @param int $uncovered
   *   The number of discovered skills that have no `eval.yaml`.
   */
  protected function writeHuman(OutputInterface $output, LoadedConfig $loaded_config, ValidationResult $validation_result, bool $show_config, int $uncovered): void {
    if ($show_config) {
      foreach ($loaded_config->skills as $skill) {
        $output->writeln('# ' . $skill->effective->skill);
        $output->writeln(Yaml::dump($skill->effective->toArray(), 6, 2));
      }
    }

    foreach ($validation_result->warnings() as $warning) {
      $output->writeln('WARNING ' . $warning->render());
    }

    foreach ($validation_result->errors() as $error) {
      $output->writeln('ERROR ' . $error->render());
    }

    if ($validation_result->hasErrors()) {
      $output->writeln(sprintf('FAILED: %d error(s).', count($validation_result->errors())));

      return;
    }

    if ($uncovered > 0) {
      $output->writeln(sprintf('OK: validated %d skill(s); %d discovered skill(s) have no eval.yaml (see warnings).', count($loaded_config->skills), $uncovered));

      return;
    }

    $output->writeln(sprintf('OK: validated %d skill(s).', count($loaded_config->skills)));
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
