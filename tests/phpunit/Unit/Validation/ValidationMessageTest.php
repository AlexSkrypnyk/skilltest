<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Validation;

use AlexSkrypnyk\SkillTest\Validation\ValidationMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class ValidationMessageTest.
 *
 * Unit test for a single validation finding.
 */
#[CoversClass(ValidationMessage::class)]
final class ValidationMessageTest extends TestCase {

  public function testError(): void {
    $message = ValidationMessage::error('eval.yaml', 'contract.tools', 'bad');

    $this->assertTrue($message->isError);
    $this->assertSame('eval.yaml', $message->file);
    $this->assertSame('contract.tools', $message->pointer);
    $this->assertSame('bad', $message->message);
  }

  public function testWarning(): void {
    $message = ValidationMessage::warning('eval.yaml', 'extra', 'unknown key.');

    $this->assertFalse($message->isError);
  }

  #[DataProvider('dataProviderRender')]
  public function testRender(string $pointer, string $expected): void {
    $message = ValidationMessage::error('eval.yaml', $pointer, 'boom');

    $this->assertSame($expected, $message->render());
  }

  public static function dataProviderRender(): \Iterator {
    yield 'with pointer' => ['contract.tools', 'eval.yaml: contract.tools - boom'];
    yield 'without pointer' => ['', 'eval.yaml - boom'];
  }

  public function testToArray(): void {
    $message = ValidationMessage::error('eval.yaml', 'version', 'bad major');

    $this->assertSame(['file' => 'eval.yaml', 'pointer' => 'version', 'message' => 'bad major'], $message->toArray());
  }

}
