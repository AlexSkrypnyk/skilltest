<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Init;

use AlexSkrypnyk\SkillTest\Init\AiDraft;
use AlexSkrypnyk\SkillTest\Init\EvalScaffold;
use AlexSkrypnyk\SkillTest\Init\SkillManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Class EvalScaffoldTest.
 *
 * Unit test for rendering an eval.yaml from a manifest and optional draft.
 */
#[CoversClass(EvalScaffold::class)]
final class EvalScaffoldTest extends TestCase {

  protected function manifest(): SkillManifest {
    return new SkillManifest('foo', 'A skill.', ['Bash', 'Skill'], 'the body');
  }

  public function testTemplateRendersCommentedBlocks(): void {
    $yaml = EvalScaffold::render('foo', $this->manifest(), NULL);

    $this->assertStringContainsString('skill: foo', $yaml);
    $this->assertStringContainsString('allowed: [Bash, Skill]', $yaml);
    $this->assertStringContainsString('required: {}', $yaml);
    $this->assertStringContainsString("'raw git mutations': pack:git-mutations", $yaml);
    $this->assertStringContainsString('# deterministic:', $yaml);
    $this->assertStringContainsString('# llm:', $yaml);
    $this->assertStringContainsString('skilltest record --skill foo', $yaml);

    $parsed = Yaml::parse($yaml);
    $this->assertIsArray($parsed);
    $this->assertArrayNotHasKey('llm', $parsed);
    $this->assertArrayNotHasKey('deterministic', $parsed);
    $this->assertSame(['baseline'], $parsed['security']['packs']);
  }

  public function testEmptyAllowedToolsRendersEmptyFlowList(): void {
    $yaml = EvalScaffold::render('foo', new SkillManifest('foo', NULL, [], 'body'), NULL);

    $this->assertStringContainsString('allowed: []', $yaml);
  }

  public function testAiRendersActiveLlmWithConfidenceAndFiltersCommands(): void {
    $draft = new AiDraft(
      [
        ['name' => 'invoked', 'prompt' => '/foo', 'low' => FALSE],
        ['name' => 'discovery', 'prompt' => 'do the thing', 'low' => TRUE],
      ],
      [
        ['label' => 'runs foo', 'pattern' => '\\bfoo\\b', 'low' => FALSE],
        ['label' => 'bad regex', 'pattern' => '(', 'low' => FALSE],
        ['label' => 'raw git mutations', 'pattern' => 'x', 'low' => FALSE],
        ['label' => 'runs foo', 'pattern' => 'dup', 'low' => FALSE],
      ],
      [
        ['text' => 'Does the thing', 'low' => FALSE],
        ['text' => 'Handles errors', 'low' => TRUE],
      ],
    );

    $yaml = EvalScaffold::render('foo', $this->manifest(), $draft);

    $this->assertStringContainsString('name: discovery  # review: low confidence', $yaml);
    $this->assertStringContainsString("- 'Handles errors'  # review: low confidence", $yaml);
    $this->assertStringContainsString('init --ai', $yaml);

    $parsed = Yaml::parse($yaml);
    $this->assertIsArray($parsed);

    // The active llm block carries both drafted tasks and the rubric.
    $this->assertCount(2, $parsed['llm']['tasks']);
    $this->assertSame(['Does the thing', 'Handles errors'], $parsed['llm']['judge']['rubric']);

    // Only the compiling, non-colliding, deduplicated command survives.
    $this->assertSame(['runs foo' => '\bfoo\b'], $parsed['contract']['commands']['required']);
    $this->assertArrayHasKey('raw git mutations', $parsed['contract']['commands']['forbidden']);
  }

  #[DataProvider('dataProviderInsufficientDraft')]
  public function testInsufficientDraftFallsBackToCommentedTemplate(AiDraft $draft): void {
    $yaml = EvalScaffold::render('foo', $this->manifest(), $draft);

    $this->assertStringContainsString('# llm:', $yaml);

    $parsed = Yaml::parse($yaml);
    $this->assertIsArray($parsed);
    $this->assertArrayNotHasKey('llm', $parsed);
  }

  public static function dataProviderInsufficientDraft(): \Iterator {
    yield 'tasks but no rubric' => [new AiDraft([['name' => 't', 'prompt' => 'p', 'low' => FALSE]], [], [])];
    yield 'rubric but no tasks' => [new AiDraft([], [], [['text' => 'c', 'low' => FALSE]])];
    yield 'entirely empty' => [new AiDraft([], [], [])];
  }

}
