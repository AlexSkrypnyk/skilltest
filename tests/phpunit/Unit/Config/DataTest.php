<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Config;

use AlexSkrypnyk\SkillTest\Config\Data;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class DataTest.
 *
 * Unit test for the safe YAML value accessors.
 */
#[CoversClass(Data::class)]
final class DataTest extends TestCase {

  #[DataProvider('dataProviderToArray')]
  public function testToArray(mixed $value, array $expected): void {
    $this->assertSame($expected, Data::toArray($value));
  }

  public static function dataProviderToArray(): \Iterator {
    yield 'array' => [['a' => 1], ['a' => 1]];
    yield 'list' => [[1, 2], [1, 2]];
    yield 'string' => ['x', []];
    yield 'null' => [NULL, []];
    yield 'int' => [5, []];
  }

  #[DataProvider('dataProviderGet')]
  public function testGet(array $data, array $keys, mixed $expected): void {
    $this->assertSame($expected, Data::get($data, ...$keys));
  }

  public static function dataProviderGet(): \Iterator {
    yield 'no keys returns data' => [['a' => 1], [], ['a' => 1]];
    yield 'single key' => [['a' => 1], ['a'], 1];
    yield 'nested' => [['a' => ['b' => 2]], ['a', 'b'], 2];
    yield 'missing key' => [['a' => 1], ['x'], NULL];
    yield 'missing nested key' => [['a' => ['b' => 2]], ['a', 'x'], NULL];
    yield 'descend into scalar' => [['a' => 1], ['a', 'b'], NULL];
  }

  #[DataProvider('dataProviderToStringOrNull')]
  public function testToStringOrNull(mixed $value, ?string $expected): void {
    $this->assertSame($expected, Data::toStringOrNull($value));
  }

  public static function dataProviderToStringOrNull(): \Iterator {
    yield 'string' => ['x', 'x'];
    yield 'int' => [5, '5'];
    yield 'float' => [1.5, '1.5'];
    yield 'bool' => [TRUE, NULL];
    yield 'null' => [NULL, NULL];
    yield 'array' => [[1], NULL];
  }

  #[DataProvider('dataProviderToIntOrNull')]
  public function testToIntOrNull(mixed $value, ?int $expected): void {
    $this->assertSame($expected, Data::toIntOrNull($value));
  }

  public static function dataProviderToIntOrNull(): \Iterator {
    yield 'int' => [5, 5];
    yield 'numeric string' => ['7', 7];
    yield 'negative string' => ['-3', -3];
    yield 'float' => [1.5, NULL];
    yield 'non-numeric string' => ['x', NULL];
    yield 'null' => [NULL, NULL];
  }

  #[DataProvider('dataProviderToFloatOrNull')]
  public function testToFloatOrNull(mixed $value, ?float $expected): void {
    $this->assertSame($expected, Data::toFloatOrNull($value));
  }

  public static function dataProviderToFloatOrNull(): \Iterator {
    yield 'float' => [1.5, 1.5];
    yield 'int' => [2, 2.0];
    yield 'numeric string' => ['0.8', 0.8];
    yield 'non-numeric string' => ['x', NULL];
    yield 'null' => [NULL, NULL];
    yield 'array' => [[1], NULL];
  }

  #[DataProvider('dataProviderToBoolOrNull')]
  public function testToBoolOrNull(mixed $value, ?bool $expected): void {
    $this->assertSame($expected, Data::toBoolOrNull($value));
  }

  public static function dataProviderToBoolOrNull(): \Iterator {
    yield 'true' => [TRUE, TRUE];
    yield 'false' => [FALSE, FALSE];
    yield 'string true' => ['true', TRUE];
    yield 'string TRUE mixed case' => ['True', TRUE];
    yield 'string one' => ['1', TRUE];
    yield 'string false' => ['false', FALSE];
    yield 'string zero' => ['0', FALSE];
    yield 'padded string' => ['  false  ', FALSE];
    yield 'other string' => ['maybe', NULL];
    yield 'int one is not a bool' => [1, NULL];
    yield 'null' => [NULL, NULL];
    yield 'array' => [[TRUE], NULL];
  }

  #[DataProvider('dataProviderToStringList')]
  public function testToStringList(mixed $value, array $expected): void {
    $this->assertSame($expected, Data::toStringList($value));
  }

  public static function dataProviderToStringList(): \Iterator {
    yield 'string becomes single element' => ['x', ['x']];
    yield 'int becomes single element' => [3, ['3']];
    yield 'bool becomes empty' => [TRUE, []];
    yield 'null becomes empty' => [NULL, []];
    yield 'list of strings' => [['a', 'b'], ['a', 'b']];
    yield 'list drops non-scalars' => [['a', ['nested'], 'b'], ['a', 'b']];
    yield 'list coerces scalars' => [[1, 2.5], ['1', '2.5']];
  }

  #[DataProvider('dataProviderToStringMap')]
  public function testToStringMap(mixed $value, array $expected): void {
    $this->assertSame($expected, Data::toStringMap($value));
  }

  public static function dataProviderToStringMap(): \Iterator {
    yield 'non-array' => ['x', []];
    yield 'string values' => [['a' => '1', 'b' => '2'], ['a' => '1', 'b' => '2']];
    yield 'drops non-scalar values' => [['a' => '1', 'b' => ['nested']], ['a' => '1']];
    yield 'coerces int keys and values' => [[0 => 4], ['0' => '4']];
  }

  #[DataProvider('dataProviderToArrayList')]
  public function testToArrayList(mixed $value, array $expected): void {
    $this->assertSame($expected, Data::toArrayList($value));
  }

  public static function dataProviderToArrayList(): \Iterator {
    yield 'non-array' => ['x', []];
    yield 'list of arrays' => [[['a' => 1], ['b' => 2]], [['a' => 1], ['b' => 2]]];
    yield 'drops non-array items and reindexes' => [[['a' => 1], 'scalar', ['b' => 2]], [['a' => 1], ['b' => 2]]];
  }

}
