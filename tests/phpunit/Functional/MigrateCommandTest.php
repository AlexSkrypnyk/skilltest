<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\PhpunitHelpers\Traits\ApplicationTrait;
use AlexSkrypnyk\SkillTest\Command\MigrateCommand;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Class MigrateCommandTest.
 *
 * Functional test for the migrate command: a current-major file is reported
 * unchanged, an older-major file is rewritten, and missing, malformed, or
 * newer-major files are configuration errors.
 */
#[CoversClass(MigrateCommand::class)]
#[Group('command')]
final class MigrateCommandTest extends TestCase {

  use ApplicationTrait;

  /**
   * The virtual filesystem root each test writes fixtures under.
   */
  protected string $root = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->root = vfsStream::setup('root')->url();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->applicationTearDown();

    parent::tearDown();
  }

  public function testCurrentMajorFileIsReportedUnchanged(): void {
    $file = $this->write('eval.yaml', "version: \"1\"\nskill: demo\n");

    $output = $this->runMigrate($file, 0);

    $this->assertStringContainsString('already at the current schema (major 1)', $output);
  }

  public function testOlderMajorFileIsMigrated(): void {
    $file = $this->write('eval.yaml', "version: \"0\"\nskill: demo\n");

    $output = $this->runMigrate($file, 0);

    $this->assertStringContainsString('migrated from schema 0.0 to 1', $output);
    $parsed = Yaml::parse((string) file_get_contents($file));
    $version = is_array($parsed) ? ($parsed['version'] ?? NULL) : NULL;
    $this->assertSame('1', $version);
  }

  public function testNewerMajorFileIsError(): void {
    $file = $this->write('eval.yaml', "version: \"2\"\nskill: demo\n");

    $output = $this->runMigrate($file, 2);

    $this->assertStringContainsString('newer than this tool supports', $output);
  }

  public function testMissingFileIsError(): void {
    $output = $this->runMigrate($this->root . '/absent.yaml', 2);

    $this->assertStringContainsString('file not found', $output);
  }

  public function testEmptyFileArgumentIsError(): void {
    $output = $this->runMigrate('', 2);

    $this->assertStringContainsString('migrate expects a file path', $output);
  }

  /**
   * Writes a fixture file under the virtual root.
   *
   * @param string $name
   *   The file name.
   * @param string $contents
   *   The file contents.
   *
   * @return string
   *   The file URL.
   */
  protected function write(string $name, string $contents): string {
    $file = $this->root . '/' . $name;
    file_put_contents($file, $contents);

    return $file;
  }

  /**
   * Runs the migrate command and asserts the exit code.
   *
   * @param string $file
   *   The file argument.
   * @param int $expected_exit
   *   The expected exit code.
   *
   * @return string
   *   The command output.
   */
  protected function runMigrate(string $file, int $expected_exit): string {
    $this->applicationInitFromCommand(MigrateCommand::class);
    $this->applicationRun(['file' => $file], [], $expected_exit !== 0);

    $this->assertSame($expected_exit, $this->applicationGetTester()->getStatusCode());

    return $this->applicationGetTester()->getDisplay();
  }

}
