<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\ExitCode;
use AlexSkrypnyk\SkillTest\Update\ReleaseClient;
use AlexSkrypnyk\SkillTest\Version;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Self-update command.
 *
 * Replaces the installed executable with the latest release after checking
 * its SHA-256 checksum against the published checksums file - a mismatch
 * refuses the swap and touches nothing, so a corrupt or tampered download can
 * never be installed. It runs only from an installed PHAR (a source checkout
 * has nothing to replace) and never runs implicitly. Confirmation is required
 * unless `--yes` is passed, so a scripted upgrade is explicit. The fetcher,
 * runtime form, target path, and current version are injected so the whole
 * flow - refuse, up-to-date, mismatch, confirm, swap - is tested without a
 * network or a real executable.
 */
class SelfUpdateCommand extends Command {

  /**
   * The fetcher passed to the release client.
   *
   * @var \Closure(string): array{0: int, 1: string}
   */
  protected \Closure $fetcher;

  /**
   * The runtime-form resolver: `phar` or `source`.
   *
   * @var \Closure(): string
   */
  protected \Closure $runtime;

  /**
   * The resolver of the executable path to replace.
   *
   * @var \Closure(): string
   */
  protected \Closure $executable;

  /**
   * The running tool version.
   */
  protected string $currentVersion;

  /**
   * Constructs a SelfUpdateCommand.
   *
   * @param \Closure|null $fetcher
   *   The release fetcher, or NULL for the live HTTP fetcher.
   * @param \Closure|null $runtime
   *   The runtime-form resolver, or NULL for the real one.
   * @param \Closure|null $executable
   *   The resolver of the executable path, or NULL for the running PHAR.
   * @param string|null $current_version
   *   The running version, or NULL for the compiled-in one.
   * @param string $repo
   *   The `owner/name` repository to read releases from.
   */
  public function __construct(
    ?\Closure $fetcher = NULL,
    ?\Closure $runtime = NULL,
    ?\Closure $executable = NULL,
    ?string $current_version = NULL,
    protected string $repo = ReleaseClient::DEFAULT_REPO,
  ) {
    parent::__construct();

    $this->fetcher = $fetcher ?? ReleaseClient::liveFetcher();
    $this->runtime = $runtime ?? self::defaultRuntime();
    $this->executable = $executable ?? self::defaultExecutable();
    $this->currentVersion = $current_version ?? Version::id();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setName('self-update')
      ->setDescription('Download, verify, and install the latest release, replacing the current executable')
      ->addOption(name: 'yes', mode: InputOption::VALUE_NONE, description: 'Skip the confirmation prompt (for scripts)');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    if (($this->runtime)() !== 'phar') {
      $output->writeln('ERROR self-update replaces an installed executable; you are running from source. Install a released PHAR or binary first.');

      return ExitCode::CONFIG_ERROR;
    }

    if (!$this->isVersion($this->currentVersion)) {
      $output->writeln(sprintf('ERROR cannot determine the current version (%s); reinstall from a release.', $this->currentVersion));

      return ExitCode::CONFIG_ERROR;
    }

    $client = new ReleaseClient($this->fetcher, $this->repo);
    $tag = $client->latestTag();

    if ($tag === NULL) {
      $output->writeln('ERROR could not determine the latest release; check your network and try again.');

      return ExitCode::FAIL;
    }

    if (version_compare(ltrim($tag, 'vV'), ltrim($this->currentVersion, 'vV'), '<=')) {
      $output->writeln(sprintf('Already up to date (version %s).', $this->currentVersion));

      return ExitCode::PASS;
    }

    return $this->apply($input, $output, $client, $tag);
  }

