<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Live\TrialWorkspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class TrialWorkspaceTest.
 *
 * Unit test for parsing and validating a task's inputs.
 */
#[CoversClass(TrialWorkspace::class)]
final class TrialWorkspaceTest extends TestCase {

  public function testParsesFullInputs(): void {
    $inputs = TrialWorkspace::parseInputs([
      'fixture' => 'fixtures/seed',
      'inputs' => [
        'repos' => [['source' => '.', 'commit' => 'main', 'dest' => 'workdir']],
        'workdir' => 'workdir',
      ],
    ], 'eval.yaml');

    $this->assertSame('fixtures/seed', $inputs['fixture']);
    $this->assertSame('workdir', $inputs['workdir']);
    $this->assertSame([['source' => '.', 'commit' => 'main', 'dest' => 'workdir']], $inputs['repos']);
  }

  public function testDefaultsCommitToHead(): void {
    $inputs = TrialWorkspace::parseInputs(['inputs' => ['repos' => [['source' => '.', 'dest' => 'sub']]]], 'eval.yaml');

    $this->assertSame(TrialWorkspace::DEFAULT_COMMIT, $inputs['repos'][0]['commit']);
  }

  public function testEmptyTaskYieldsEmptyInputs(): void {
    $inputs = TrialWorkspace::parseInputs([], 'eval.yaml');

    $this->assertNull($inputs['fixture']);
    $this->assertNull($inputs['workdir']);
    $this->assertSame([], $inputs['repos']);
  }

  public function testBlankWorkdirBecomesNull(): void {
    $inputs = TrialWorkspace::parseInputs(['inputs' => ['workdir' => '']], 'eval.yaml');

    $this->assertNull($inputs['workdir']);
  }

  #[DataProvider('dataProviderRejectsInvalidInputs')]
  public function testRejectsInvalidInputs(array $task, string $expected): void {
    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage($expected);

    TrialWorkspace::parseInputs($task, 'eval.yaml');
  }

  /**
   * Data provider for invalid inputs.
   *
   * @return \Iterator<string, array{array<mixed>, string}>
   *   The cases.
   */
  public static function dataProviderRejectsInvalidInputs(): \Iterator {
    yield 'repo without source' => [
      ['inputs' => ['repos' => [['dest' => 'sub']]]],
      "a repos entry requires a 'source'.",
    ];
    yield 'repo without dest' => [
      ['inputs' => ['repos' => [['source' => '.']]]],
      "a repos entry requires a 'dest'.",
    ];
    yield 'dest with parent segment' => [
      ['inputs' => ['repos' => [['source' => '.', 'dest' => '../escape']]]],
      'must be a relative path without a ".." segment',
    ];
    yield 'absolute dest' => [
      ['inputs' => ['repos' => [['source' => '.', 'dest' => '/etc']]]],
      'must be a relative path without a ".." segment',
    ];
    yield 'workdir with parent segment' => [
      ['inputs' => ['workdir' => 'a/../..']],
      'must be a relative path without a ".." segment',
    ];
  }

}
