<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Run\Report;

use AlexSkrypnyk\SkillTest\Run\Report\JUnitReporter;
use AlexSkrypnyk\SkillTest\Tests\Traits\JunitSchemaValidationTrait;
use AlexSkrypnyk\SkillTest\Tests\Traits\ResultsDocumentTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class JUnitReporterTest.
 *
 * Unit test for rendering a results document as schema-valid JUnit XML.
 */
#[CoversClass(JUnitReporter::class)]
final class JUnitReporterTest extends TestCase {

  use JunitSchemaValidationTrait;
  use ResultsDocumentTrait;

  public function testRendersSkillHookAndCoverageSuites(): void {
    $document = $this->document(
      [$this->skill('alpha', [$this->check('structure.frontmatter', TRUE)], [], [$this->check('contract.commands.forbidden', FALSE, 'no git pushes', 'git push origin main', "forbidden behaviour 'no git pushes' matched")])],
      [$this->check('hooks.guard', TRUE, 'blocks push', '{}'), $this->check('hooks.guard', FALSE, 'blocks push', '{}', 'allowed but must block')],
      [$this->check('coverage.eval-exists', FALSE, '', '', "skill 'gamma' has no eval.yaml")],
    );

    $xml = (new JUnitReporter())->render($document);
    $this->assertMatchesJunitSchema($xml);

    $suites = simplexml_load_string($xml);
    if ($suites === FALSE) {
      $this->fail('JUnit output did not parse.');
    }

    $this->assertSame('skilltest', (string) $suites['name']);
    $this->assertSame('5', (string) $suites['tests']);
    $this->assertSame('3', (string) $suites['failures']);

    $alpha = $this->suite($suites, 'alpha');
    $this->assertSame('2', (string) $alpha['tests']);
    $this->assertSame('1', (string) $alpha['failures']);
    $this->assertSame('alpha.structure', (string) $alpha->testcase[0]['classname']);

    $hooks = $this->suite($suites, 'hooks');
    $this->assertSame('2', (string) $hooks['tests']);
    $this->assertSame('1', (string) $hooks['failures']);

    $coverage = $this->suite($suites, 'coverage');
    $this->assertSame('1', (string) $coverage['tests']);
  }

  public function testFailureCarriesCheckIdLabelAndEvidence(): void {
    $document = $this->document([$this->skill('alpha', [], [], [$this->check('contract.commands.forbidden', FALSE, 'no git pushes', 'git push origin main', 'forbidden behaviour matched')])]);

    $xml = (new JUnitReporter())->render($document);

    $this->assertStringContainsString('message="forbidden behaviour matched"', $xml);
    $this->assertStringContainsString('check: contract.commands.forbidden', $xml);
    $this->assertStringContainsString('label: no git pushes', $xml);
    $this->assertStringContainsString('evidence: git push origin main', $xml);
    $this->assertStringContainsString('message: forbidden behaviour matched', $xml);
  }

  public function testFailureMessageFallsBackToLabelThenId(): void {
    $document = $this->document([$this->skill('alpha', [$this->check('structure.name', FALSE, 'name matches dir')], [], [$this->check('security.curl', FALSE)])]);

    $xml = (new JUnitReporter())->render($document);

    $this->assertStringContainsString('message="name matches dir"', $xml);
    $this->assertStringContainsString('message="security.curl"', $xml);
  }

  public function testRendersLlmTrialsAsTestCases(): void {
    $llm = [
      'tasks' => [
        [
          'task' => 'invoked',
          'models' => [
            [
              'model' => 'claude-haiku-4-5',
              'alias' => 'haiku',
              'trials' => [
                ['trial' => 1, 'pass' => TRUE, 'duration_ms' => 4200],
                ['trial' => 2, 'pass' => FALSE, 'contract' => [['check' => 'contract.tools.required', 'pass' => FALSE]], 'judge' => [['criterion' => 1, 'pass' => TRUE], ['criterion' => 2, 'pass' => FALSE]]],
              ],
            ],
          ],
        ],
      ],
      'verdict' => ['minimal_model' => 'sonnet', 'threshold' => 0.8, 'trials' => 3],
    ];
    $document = $this->document([$this->skill('alpha', [], [], [], $llm)]);

    $xml = (new JUnitReporter())->render($document);
    $this->assertMatchesJunitSchema($xml);

    $alpha = $this->suite(simplexml_load_string($xml) ?: NULL, 'alpha');
    $this->assertSame('2', (string) $alpha['tests']);
    $this->assertSame('1', (string) $alpha['failures']);
    $this->assertStringContainsString('name="invoked.haiku.trial-1"', $xml);
    $this->assertStringContainsString('name="invoked.haiku.trial-2"', $xml);
    $this->assertStringContainsString('classname="alpha.llm"', $xml);
    $this->assertStringContainsString('failed: contract contract.tools.required; judge criteria 2', $xml);
  }

  public function testSkillWithoutChecksProducesNoSuite(): void {
    $document = $this->document([$this->skill('beta')]);

    $xml = (new JUnitReporter())->render($document);
    $this->assertMatchesJunitSchema($xml);

    $suites = simplexml_load_string($xml);
    if ($suites === FALSE) {
      $this->fail('JUnit output did not parse.');
    }

    $this->assertCount(0, $suites->xpath('//testsuite') ?: []);
    $this->assertSame('0', (string) $suites['tests']);
  }

  public function testStripsXmlIllegalCharactersFromEvidence(): void {
    $document = $this->document([$this->skill('alpha', [], [], [$this->check('contract.x', FALSE, 'lbl', "bad\x00\x08value", 'msg')])]);

    $xml = (new JUnitReporter())->render($document);
    $this->assertMatchesJunitSchema($xml);
    $this->assertStringContainsString('evidence: badvalue', $xml);
  }

  public function testMissingToolNameDefaultsToSkilltest(): void {
    $document = $this->document();
    unset($document['tool']);

    $xml = (new JUnitReporter())->render($document);

    $this->assertStringContainsString('name="skilltest"', $xml);
  }

  /**
   * Finds a test suite by name.
   *
   * @param \SimpleXMLElement|null $suites
   *   The parsed testsuites root.
   * @param string $name
   *   The suite name to find.
   *
   * @return \SimpleXMLElement
   *   The matching suite.
   */
  protected function suite(?\SimpleXMLElement $suites, string $name): \SimpleXMLElement {
    $matches = $suites instanceof \SimpleXMLElement ? ($suites->xpath(sprintf("//testsuite[@name='%s']", $name)) ?: []) : [];

    if (!isset($matches[0]) || !$matches[0] instanceof \SimpleXMLElement) {
      $this->fail(sprintf('No testsuite named "%s".', $name));
    }

    return $matches[0];
  }

}
