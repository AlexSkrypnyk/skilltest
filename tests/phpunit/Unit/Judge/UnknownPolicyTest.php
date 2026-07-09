<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Judge;

use AlexSkrypnyk\SkillTest\Judge\UnknownPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class UnknownPolicyTest.
 *
 * Unit test for the judge abstention policy resolution.
 */
#[CoversClass(UnknownPolicy::class)]
final class UnknownPolicyTest extends TestCase {

  #[DataProvider('dataProviderFromConfig')]
  public function testFromConfig(?string $value, UnknownPolicy $expected): void {
    $this->assertSame($expected, UnknownPolicy::fromConfig($value));
  }

  public static function dataProviderFromConfig(): \Iterator {
    yield 'null defaults to fail' => [NULL, UnknownPolicy::FAIL];
    yield 'fail' => ['fail', UnknownPolicy::FAIL];
    yield 'ignore' => ['ignore', UnknownPolicy::IGNORE];
    yield 'unrecognised defaults to fail' => ['maybe', UnknownPolicy::FAIL];
  }

}
