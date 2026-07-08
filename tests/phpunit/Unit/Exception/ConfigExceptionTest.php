<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Exception;

use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class ConfigExceptionTest.
 *
 * Unit test for the hard configuration error.
 */
#[CoversClass(ConfigException::class)]
final class ConfigExceptionTest extends TestCase {

  public function testFull(): void {
    $exception = new ConfigException('bad thing', 'skilltest.yml', 'version');

    $this->assertSame('bad thing', $exception->getMessage());
    $this->assertSame('skilltest.yml', $exception->configFile());
    $this->assertSame('version', $exception->pointer());
  }

  public function testDefaults(): void {
    $exception = new ConfigException('bad thing');

    $this->assertSame('', $exception->configFile());
    $this->assertSame('', $exception->pointer());
  }

}
