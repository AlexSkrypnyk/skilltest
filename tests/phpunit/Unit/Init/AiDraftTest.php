<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Init;

use AlexSkrypnyk\SkillTest\Init\AiDraft;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class AiDraftTest.
 *
 * Unit test for parsing a model reply into a typed draft.
 */
#[CoversClass(AiDraft::class)]
final class AiDraftTest extends TestCase {

  public function testParsesFullDraftAndDropsIncompleteEntries(): void {
    $json = json_encode([
      'tasks' => [
        ['name' => 'invoked', 'prompt' => '/foo', 'confidence' => 'high'],
        ['name' => "  spaced \n name ", 'prompt' => "multi\n  line", 'confidence' => 'LOW'],
        ['name' => '', 'prompt' => 'x'],
        ['name' => 'y', 'prompt' => ''],
        ['name' => ['not', 'scalar'], 'prompt' => 'p'],
      ],
      'commands' => [
        ['label' => 'runs foo', 'pattern' => '\\bfoo\\b', 'confidence' => 'low'],
        ['label' => '', 'pattern' => 'x'],
        ['label' => 'z', 'pattern' => ''],
      ],
      'rubric' => [
        'plain string criterion',
        ['text' => 'object criterion', 'confidence' => 'low'],
        ['text' => ''],
        '',
      ],
    ], JSON_THROW_ON_ERROR);

    $draft = AiDraft::fromResponse((string) $json);

    $this->assertInstanceOf(AiDraft::class, $draft);
    $this->assertSame([
      ['name' => 'invoked', 'prompt' => '/foo', 'low' => FALSE],
      ['name' => 'spaced name', 'prompt' => 'multi line', 'low' => TRUE],
    ], $draft->tasks);
    $this->assertSame([
      ['label' => 'runs foo', 'pattern' => '\bfoo\b', 'low' => TRUE],
    ], $draft->commands);
    $this->assertSame([
      ['text' => 'plain string criterion', 'low' => FALSE],
      ['text' => 'object criterion', 'low' => TRUE],
    ], $draft->rubric);
  }

  public function testExtractsJsonObjectFromWrappedResponse(): void {
    $response = "Sure, here is the draft:\n```json\n{\"tasks\":[{\"name\":\"t\",\"prompt\":\"p\"}],\"commands\":[],\"rubric\":[\"c\"]}\n```\nHope that helps!";

    $draft = AiDraft::fromResponse($response);

    $this->assertInstanceOf(AiDraft::class, $draft);
    $this->assertSame([['name' => 't', 'prompt' => 'p', 'low' => FALSE]], $draft->tasks);
    $this->assertSame([], $draft->commands);
    $this->assertSame([['text' => 'c', 'low' => FALSE]], $draft->rubric);
  }

  #[DataProvider('dataProviderNonObjectResponseYieldsNull')]
  public function testNonObjectResponseYieldsNull(string $response): void {
    $this->assertNotInstanceOf(AiDraft::class, AiDraft::fromResponse($response));
  }

  public static function dataProviderNonObjectResponseYieldsNull(): \Iterator {
    yield 'empty string' => [''];
    yield 'whitespace only' => ["  \n\t "];
    yield 'prose without any brace' => ['there is no json here'];
    yield 'open brace but no close' => ['a stray { with no close'];
    yield 'close before open' => ['} then {'];
    yield 'braces wrapping invalid json' => ['prefix { not: valid, json } suffix'];
    yield 'top-level array is not an object' => ['[1, 2, 3]'];
  }

}
