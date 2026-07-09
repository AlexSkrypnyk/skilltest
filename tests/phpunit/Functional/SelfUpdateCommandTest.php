<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\PhpunitHelpers\Traits\ApplicationTrait;
use AlexSkrypnyk\SkillTest\Command\SelfUpdateCommand;
use AlexSkrypnyk\SkillTest\Update\ReleaseClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class SelfUpdateCommandTest.
 *
 * Functional test for the self-update command: the refusal from source, the
 * up-to-date short-circuit, the checksum-mismatch refusal that touches nothing,
 * and the confirmed and cancelled swaps.
 */
#[CoversClass(SelfUpdateCommand::class)]
#[Group('command')]
final class SelfUpdateCommandTest extends TestCase {

  use ApplicationTrait;

  /**
   * The old executable content every swap test starts from.
   */
  protected const string OLD = "#!/usr/bin/env php\nOLD-PHAR\n";

  /**
   * The new PHAR bytes a successful update installs.
   */
  protected const string NEW = "#!/usr/bin/env php\nNEW-PHAR\n";

  /**
   * The temporary executable file the command replaces.
   */
  protected string $exe = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->exe = dirname(__DIR__, 3) . '/.artifacts/tmp/selfupdate-' . getmypid() . '-' . uniqid();
    mkdir(dirname($this->exe), 0777, TRUE);
    file_put_contents($this->exe, self::OLD);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach (glob($this->exe . '*') ?: [] as $path) {
      unlink($path);
    }

    $this->applicationTearDown();

    parent::tearDown();
  }

  public function testRefusesFromSource(): void {
    $output = $this->runSelfUpdate(['runtime' => 'source'], 2);

    $this->assertStringContainsString('you are running from source', $output);
  }

  public function testRefusesWhenCurrentVersionIsUnparseable(): void {
    $output = $this->runSelfUpdate(['current' => 'development'], 2);

    $this->assertStringContainsString('cannot determine the current version', $output);
  }

  public function testErrorsWhenLatestTagCannotBeRead(): void {
    $output = $this->runSelfUpdate(['tagStatus' => 404], 1);

    $this->assertStringContainsString('could not determine the latest release', $output);
  }

  public function testReportsAlreadyUpToDate(): void {
    $output = $this->runSelfUpdate(['current' => '2.0.0', 'tag' => '2.0.0'], 0);

    $this->assertStringContainsString('Already up to date (version 2.0.0)', $output);
    $this->assertSame(self::OLD, (string) file_get_contents($this->exe));
  }

  public function testErrorsWhenAssetDownloadFails(): void {
    $output = $this->runSelfUpdate(['pharStatus' => 404], 1);

    $this->assertStringContainsString('could not download the release assets', $output);
  }

  public function testRefusesOnChecksumMismatchAndTouchesNothing(): void {
    $output = $this->runSelfUpdate(['sums' => 'deadbeef  ' . ReleaseClient::PHAR_NAME, '--yes' => TRUE], 1);

    $this->assertStringContainsString('checksum verification failed', $output);
    $this->assertStringContainsString('refusing to replace the executable', $output);
    $this->assertSame(self::OLD, (string) file_get_contents($this->exe));
    $this->assertSame([$this->exe], glob($this->exe . '*'), 'no partial .new file is left behind');
  }

  public function testRefusesWhenChecksumsFileHasNoUsableEntry(): void {
    $output = $this->runSelfUpdate(['sums' => "\n  \n", '--yes' => TRUE], 1);

    $this->assertStringContainsString('expected <none>', $output);
    $this->assertSame(self::OLD, (string) file_get_contents($this->exe));
  }

  public function testUpdatesWithYesFlag(): void {
    $output = $this->runSelfUpdate(['--yes' => TRUE], 0);

    $this->assertStringContainsString('Updated skilltest to version 2.0.0', $output);
    $this->assertSame(self::NEW, (string) file_get_contents($this->exe));
  }

  public function testUpdatesWithBareChecksumFallback(): void {
    $output = $this->runSelfUpdate(['sums' => hash('sha256', self::NEW), '--yes' => TRUE], 0);

    $this->assertStringContainsString('Updated skilltest to version 2.0.0', $output);
    $this->assertSame(self::NEW, (string) file_get_contents($this->exe));
  }

  public function testInteractiveConfirmSwaps(): void {
    $output = $this->runSelfUpdate([], 0, ['y']);

    $this->assertStringContainsString('Updated skilltest to version 2.0.0', $output);
    $this->assertSame(self::NEW, (string) file_get_contents($this->exe));
  }

  public function testInteractiveDeclineCancels(): void {
    $output = $this->runSelfUpdate([], 0, ['n']);

    $this->assertStringContainsString('Update cancelled', $output);
    $this->assertSame(self::OLD, (string) file_get_contents($this->exe));
  }

  /**
   * Runs the command with the given overrides and asserts the exit code.
   *
   * @param array<string, mixed> $opts
   *   Overrides: runtime, current, tag, tagStatus, pharStatus, pharBytes,
   *   sumStatus, sums, and `--yes` (a command option).
   * @param int $expected_exit
   *   The expected exit code.
   * @param array<int, string> $inputs
   *   Interactive answers fed to the confirmation prompt.
   *
   * @return string
   *   The command output.
   */
  protected function runSelfUpdate(array $opts, int $expected_exit, array $inputs = []): string {
    $opts += [
      'runtime' => 'phar',
      'current' => '1.0.0',
      'tag' => '2.0.0',
      'tagStatus' => 200,
      'pharStatus' => 200,
      'pharBytes' => self::NEW,
      'sumStatus' => 200,
      'sums' => hash('sha256', self::NEW) . '  ' . ReleaseClient::PHAR_NAME,
    ];

    $runtime = is_string($opts['runtime']) ? $opts['runtime'] : 'phar';
    $current = is_string($opts['current']) ? $opts['current'] : '1.0.0';

    $command = new SelfUpdateCommand(
      $this->fetcher($opts),
      static fn(): string => $runtime,
      fn(): string => $this->exe,
      $current,
      ReleaseClient::DEFAULT_REPO,
    );

    $this->applicationInitFromCommand($command);

    if ($inputs !== []) {
      $this->applicationGetTester()->setInputs(array_values($inputs));
    }

    $input = isset($opts['--yes']) ? ['--yes' => TRUE] : [];
    $this->applicationRun($input, [], $expected_exit !== 0);

    $this->assertSame($expected_exit, $this->applicationGetTester()->getStatusCode());

    return $this->applicationGetTester()->getDisplay();
  }

  /**
   * Builds a fetcher mapping the API and asset URLs to canned responses.
   *
   * @param array<string, mixed> $opts
   *   The resolved options.
   *
   * @return \Closure
   *   The fetcher closure.
   */
  protected function fetcher(array $opts): \Closure {
    return static function (string $url) use ($opts): array {
      if (str_contains($url, 'api.github.com')) {
        return [$opts['tagStatus'], json_encode(['tag_name' => $opts['tag']], JSON_THROW_ON_ERROR)];
      }

      if (str_ends_with($url, '.sha256')) {
        return [$opts['sumStatus'], $opts['sums']];
      }

      return [$opts['pharStatus'], $opts['pharBytes']];
    };
  }

}
