<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Gate;

use AlexSkrypnyk\SkillTest\Gate\GateOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class GateOptionsTest.
 *
 * Unit test for parsing and validating the gate policy options.
 */
#[CoversClass(GateOptions::class)]
final class GateOptionsTest extends TestCase {

  public function testDefaultsAreZeroToleranceAndWarnDrift(): void {
    [$options, $errors] = GateOptions::parse(NULL, NULL, NULL);

    $this->assertSame([], $errors);
    $this->assertInstanceOf(GateOptions::class, $options);
    $this->assertEqualsWithDelta(0.0, $options->maxRegression, PHP_FLOAT_EPSILON);
    $this->assertSame('warn', $options->newTasks);
    $this->assertSame('warn', $options->removedTasks);
  }

  public function testParsesValidValues(): void {
    [$options, $errors] = GateOptions::parse('7.5', 'fail', 'allow');

    $this->assertSame([], $errors);
    $this->assertInstanceOf(GateOptions::class, $options);
    $this->assertEqualsWithDelta(7.5, $options->maxRegression, PHP_FLOAT_EPSILON);
    $this->assertSame('fail', $options->newTasks);
    $this->assertSame('allow', $options->removedTasks);
  }

  #[DataProvider('dataProviderInvalidValuesReportErrors')]
  public function testInvalidValuesReportErrors(?string $max, ?string $new, ?string $removed, string $needle): void {
    [$options, $errors] = GateOptions::parse($max, $new, $removed);

    $this->assertNotInstanceOf(GateOptions::class, $options);
    $this->assertNotEmpty($errors);
    $this->assertStringContainsString($needle, implode("\n", $errors));
  }

  public static function dataProviderInvalidValuesReportErrors(): \Iterator {
    yield 'non-numeric regression' => ['abc', NULL, NULL, '--max-regression'];
    yield 'negative regression' => ['-1', NULL, NULL, '--max-regression'];
    yield 'unknown new policy' => [NULL, 'skip', NULL, '--on-new-tasks'];
    yield 'unknown removed policy' => [NULL, NULL, 'drop', '--on-removed-tasks'];
  }

  public function testAccumulatesMultipleErrors(): void {
    [, $errors] = GateOptions::parse('nope', 'skip', 'drop');

    $this->assertCount(3, $errors);
  }

}
