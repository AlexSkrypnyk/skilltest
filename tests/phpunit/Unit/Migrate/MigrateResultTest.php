<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Migrate;

use AlexSkrypnyk\SkillTest\Migrate\MigrateResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class MigrateResultTest.
 *
 * Unit test for the migrate result value object.
 */
#[CoversClass(MigrateResult::class)]
#[Group('migrate')]
final class MigrateResultTest extends TestCase {

  public function testExposesItsFields(): void {
    $result = new MigrateResult(TRUE, '0.0', '1', 'migrated.');

    $this->assertTrue($result->changed);
    $this->assertSame('0.0', $result->from);
    $this->assertSame('1', $result->to);
    $this->assertSame('migrated.', $result->message);
  }

}
