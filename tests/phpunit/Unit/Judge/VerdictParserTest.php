<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Judge;

use AlexSkrypnyk\SkillTest\Judge\JudgeCriterion;
use AlexSkrypnyk\SkillTest\Judge\JudgeException;
use AlexSkrypnyk\SkillTest\Judge\JudgeVerdict;
use AlexSkrypnyk\SkillTest\Judge\VerdictParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class VerdictParserTest.
 *
 * Unit test for the hardened judge-verdict parser, proven against committed
 * verdict fixtures and the noise a real model wraps its JSON in.
 */
#[CoversClass(VerdictParser::class)]
final class VerdictParserTest extends TestCase {

  public function testGoodVerdictParsesAllPassing(): void {
    $verdict = $this->parseFixture('good-verdict.json');

    $this->assertSame([[1, TRUE, FALSE], [2, TRUE, FALSE], [3, TRUE, FALSE]], $this->flatten($verdict->criteria));
    $this->assertStringContainsString('invents no paths', $verdict->reasoning);
  }

  public function testFailingVerdictParsesPerCriterion(): void {
    $verdict = $this->parseFixture('failing-verdict.json');

    $this->assertSame([[1, TRUE, FALSE], [2, FALSE, FALSE], [3, FALSE, FALSE]], $this->flatten($verdict->criteria));
  }

  public function testAbstainVerdictReportsUnknownDistinctly(): void {
    $verdict = $this->parseFixture('abstain-verdict.json');

    $this->assertSame([[1, TRUE, FALSE], [2, FALSE, TRUE]], $this->flatten($verdict->criteria));
    $this->assertSame(1, $verdict->unknowns());
  }

  public function testFencedVerdictIsExtractedFromProse(): void {
    $verdict = $this->parseFixture('fenced-verdict.json');

    $this->assertSame([[1, TRUE, FALSE], [2, TRUE, FALSE]], $this->flatten($verdict->criteria));
    $this->assertSame('Both criteria hold.', $verdict->reasoning);
  }

  public function testMalformedFixtureFailsTheJudge(): void {
    $this->expectException(JudgeException::class);

    $this->parseFixture('malformed-verdict.json');
  }

  #[DataProvider('dataProviderParsesHardenedInput')]
  public function testParsesHardenedInput(string $raw, array $expected): void {
    $this->assertSame($expected, $this->flatten((new VerdictParser())->parse($raw)->criteria));
  }

  public static function dataProviderParsesHardenedInput(): \Iterator {
    yield 'id falls back to position' => ['{"criteria":[{"pass":true},{"pass":false}]}', [[1, TRUE, FALSE], [2, FALSE, FALSE]]];
    yield 'string booleans are clamped' => ['{"criteria":[{"id":1,"pass":"true"},{"id":2,"pass":"false"}]}', [[1, TRUE, FALSE], [2, FALSE, FALSE]]];
    yield 'top-level unknown marks every criterion' => ['{"criteria":[{"id":1,"pass":true}],"unknown":true}', [[1, FALSE, TRUE]]];
    yield 'per-criterion unknown forces pass false' => ['{"criteria":[{"id":1,"pass":true,"unknown":true}]}', [[1, FALSE, TRUE]]];
    yield 'trailing noise is tolerated' => ['{"criteria":[{"id":1,"pass":true}]} thanks!', [[1, TRUE, FALSE]]];
    yield 'leading prose is discarded' => ['Verdict: {"criteria":[{"id":1,"pass":true}]}', [[1, TRUE, FALSE]]];
    yield 'braces inside a string do not unbalance' => ['{"reasoning":"a } and { here","criteria":[{"id":1,"pass":true}]}', [[1, TRUE, FALSE]]];
    yield 'escaped quote inside a string is honoured' => ['{"reasoning":"a \" } brace","criteria":[{"id":1,"pass":true}]}', [[1, TRUE, FALSE]]];
    yield 'non-array entries are skipped' => ['{"criteria":["junk",{"id":1,"pass":true}]}', [[1, TRUE, FALSE]]];
  }

  public function testReasoningDefaultsToEmpty(): void {
    $this->assertSame('', (new VerdictParser())->parse('{"criteria":[{"id":1,"pass":true}]}')->reasoning);
  }

  #[DataProvider('dataProviderMalformedInputThrows')]
  public function testMalformedInputThrows(string $raw): void {
    $this->expectException(JudgeException::class);

    (new VerdictParser())->parse($raw);
  }

  public static function dataProviderMalformedInputThrows(): \Iterator {
    yield 'not json at all' => ['the transcript was truncated'];
    yield 'not an object' => ['[1, 2, 3]'];
    yield 'no criteria key' => ['{"reasoning":"ok"}'];
    yield 'criteria not an array' => ['{"criteria":"nope"}'];
    yield 'empty criteria' => ['{"criteria":[]}'];
    yield 'only non-array entries' => ['{"criteria":[42,"x"]}'];
    yield 'unbalanced object' => ['{"criteria":[{"id":1,'];
  }

  /**
   * Parses a committed verdict fixture.
   *
   * @param string $name
   *   The fixture filename under Fixtures/judge.
   *
   * @return \AlexSkrypnyk\SkillTest\Judge\JudgeVerdict
   *   The parsed verdict.
   */
  protected function parseFixture(string $name): JudgeVerdict {
    return (new VerdictParser())->parse((string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/judge/' . $name));
  }

  /**
   * Flattens criteria to `[id, pass, unknown]` triples for comparison.
   *
   * @param \AlexSkrypnyk\SkillTest\Judge\JudgeCriterion[] $criteria
   *   The criteria.
   *
   * @return array<int, array{int, bool, bool}>
   *   The flattened triples.
   */
  protected function flatten(array $criteria): array {
    return array_map(static fn(JudgeCriterion $criterion): array => [$criterion->id, $criterion->pass, $criterion->unknown], $criteria);
  }

}
