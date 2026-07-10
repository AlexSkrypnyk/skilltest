<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\PhpunitHelpers\Traits\ApplicationTrait;
use AlexSkrypnyk\SkillTest\Command\CompareCommand;
use AlexSkrypnyk\SkillTest\Tests\Traits\ArrayPathTrait;
use AlexSkrypnyk\SkillTest\Tests\Traits\ResultsDocumentTrait;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class CompareCommandTest.
 *
 * Functional test for the compare command: two fixture results show the seeded
 * deltas in table and JSON forms, and a bad argument or an unreadable or
 * incompatible file is a configuration error.
 */
#[CoversClass(CompareCommand::class)]
#[Group('command')]
final class CompareCommandTest extends TestCase {

  use ApplicationTrait;
  use ArrayPathTrait;
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

  public function testTwoFixturesShowSeededDeltasAsTable(): void {
    $before = $this->writeResults('before.json', $this->document([$this->skill('alpha', [], [], [], $this->llm([$this->task('invoked', 'claude-haiku-4-5', 'haiku', [$this->trial(1, FALSE)], 0.0)]))], [], [], ['checks' => 2, 'failures' => 2]));
    $after = $this->writeResults('after.json', $this->document([$this->skill('alpha', [], [], [], $this->llm([$this->task('invoked', 'claude-haiku-4-5', 'haiku', [$this->trial(1, TRUE)], 1.0)]))], [], [], ['checks' => 2, 'failures' => 0]));

    $output = $this->runCompare(['files' => [$before, $after]], 0);

    $this->assertStringContainsString('compare: before -> after', $output);
    $this->assertMatchesRegularExpression('/failures +2 +0 +-2/', $output);
    $this->assertMatchesRegularExpression('/haiku pass_rate +0% +100% +\+100%/', $output);
  }

  public function testJsonFormatEmitsDeltas(): void {
    $before = $this->writeResults('before.json', $this->document([], [], [], ['checks' => 4, 'failures' => 2]));
    $after = $this->writeResults('after.json', $this->document([], [], [], ['checks' => 4, 'failures' => 0]));

    $output = $this->runCompare(['files' => [$before, $after], '--format' => 'json'], 0);

    $decoded = $this->decode($output);
    $this->assertTrue($this->path($decoded, 'compare'));
    $this->assertSame(['before', 'after'], $this->path($decoded, 'labels'));
    $this->assertSame(-2, $this->path($decoded, 'aggregate', 'failures', 'delta'));
    $this->assertEqualsWithDelta(0.5, $this->path($decoded, 'aggregate', 'pass_rate', 'delta'), PHP_FLOAT_EPSILON);
  }

  public function testCollidingBasenamesGetDistinctLabels(): void {
    mkdir($this->root . '/a');
    mkdir($this->root . '/b');
    $one = $this->writeResults('a/results.json', $this->document([], [], [], ['checks' => 2, 'failures' => 1]));
    $two = $this->writeResults('b/results.json', $this->document([], [], [], ['checks' => 2, 'failures' => 0]));

    $output = $this->runCompare(['files' => [$one, $two], '--format' => 'json'], 0);

    $decoded = $this->decode($output);
    $this->assertSame(['results', 'results#2'], $this->path($decoded, 'labels'));
  }

  public function testFewerThanTwoFilesIsError(): void {
    $only = $this->writeResults('only.json', $this->document());

    $output = $this->runCompare(['files' => [$only]], 2);

    $this->assertStringContainsString('at least two results files', $output);
  }

  public function testMissingFileIsError(): void {
    $present = $this->writeResults('present.json', $this->document());

    $output = $this->runCompare(['files' => [$present, $this->root . '/absent.json']], 2);

    $this->assertStringContainsString('results file not found', $output);
  }

  public function testIncompatibleMajorIsError(): void {
    $present = $this->writeResults('present.json', $this->document());
    $newer = $this->root . '/newer.json';
    file_put_contents($newer, '{"version": "2"}');

    $output = $this->runCompare(['files' => [$present, $newer], '--format' => 'json'], 2);

    $this->assertStringContainsString('skilltest migrate', $output);
  }

  public function testUnknownFormatIsError(): void {
    $before = $this->writeResults('before.json', $this->document());
    $after = $this->writeResults('after.json', $this->document());

    $output = $this->runCompare(['files' => [$before, $after], '--format' => 'xml'], 2);

    $this->assertStringContainsString('unknown format', $output);
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
   * Runs the compare command and asserts the exit code.
   *
   * The tester is driven directly rather than through `applicationRun()`
   * because the `files` argument takes an array value, which the trait's
   * string-or-bool input contract does not admit.
   *
   * @param array<string, string|bool|string[]> $input
   *   The command input.
   * @param int $expected_exit
   *   The expected exit code.
   *
   * @return string
   *   The combined command output.
   */
  protected function runCompare(array $input, int $expected_exit): string {
    $this->applicationInitFromCommand(CompareCommand::class);
    $this->applicationGetTester()->run($input, ['capture_stderr_separately' => TRUE]);

    $this->assertSame($expected_exit, $this->applicationGetTester()->getStatusCode());

    return $this->applicationGetTester()->getDisplay() . $this->applicationGetTester()->getErrorOutput();
  }

  /**
   * Decodes JSON output into an array, failing when it is not an object.
   *
   * @param string $json
   *   The JSON output.
   *
   * @return array<mixed>
   *   The decoded object.
   */
  protected function decode(string $json): array {
    $data = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);

    if (!is_array($data)) {
      $this->fail('Expected a JSON object.');
    }

    return $data;
  }

}
