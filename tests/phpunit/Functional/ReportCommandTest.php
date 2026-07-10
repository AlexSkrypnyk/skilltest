<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\PhpunitHelpers\Traits\ApplicationTrait;
use AlexSkrypnyk\SkillTest\Command\ReportCommand;
use AlexSkrypnyk\SkillTest\Tests\Traits\ResultsDocumentTrait;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class ReportCommandTest.
 *
 * Functional test for the report command: the terminal summary, the
 * self-contained HTML report (grid, drill-down, and no external request), the
 * plain-language interpretation, and the file-error surface.
 */
#[CoversClass(ReportCommand::class)]
#[Group('command')]
final class ReportCommandTest extends TestCase {

  use ApplicationTrait;
  use ResultsDocumentTrait;

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

  public function testTerminalSummaryRendersStatusAndCounts(): void {
    $file = $this->writeResults('results.json', $this->document([$this->skill('alpha', [$this->check('structure.frontmatter', TRUE)])], [], [], ['checks' => 3, 'failures' => 0]));

    $output = $this->runReport(['file' => $file], 0);

    $this->assertStringContainsString('PASS - all 3 check(s) passed', $output);
    $this->assertStringContainsString('checks: 3', $output);
  }

  public function testHtmlOptionWritesSelfContainedReport(): void {
    $file = $this->writeResults('results.json', $this->document([
      $this->skill('alpha', [$this->check('structure.name', FALSE, 'name', 'skills/alpha', 'name mismatch')], [], [], $this->llm([
        ['task' => 'invoked', 'models' => [['model' => 'claude-haiku-4-5', 'alias' => 'haiku', 'pass_rate' => 0.33, 'trials' => [$this->trial(1, FALSE, ['cost_usd' => 0.02])]], ['model' => 'claude-sonnet-5', 'alias' => 'sonnet', 'pass_rate' => 1.0, 'trials' => [$this->trial(1, TRUE, ['cost_usd' => 0.05])]]]],
      ], ['minimal_model' => 'sonnet', 'threshold' => 0.8, 'trials' => 3])),
    ], [], [], ['checks' => 3, 'failures' => 1, 'trials' => 2]));

    $target = $this->root . '/report.html';
    $output = $this->runReport(['file' => $file, '--html' => $target], 0);

    $this->assertStringContainsString('report written to ' . $target, $output);
    $this->assertFileExists($target);

    $html = (string) file_get_contents($target);
    $this->assertStringStartsWith('<!doctype html>', $html);
    $this->assertStringContainsString('<h2>Matrix</h2>', $html);
    $this->assertStringContainsString('<details open><summary>alpha', $html);
    $this->assertStringContainsString('name mismatch [skills/alpha]', $html);
    $this->assertStringNotContainsString('http://', $html);
    $this->assertStringNotContainsString('https://', $html);
    $this->assertStringNotContainsString('src=', $html);
    $this->assertStringNotContainsString('<script', $html);
  }

  public function testInterpretNamesTheTopFailureAndNextStep(): void {
    $file = $this->writeResults('results.json', $this->document([
      $this->skill('alpha', [], [], [$this->check('contract.commands.required', FALSE, 'runs build', '', 'missing required command')]),
    ], [], [], ['checks' => 1, 'failures' => 1]));

    $output = $this->runReport(['file' => $file, '--interpret' => TRUE], 0);

    $this->assertStringContainsString('1 of 1 check(s) failed.', $output);
    $this->assertStringContainsString('contract failure contract.commands.required', $output);
    $this->assertStringContainsString('skilltest record --skill alpha', $output);
  }

  public function testInterpretIsEmbeddedInHtml(): void {
    $file = $this->writeResults('results.json', $this->document([$this->skill('alpha', [$this->check('structure.frontmatter', TRUE)])], [], [], ['checks' => 1, 'failures' => 0]));

    $target = $this->root . '/report.html';
    $this->runReport(['file' => $file, '--html' => $target, '--interpret' => TRUE], 0);

    $html = (string) file_get_contents($target);
    $this->assertStringContainsString('class="interpret"', $html);
    $this->assertStringContainsString('All 1 check(s) passed', $html);
  }

  public function testMissingFileIsError(): void {
    $output = $this->runReport(['file' => $this->root . '/absent.json'], 2);

    $this->assertStringContainsString('results file not found', $output);
  }

  public function testEmptyFileArgumentIsError(): void {
    $output = $this->runReport(['file' => ''], 2);

    $this->assertStringContainsString('report expects a results file path', $output);
  }

  /**
   * Writes a results document to a fixture file under the virtual root.
   *
   * @param string $name
   *   The file name.
   * @param array<string, mixed> $document
   *   The results document.
   *
   * @return string
   *   The file URL.
   */
  protected function writeResults(string $name, array $document): string {
    $file = $this->root . '/' . $name;
    file_put_contents($file, json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    return $file;
  }

  /**
   * Runs the report command and asserts the exit code.
   *
   * @param array<string, string|bool> $input
   *   The command input.
   * @param int $expected_exit
   *   The expected exit code.
   *
   * @return string
   *   The combined command output.
   */
  protected function runReport(array $input, int $expected_exit): string {
    $this->applicationInitFromCommand(ReportCommand::class);
    $this->applicationRun($input, [], $expected_exit !== 0);

    $this->assertSame($expected_exit, $this->applicationGetTester()->getStatusCode());

    return $this->applicationGetTester()->getDisplay() . $this->applicationGetTester()->getErrorOutput();
  }

}
