<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Tokens;

use AlexSkrypnyk\SkillTest\Tokens\TokenDelta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class TokenDeltaTest.
 *
 * Unit test for the comparison row: novelty, delta, and growth derivation,
 * including the undefined-growth edge cases.
 */
#[CoversClass(TokenDelta::class)]
final class TokenDeltaTest extends TestCase {

  #[DataProvider('dataProviderDerivation')]
  public function testDerivation(int $tokens, ?int $ref_tokens, bool $is_new, int $delta, ?float $growth_pct): void {
    $row = new TokenDelta('skills/foo/SKILL.md', $tokens, $ref_tokens);

    $this->assertSame($is_new, $row->isNew());
    $this->assertSame($delta, $row->delta());
    $this->assertSame($growth_pct, $row->growthPct());
  }

  public static function dataProviderDerivation(): \Iterator {
    yield 'new file has no growth' => [120, NULL, TRUE, 120, NULL];
    yield 'unchanged file' => [100, 100, FALSE, 0, 0.0];
    yield 'growth is a percentage of the ref' => [120, 100, FALSE, 20, 20.0];
    yield 'shrinkage is negative growth' => [80, 100, FALSE, -20, -20.0];
    yield 'growth rounds to one decimal' => [4, 3, FALSE, 1, 33.3];
    yield 'growth from an empty ref is undefined' => [50, 0, FALSE, 50, NULL];
  }

  public function testToArrayExposesEveryField(): void {
    $row = new TokenDelta('skills/foo/SKILL.md', 120, 100);

    $this->assertSame([
      'path' => 'skills/foo/SKILL.md',
      'tokens' => 120,
      'ref_tokens' => 100,
      'delta' => 20,
      'growth_pct' => 20.0,
      'new' => FALSE,
    ], $row->toArray());
  }

}
