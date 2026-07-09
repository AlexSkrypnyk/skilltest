<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Live\ResponderAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class ResponderActionTest.
 *
 * Unit test for the per-turn responder action's wire values.
 */
#[CoversClass(ResponderAction::class)]
final class ResponderActionTest extends TestCase {

  #[DataProvider('dataProviderValues')]
  public function testValues(string $value, ResponderAction $expected): void {
    $this->assertSame($expected, ResponderAction::from($value));
  }

  public static function dataProviderValues(): \Iterator {
    yield 'reply' => ['reply', ResponderAction::Reply];
    yield 'stop' => ['stop', ResponderAction::Stop];
    yield 'abstain' => ['abstain', ResponderAction::Abstain];
  }

}
