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

  #[DataProvider('dataProviderMalformedDocumentsAreRejected')]
  public function testMalformedDocumentsAreRejected(array $document): void {
    $this->assertNotSame([], $this->resultsSchemaErrors((string) json_encode($document)), 'Expected the schema to reject the malformed document.');
  }

  /**
   * Provides documents that each break the schema in exactly one way.
   *
   * Each case overrides or drops one part of the valid document via top-level
   * array operations, so the fixtures stay derived from one source of truth
   * without mutating nested offsets.
   *
   * @return \Iterator<string, array{array<string, mixed>}>
   *   Named malformed documents.
   */
  public static function dataProviderMalformedDocumentsAreRejected(): \Iterator {
    $base = self::deterministicDocument();

    yield 'missing version' => [array_diff_key($base, ['version' => 0])];
    yield 'wrong major version' => [[...$base, 'version' => '2']];
    yield 'non-integer duration' => [[...$base, 'run' => ['id' => 'st-x', 'started' => 'now', 'duration_ms' => 'soon', 'command' => 'run', 'environment' => 'host']]];
    yield 'skills not an array' => [[...$base, 'skills' => 'none']];
    yield 'check missing pass verdict' => [[...$base, 'skills' => [['skill' => 'alpha', 'deterministic' => ['structure' => [['check' => 'structure.frontmatter']]]]]]];
    yield 'totals missing tokens' => [[...$base, 'totals' => ['checks' => 3, 'failures' => 0, 'trials' => 0, 'cost_usd' => 0.0]]];
    yield 'negative failure count' => [[...$base, 'totals' => ['checks' => 3, 'failures' => -1, 'trials' => 0, 'tokens' => ['in' => 0, 'out' => 0], 'cost_usd' => 0.0]]];
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
