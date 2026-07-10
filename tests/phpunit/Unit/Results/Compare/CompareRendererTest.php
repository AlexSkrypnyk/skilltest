<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Results\Compare;

use AlexSkrypnyk\SkillTest\Results\Compare\Comparison;
use AlexSkrypnyk\SkillTest\Results\Compare\CompareRenderer;
use AlexSkrypnyk\SkillTest\Tests\Traits\ResultsDocumentTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class CompareRendererTest.
 *
 * Unit test for the terminal comparison tables: the aggregate grid, the signed
 * deltas, the percentage and money formatting, and the model and task sections
 * that appear only when the runs carried llm results.
 */
#[CoversClass(CompareRenderer::class)]
#[Group('results')]
final class CompareRendererTest extends TestCase {

  use ResultsDocumentTrait;

  public function testAggregateGridShowsValuesAndSignedDeltas(): void {
    $before = $this->document([], [], [], ['checks' => 4, 'failures' => 2, 'cost_usd' => 0.10]);
    $after = $this->document([], [], [], ['checks' => 4, 'failures' => 0, 'cost_usd' => 0.30]);

    $text = implode("\n", (new CompareRenderer(Comparison::of([['label' => 'before', 'document' => $before], ['label' => 'after', 'document' => $after]])))->text());

    $this->assertStringContainsString('compare: before -> after', $text);
    $this->assertStringContainsString('aggregate', $text);
    $this->assertMatchesRegularExpression('/metric +before +after +delta/', $text);
    $this->assertMatchesRegularExpression('/pass_rate +50% +100% +\+50%/', $text);
    $this->assertMatchesRegularExpression('/failures +2 +0 +-2/', $text);
    $this->assertMatchesRegularExpression('/cost_usd +\$0\.1000 +\$0\.3000 +\+\$0\.2000/', $text);
  }

  public function testModelAndTaskSectionsAppearWithLlmResults(): void {
    $before = $this->document([$this->skill('alpha', [], [], [], $this->llm([$this->task('invoked', 'claude-haiku-4-5', 'haiku', [$this->trial(1, FALSE, ['cost_usd' => 0.02])], 0.0)]))]);
    $after = $this->document([$this->skill('alpha', [], [], [], $this->llm([$this->task('invoked', 'claude-haiku-4-5', 'haiku', [$this->trial(1, TRUE, ['cost_usd' => 0.02])], 1.0)]))]);

    $text = implode("\n", (new CompareRenderer(Comparison::of([['label' => 'before', 'document' => $before], ['label' => 'after', 'document' => $after]])))->text());

    $this->assertStringContainsString('models', $text);
    $this->assertMatchesRegularExpression('/haiku pass_rate +0% +100% +\+100%/', $text);
    $this->assertStringContainsString('tasks', $text);
    $this->assertMatchesRegularExpression('/alpha::invoked::haiku +0% +100% +\+100%/', $text);
  }

  public function testDeterministicComparisonOmitsModelAndTaskSections(): void {
    $document = $this->document([$this->skill('alpha', [$this->check('structure.frontmatter', TRUE)])], [], [], ['checks' => 1, 'failures' => 0]);

    $text = implode("\n", (new CompareRenderer(Comparison::of([['label' => 'a', 'document' => $document], ['label' => 'b', 'document' => $document]])))->text());

    $this->assertStringContainsString('aggregate', $text);
    $this->assertStringNotContainsString('models', $text);
    $this->assertStringNotContainsString('tasks', $text);
  }

  public function testMissingValueRendersDash(): void {
    $before = $this->document([$this->skill('alpha', [], [], [], $this->llm([$this->task('invoked', 'claude-haiku-4-5', 'haiku', [$this->trial(1, TRUE)], 1.0)]))]);
    $after = $this->document([$this->skill('alpha', [], [], [], $this->llm([$this->task('invoked', 'claude-sonnet-5', 'sonnet', [$this->trial(1, TRUE)], 1.0)]))]);

    $text = implode("\n", (new CompareRenderer(Comparison::of([['label' => 'before', 'document' => $before], ['label' => 'after', 'document' => $after]])))->text());

    $this->assertMatchesRegularExpression('/haiku pass_rate +100% +- +-/', $text);
  }

  public function testZeroDeltaIsUnsigned(): void {
    $document = $this->document([], [], [], ['checks' => 4, 'failures' => 1]);

    $text = implode("\n", (new CompareRenderer(Comparison::of([['label' => 'a', 'document' => $document], ['label' => 'b', 'document' => $document]])))->text());

    $this->assertMatchesRegularExpression('/failures +1 +1 +0/', $text);
    $this->assertMatchesRegularExpression('/pass_rate +75% +75% +0%/', $text);
  }

}