  /**
   * Downloads, verifies, confirms, and swaps in the release for a tag.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param \AlexSkrypnyk\SkillTest\Update\ReleaseClient $client
   *   The release client.
   * @param string $tag
   *   The release tag to install.
   *
   * @return int
   *   The exit code.
   */
  protected function apply(InputInterface $input, OutputInterface $output, ReleaseClient $client, string $tag): int {
    $phar = $client->download($client->pharUrl($tag));
    $checksums = $client->download($client->checksumsUrl($tag));

    if ($phar === NULL || $checksums === NULL) {
      $output->writeln(sprintf('ERROR could not download the release assets for %s.', $tag));

      return ExitCode::FAIL;
    }

    $expected = $this->expectedChecksum($checksums);
    $actual = hash('sha256', $phar);

    if ($expected === NULL || !hash_equals($expected, $actual)) {
      $output->writeln(sprintf('ERROR checksum verification failed for %s (expected %s, got %s); refusing to replace the executable.', ReleaseClient::PHAR_NAME, $expected ?? '<none>', $actual));

      return ExitCode::FAIL;
    }

    $target = ($this->executable)();

    if (!$this->confirm($input, $output, $tag, $target)) {
      $output->writeln('Update cancelled.');

      return ExitCode::PASS;
    }

    return $this->swap($output, $target, $phar, $tag);
  }

  /**
   * Confirms the swap, unless `--yes` was passed.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param string $tag
   *   The release tag.
   * @param string $target
   *   The executable path to replace.
   *
   * @return bool
   *   TRUE when the swap should proceed.
   */
  protected function confirm(InputInterface $input, OutputInterface $output, string $tag, string $target): bool {
    if ((bool) $input->getOption('yes')) {
      return TRUE;
    }

    $helper = $this->getHelper('question');

    if (!$helper instanceof QuestionHelper) {
      // @codeCoverageIgnoreStart
      throw new \RuntimeException('The question helper is unavailable.');
      // @codeCoverageIgnoreEnd
    }

    $question = new ConfirmationQuestion(sprintf('Replace %s with version %s? [y/N] ', $target, $tag), FALSE);

    return (bool) $helper->ask($input, $output, $question);
  }

  /**
   * Writes the verified PHAR beside the target and renames it into place.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param string $target
   *   The executable path to replace.
   * @param string $phar
   *   The verified PHAR bytes.
   * @param string $tag
   *   The release tag installed.
   *
   * @return int
   *   The exit code.
   */
  protected function swap(OutputInterface $output, string $target, string $phar, string $tag): int {
    $temp = $target . '.' . getmypid() . '.new';

    if (@file_put_contents($temp, $phar) === FALSE) {
      // @codeCoverageIgnoreStart
      $output->writeln(sprintf('ERROR could not write the new executable next to %s.', $target));

      return ExitCode::FAIL;
      // @codeCoverageIgnoreEnd
    }

    chmod($temp, 0755);
    rename($temp, $target);

    $output->writeln(sprintf('Updated skilltest to version %s.', $tag));

    return ExitCode::PASS;
  }

  /**
   * Extracts the expected PHAR checksum from a `sha256sum`-format file.
   *
   * The line naming the PHAR wins; a single-entry file with no name falls back
   * to its first field, matching the installer's own resolution order.
   *
   * @param string $checksums
   *   The checksums file contents.
   *
   * @return string|null
   *   The expected hex digest, or NULL when none can be read.
   */
  protected function expectedChecksum(string $checksums): ?string {
    $fallback = NULL;

    foreach (explode("\n", $checksums) as $line) {
      $fields = preg_split('/\s+/', trim($line)) ?: [];

      if (($fields[0] ?? '') === '') {
        continue;
      }

      $fallback ??= $fields[0];

      if (($fields[1] ?? '') === ReleaseClient::PHAR_NAME) {
        return $fields[0];
      }
    }

    return $fallback;
  }

  /**
   * Whether a string looks like a comparable version.
   *
   * @param string $value
   *   The candidate.
   *
   * @return bool
   *   TRUE when it starts with a digit run, optionally `v`-prefixed.
   */
  protected function isVersion(string $value): bool {
    return preg_match('/^v?\d+(\.\d+)*$/', $value) === 1;
  }

  /**
   * The default runtime-form resolver.
   *
   * @return \Closure(): string
   *   The resolver.
   */
  protected static function defaultRuntime(): \Closure {
    // @codeCoverageIgnoreStart
    return Version::runtime(...);
    // @codeCoverageIgnoreEnd
  }

  /**
   * The default executable-path resolver: the running PHAR file.
   *
   * @return \Closure(): string
   *   The resolver.
   */
  protected static function defaultExecutable(): \Closure {
    // @codeCoverageIgnoreStart
    return static fn(): string => \Phar::running(FALSE);
    // @codeCoverageIgnoreEnd
  }

}
