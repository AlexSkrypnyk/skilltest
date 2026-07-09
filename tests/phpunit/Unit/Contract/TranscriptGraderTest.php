<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Contract;

use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Contract\TranscriptGrader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class TranscriptGraderTest.
 *
 * Unit test for the shared transcript grading kernel: contract assertions
 * followed by custom-check scripts, graded against a transcript file, with an
 * injected check runner so no process is spawned.
 */
#[CoversClass(TranscriptGrader::class)]
final class TranscriptGraderTest extends TestCase {

  /**
   * The transcript file each test grades.
   */
  protected string $transcript = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $dir = dirname(__DIR__, 3) . '/.artifacts/tmp/transcriptgrader-' . getmypid() . '-' . uniqid();
    mkdir($dir, 0777, TRUE);
    $this->transcript = $dir . '/transcript.jsonl';
    file_put_contents($this->transcript, '{"type":"tool_use","name":"Bash","input":{"command":"harness build"}}' . "\n");
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->transcript !== '' && is_file($this->transcript)) {
      unlink($this->transcript);
      rmdir(dirname($this->transcript));
    }

    parent::tearDown();
  }

  public function testGradesContractOnlyWhenNoChecksDeclared(): void {
    $contract = ['tools' => ['required' => ['Bash']]];

    $results = (new TranscriptGrader('/root'))->grade($this->transcript, $contract, [], '/root/skills/alpha');

    $this->assertCount(1, $results);
    $this->assertSame('contract.tools.required', $results[0]->id);
    $this->assertTrue($results[0]->pass);
  }

  public function testAppliesAliasesToCommandMatching(): void {
    $contract = ['commands' => ['required' => ['builds' => '\\bharness\\s+build\\b']]];

    $results = (new TranscriptGrader('/root', ['harness' => '\\bharness\\b']))->grade($this->transcript, $contract, [], '/root/skills/alpha');

    $this->assertCount(1, $results);
    $this->assertTrue($results[0]->pass);
    $this->assertSame('harness build', $results[0]->evidence);
  }

  public function testCustomChecksFollowContractInOrderWithSkillDirArgument(): void {
    $captured = [];
    $runner = function (string $command, string $cwd) use (&$captured): array {
      $captured[] = $command;

      return [0, ''];
    };
    $contract = ['tools' => ['required' => ['Bash']]];
    $checks = [['name' => 'board', 'run' => 'php check.php']];

    $results = (new TranscriptGrader('/root', [], $runner))->grade($this->transcript, $contract, $checks, '/root/skills/alpha');

    $this->assertCount(2, $results);
    $this->assertSame('contract.tools.required', $results[0]->id);
    $this->assertSame('check.board', $results[1]->id);
    $this->assertTrue($results[1]->pass);
    $this->assertStringContainsString(escapeshellarg($this->transcript), $captured[0]);
    $this->assertStringContainsString(escapeshellarg('/root/skills/alpha'), $captured[0]);
  }

  public function testFailingCustomCheckSurfacesAsFailure(): void {
    $runner = fn(string $command, string $cwd): array => [1, ''];
    $checks = [['name' => 'board', 'run' => 'php check.php']];

    $results = (new TranscriptGrader('/root', [], $runner))->grade($this->transcript, [], $checks, '/root/skills/alpha');

    $this->assertCount(1, $results);
    $this->assertInstanceOf(CheckResult::class, $results[0]);
    $this->assertFalse($results[0]->pass);
  }

  public function testMissingTranscriptGradesAsEmptyRun(): void {
    $contract = ['tools' => ['required' => ['Bash']]];

    $results = (new TranscriptGrader('/root'))->grade('/does/not/exist.jsonl', $contract, [], '/root/skills/alpha');

    $this->assertCount(1, $results);
    $this->assertFalse($results[0]->pass);
  }

}
