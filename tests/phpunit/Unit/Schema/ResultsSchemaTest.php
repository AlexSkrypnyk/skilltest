<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Schema;

use AlexSkrypnyk\SkillTest\Tests\Traits\SchemaValidationTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class ResultsSchemaTest.
 *
 * Guards the committed results JSON Schema: a deterministic document and an
 * llm-shaped document both conform, and malformed documents are rejected, so
 * the schema is a real contract rather than a rubber stamp.
 */
#[CoversNothing]
final class ResultsSchemaTest extends TestCase {

  use SchemaValidationTrait;

  public function testCommittedLlmFixtureMatchesSchema(): void {
    $json = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/results-llm.json');

    $this->assertMatchesResultsSchema($json);
  }

  public function testDeterministicDocumentMatchesSchema(): void {
    $this->assertMatchesResultsSchema((string) json_encode(self::deterministicDocument()));
  }

  #[DataProvider('dataProviderMalformedDocuments')]
  public function testMalformedDocumentsAreRejected(callable $mutate): void {
    $document = self::deterministicDocument();
    $mutate($document);

    $this->assertNotSame([], $this->resultsSchemaErrors((string) json_encode($document)), 'Expected the schema to reject the malformed document.');
  }

  /**
   * Provides mutations that each break the deterministic document.
   *
   * @return array<string, array{callable}>
   *   Named mutators applied to a valid document by reference.
   */
  public static function dataProviderMalformedDocuments(): array {
    return [
      'missing version' => [static function (array &$document): void {
        unset($document['version']);
      }],
      'wrong major version' => [static function (array &$document): void {
        $document['version'] = '2';
      }],
      'non-integer duration' => [static function (array &$document): void {
        $document['run']['duration_ms'] = 'soon';
      }],
      'skills not an array' => [static function (array &$document): void {
        $document['skills'] = 'none';
      }],
      'check missing pass verdict' => [static function (array &$document): void {
        unset($document['skills'][0]['deterministic']['structure'][0]['pass']);
      }],
      'totals missing tokens' => [static function (array &$document): void {
        unset($document['totals']['tokens']);
      }],
      'negative failure count' => [static function (array &$document): void {
        $document['totals']['failures'] = -1;
      }],
    ];
  }

  /**
   * Builds a minimal, valid deterministic results document.
   *
   * @return array<string, mixed>
   *   The results document.
   */
  protected static function deterministicDocument(): array {
    return [
      'version' => '1',
      'tool' => ['name' => 'skilltest', 'version' => 'development'],
      'run' => ['id' => 'st-20260709-0900', 'started' => '2026-07-09T09:00:00+00:00', 'duration_ms' => 12, 'command' => 'run', 'environment' => 'host'],
      'skills' => [
        [
          'skill' => 'alpha',
          'path' => 'skills/alpha',
          'deterministic' => [
            'structure' => [['check' => 'structure.frontmatter', 'skill' => 'alpha', 'status' => 'pass', 'pass' => TRUE]],
            'security' => [],
            'transcript' => [['check' => 'contract.tools.required', 'label' => 'uses Bash', 'pass' => TRUE, 'evidence' => 'Bash', 'message' => '']],
          ],
        ],
      ],
      'hooks' => [['check' => 'hooks.guard', 'label' => '', 'pass' => TRUE, 'evidence' => '', 'message' => '']],
      'coverage' => ['violations' => []],
      'totals' => ['checks' => 3, 'failures' => 0, 'trials' => 0, 'tokens' => ['in' => 0, 'out' => 0], 'cost_usd' => 0.0],
    ];
  }

}
