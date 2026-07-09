<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Ai;

use AlexSkrypnyk\SkillTest\Ai\JsonObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class JsonObjectTest.
 *
 * Unit test for the shared balanced-object extractor every model-output parser
 * runs before decoding.
 */
#[CoversClass(JsonObject::class)]
final class JsonObjectTest extends TestCase {

  #[DataProvider('dataProviderExtract')]
  public function testExtract(string $raw, string $expected): void {
    $this->assertSame($expected, JsonObject::extract($raw));
  }

  public static function dataProviderExtract(): \Iterator {
    yield 'plain object' => ['{"a":1}', '{"a":1}'];
    yield 'surrounding whitespace is trimmed' => ["  {\"a\":1}  ", '{"a":1}'];
    yield 'json code fence is unwrapped' => ["```json\n{\"a\":1}\n```", '{"a":1}'];
    yield 'bare code fence is unwrapped' => ["```\n{\"a\":1}\n```", '{"a":1}'];
    yield 'leading prose is discarded' => ['note: {"a":1}', '{"a":1}'];
    yield 'trailing noise is discarded' => ['{"a":1} thanks!', '{"a":1}'];
    yield 'no object returns the trimmed input' => ['nothing here', 'nothing here'];
    yield 'braces inside a string do not unbalance' => ['{"a":"} {"}', '{"a":"} {"}'];
    yield 'escaped quote inside a string is honoured' => ['{"a":"x \" y"}', '{"a":"x \" y"}'];
    yield 'nested objects balance by depth' => ['{"a":{"b":2}}', '{"a":{"b":2}}'];
    yield 'unbalanced object returns from the first brace' => ['{"a":1', '{"a":1'];
  }

}
