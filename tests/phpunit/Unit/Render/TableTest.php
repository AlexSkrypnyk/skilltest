<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Render;

use AlexSkrypnyk\SkillTest\Render\Table;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class TableTest.
 *
 * Unit test for the shared text and markdown grid renderer.
 */
#[CoversClass(Table::class)]
final class TableTest extends TestCase {

  public function testTextAlignsColumnsToWidestCell(): void {
    $lines = Table::text(['Skill', 'Minimal'], [['run-harness', 'sonnet'], ['init', 'haiku']]);

    $this->assertSame('Skill        Minimal', $lines[0]);
    $this->assertSame('run-harness  sonnet', $lines[1]);
    $this->assertSame('init         haiku', $lines[2]);
  }

  public function testTextTrimsTrailingWhitespace(): void {
    $lines = Table::text(['A', 'B'], [['longvalue', '']]);

    foreach ($lines as $line) {
      $this->assertSame(rtrim($line), $line, 'No row should carry trailing whitespace.');
    }
  }

  public function testTextFlattensNewlinesInCells(): void {
    $lines = Table::text(['A'], [["one\ntwo"]]);

    $this->assertSame('one two', $lines[1]);
  }

  public function testMarkdownEmitsValidPipeTable(): void {
    $lines = Table::markdown(['Skill', 'Minimal'], [['run-harness', 'sonnet']]);

    $this->assertSame('| Skill | Minimal |', $lines[0]);
    $this->assertSame('| --- | --- |', $lines[1]);
    $this->assertSame('| run-harness | sonnet |', $lines[2]);
  }

  public function testMarkdownEscapesPipesInCells(): void {
    $lines = Table::markdown(['A'], [['a|b']]);

    $this->assertSame('| a\\|b |', $lines[2]);
  }

  public function testMarkdownWithNoRowsIsHeaderAndUnderlineOnly(): void {
    $lines = Table::markdown(['A', 'B'], []);

    $this->assertCount(2, $lines);
    $this->assertSame('| A | B |', $lines[0]);
    $this->assertSame('| --- | --- |', $lines[1]);
  }

}
