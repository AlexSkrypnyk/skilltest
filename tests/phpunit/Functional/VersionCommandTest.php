<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\PhpunitHelpers\Traits\ApplicationTrait;
use AlexSkrypnyk\SkillTest\Command\VersionCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class VersionCommandTest.
 *
 * Functional test for the version command.
 */
#[CoversClass(VersionCommand::class)]
#[Group('command')]
final class VersionCommandTest extends TestCase {

  use ApplicationTrait;

  #[DataProvider('dataProviderExecute')]
  public function testExecute(string $expected): void {
    $this->applicationInitFromCommand(VersionCommand::class);

    $output = $this->applicationRun();

    $this->assertStringContainsString($expected, $output);
  }

  public static function dataProviderExecute(): \Iterator {
    yield 'version line' => ['skilltest development'];
    yield 'config schema' => ['Config schema:  1'];
    yield 'results schema' => ['Results schema: 1'];
    yield 'build info' => ['PHP:            ' . PHP_VERSION . ' (source)'];
  }

  public function testExecuteJson(): void {
    $this->applicationInitFromCommand(VersionCommand::class);

    $output = $this->applicationRun(['--json' => TRUE]);

    $expected = [
      'tool' => ['name' => 'skilltest', 'version' => 'development'],
      'schemas' => ['config' => '1', 'results' => '1'],
      'build' => ['php' => PHP_VERSION, 'runtime' => 'source'],
    ];
    $this->assertSame($expected, json_decode(trim($output), TRUE));
  }

}
