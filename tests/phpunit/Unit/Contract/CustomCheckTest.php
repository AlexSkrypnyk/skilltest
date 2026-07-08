<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Contract;

use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Contract\CustomCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class CustomCheckTest.
 *
 * Unit test for the custom check runner, with an injected process runner.
 */
#[CoversClass(CustomCheck::class)]
final class CustomCheckTest extends TestCase {

  public function testPassesTranscriptAndSkillDirAsArgumentsInRepoRoot(): void {
    $captured = [];
    $runner = function (string $command, string $cwd) use (&$captured): array {
      $captured = ['command' => $command, 'cwd' => $cwd];

      return [0, ''];
    };

    (new CustomCheck('/repo/root', $runner))->run(
      ['name' => 'board', 'run' => 'php tests/checks/board.php'],
      '/tmpdir/transcript.jsonl',
      'skills/foo',
    );

    $this->assertSame('/repo/root', $captured['cwd']);
    $this->assertStringStartsWith('php tests/checks/board.php ', $captured['command']);
    $this->assertStringContainsString(escapeshellarg('/tmpdir/transcript.jsonl'), $captured['command']);
    $this->assertStringContainsString(escapeshellarg('skills/foo'), $captured['command']);
  }

  #[DataProvider('dataProviderVerdict')]
  public function testVerdict(int $exit, string $stdout, bool $pass, string $evidence, string $message_contains): void {
    $runner = fn(string $command, string $cwd): array => [$exit, $stdout];

    $result = (new CustomCheck('/root', $runner))->run(['name' => 'c', 'run' => 'php c.php'], '/t.jsonl', 'skills/foo');

    $this->assertInstanceOf(CheckResult::class, $result);
    $this->assertSame('check.c', $result->id);
    $this->assertSame('c', $result->label);
    $this->assertSame($pass, $result->pass);
    $this->assertSame($evidence, $result->evidence);
    $this->assertStringContainsString($message_contains, $result->message);
  }

  public static function dataProviderVerdict(): \Iterator {
    yield 'exit zero passes' => [0, '', TRUE, '', "custom check 'c' passed."];
    yield 'nonzero exit fails with code' => [1, '', FALSE, '', "custom check 'c' failed (exit 1)."];
    yield 'json overrides exit to pass' => [1, '{"pass":true}', TRUE, '', "custom check 'c' passed."];
    yield 'json overrides exit to fail' => [0, '{"pass":false}', FALSE, '', "custom check 'c' failed (exit 0)."];
    yield 'json message and evidence enrich' => [0, '{"pass":true,"message":"looks good","evidence":"gh pr view 1"}', TRUE, 'gh pr view 1', 'looks good'];
    yield 'json evidence without pass keeps exit verdict' => [1, '{"evidence":"offending cmd"}', FALSE, 'offending cmd', 'failed (exit 1)'];
    yield 'malformed json falls back to exit code' => [0, '{not valid', TRUE, '', 'passed.'];
    yield 'non-object json falls back to exit code' => [3, '42', FALSE, '', 'failed (exit 3).'];
    yield 'non-bool pass is ignored' => [0, '{"pass":"yes"}', TRUE, '', 'passed.'];
    yield 'whitespace stdout falls back to exit code' => [0, "  \n ", TRUE, '', 'passed.'];
  }

  #[DataProvider('dataProviderMalformedEntryYieldsNull')]
  public function testMalformedEntryYieldsNull(array $check): void {
    $runner = fn(string $command, string $cwd): array => [0, ''];

    $this->assertNull((new CustomCheck('/root', $runner))->run($check, '/t.jsonl', 'skills/foo'));
  }

  public static function dataProviderMalformedEntryYieldsNull(): \Iterator {
    yield 'missing run' => [['name' => 'c']];
    yield 'missing name' => [['run' => 'php c.php']];
    yield 'empty run' => [['name' => 'c', 'run' => '']];
    yield 'empty name' => [['name' => '', 'run' => 'php c.php']];
    yield 'no keys' => [[]];
  }

}
