<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Validation;

use AlexSkrypnyk\SkillTest\Validation\ValidationMessage;
use AlexSkrypnyk\SkillTest\Validation\ValidationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class ValidationResultTest.
 *
 * Unit test for the accumulating validation result.
 */
#[CoversClass(ValidationResult::class)]
final class ValidationResultTest extends TestCase {

  public function testEmpty(): void {
    $result = new ValidationResult();

    $this->assertSame([], $result->messages());
    $this->assertSame([], $result->errors());
    $this->assertSame([], $result->warnings());
    $this->assertFalse($result->hasErrors());
  }

  public function testAccumulates(): void {
    $result = new ValidationResult();
    $result->addError('a.yml', 'x', 'error one');
    $result->addWarning('a.yml', 'y', 'warning one');
    $result->add(ValidationMessage::error('b.yml', 'z', 'error two'));

    $this->assertCount(3, $result->messages());
    $this->assertCount(2, $result->errors());
    $this->assertCount(1, $result->warnings());
    $this->assertTrue($result->hasErrors());
    $this->assertSame('error one', $result->errors()[0]->message);
    $this->assertSame('warning one', $result->warnings()[0]->message);
  }

  public function testWarningsOnlyDoNotError(): void {
    $result = new ValidationResult();
    $result->addWarning('a.yml', 'y', 'just a warning');

    $this->assertFalse($result->hasErrors());
  }

}
