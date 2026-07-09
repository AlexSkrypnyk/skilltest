<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Contract;

use AlexSkrypnyk\SkillTest\Contract\Transcript;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class TranscriptTest.
 *
 * Unit test for the transcript parser.
 */
#[CoversClass(Transcript::class)]
final class TranscriptTest extends TestCase {

  public function testFromFileParsesNestedAndFlatShapes(): void {
    $transcript = Transcript::fromFile(__DIR__ . '/../../Fixtures/transcript.jsonl');

    $this->assertSame(['Bash', 'Skill', 'Bash', 'Read', 'Bash'], $transcript->toolNames());
    $this->assertSame(['php bin/harness workflow start', 'git push origin main'], $transcript->bashCommands());
    $this->assertSame(['harness:build-generic'], $transcript->skillInvocations());
  }

  public function testFromMissingFileYieldsNoEvents(): void {
    $transcript = Transcript::fromFile(__DIR__ . '/does-not-exist.jsonl');

    $this->assertSame([], $transcript->toolUses());
  }

  public function testToolUsesCaptureNameAndInput(): void {
    $transcript = new Transcript('{"type":"tool_use","name":"Bash","input":{"command":"ls"}}');

    $this->assertSame([['name' => 'Bash', 'input' => ['command' => 'ls']]], $transcript->toolUses());
  }

  #[DataProvider('dataProviderToolNames')]
  public function testToolNames(string $jsonl, array $expected): void {
    $this->assertSame($expected, (new Transcript($jsonl))->toolNames());
  }

  public static function dataProviderToolNames(): \Iterator {
    yield 'empty string' => ['', []];
    yield 'whitespace only' => ["  \n  \n", []];
    yield 'blank lines between events' => ["\n{\"type\":\"tool_use\",\"name\":\"Bash\",\"input\":{}}\n\n", ['Bash']];
    yield 'malformed line skipped' => ["oops\n{\"type\":\"tool_use\",\"name\":\"Read\",\"input\":{}}", ['Read']];
    yield 'non-array json line skipped' => ['42', []];
    yield 'tool_use without a name ignored' => ['{"type":"tool_use","input":{"command":"ls"}}', []];
    yield 'non-string name ignored' => ['{"type":"tool_use","name":123,"input":{}}', []];
    yield 'non-tool_use node ignored' => ['{"type":"text","name":"Bash"}', []];
    yield 'deeply nested tool_use found' => ['{"a":{"b":[{"type":"tool_use","name":"Deep","input":{}}]}}', ['Deep']];
  }

  #[DataProvider('dataProviderBashCommands')]
  public function testBashCommands(string $jsonl, array $expected): void {
    $this->assertSame($expected, (new Transcript($jsonl))->bashCommands());
  }

  public static function dataProviderBashCommands(): \Iterator {
    yield 'command captured' => ['{"type":"tool_use","name":"Bash","input":{"command":"git status"}}', ['git status']];
    yield 'missing input skipped' => ['{"type":"tool_use","name":"Bash"}', []];
    yield 'missing command key skipped' => ['{"type":"tool_use","name":"Bash","input":{"skill":"x"}}', []];
    yield 'non-string command skipped' => ['{"type":"tool_use","name":"Bash","input":{"command":["a"]}}', []];
    yield 'non-bash tool ignored' => ['{"type":"tool_use","name":"Skill","input":{"command":"x"}}', []];
  }

  #[DataProvider('dataProviderSkillInvocations')]
  public function testSkillInvocations(string $jsonl, array $expected): void {
    $this->assertSame($expected, (new Transcript($jsonl))->skillInvocations());
  }

  public static function dataProviderSkillInvocations(): \Iterator {
    yield 'skill captured' => ['{"type":"tool_use","name":"Skill","input":{"skill":"lint"}}', ['lint']];
    yield 'missing skill key skipped' => ['{"type":"tool_use","name":"Skill","input":{"command":"x"}}', []];
    yield 'non-string skill skipped' => ['{"type":"tool_use","name":"Skill","input":{"skill":5}}', []];
    yield 'non-skill tool ignored' => ['{"type":"tool_use","name":"Bash","input":{"skill":"x"}}', []];
  }

  #[DataProvider('dataProviderResultText')]
  public function testResultText(string $jsonl, string $expected): void {
    $this->assertSame($expected, (new Transcript($jsonl))->resultText());
  }

  public static function dataProviderResultText(): \Iterator {
    yield 'no result event' => ['{"type":"system","subtype":"init"}', ''];
    yield 'the last result wins' => ['{"type":"result","result":"first"}' . "\n" . '{"type":"result","result":"second"}', 'second'];
    yield 'a non-string result is ignored' => ['{"type":"result","result":42}', ''];
    yield 'blank and malformed lines are skipped' => ["\noops\n42\n" . '{"type":"result","result":"ok"}', 'ok'];
  }

  #[DataProvider('dataProviderSessionId')]
  public function testSessionId(string $jsonl, ?string $expected): void {
    $this->assertSame($expected, (new Transcript($jsonl))->sessionId());
  }

  public static function dataProviderSessionId(): \Iterator {
    yield 'no session id' => ['{"type":"result"}', NULL];
    yield 'the last session id wins' => ['{"type":"system","session_id":"sess-1"}' . "\n" . '{"type":"result","session_id":"sess-2"}', 'sess-2'];
    yield 'a non-string session id is ignored' => ['{"session_id":123}', NULL];
  }

  #[DataProvider('dataProviderResponderTurns')]
  public function testResponderTurns(string $jsonl, array $expected): void {
    $this->assertSame($expected, (new Transcript($jsonl))->responderTurns());
  }

  public static function dataProviderResponderTurns(): \Iterator {
    yield 'turns are captured in order' => ['{"type":"user","responder":true,"text":"a"}' . "\n" . '{"type":"user","responder":true,"text":"b"}', ['a', 'b']];
    yield 'a user turn without the responder marker is ignored' => ['{"type":"user","text":"x"}', []];
    yield 'a false responder marker is ignored' => ['{"type":"user","responder":false,"text":"x"}', []];
    yield 'a responder turn without text is ignored' => ['{"type":"user","responder":true}', []];
    yield 'a non-string responder text is ignored' => ['{"type":"user","responder":true,"text":5}', []];
    yield 'a non-user responder event is ignored' => ['{"type":"assistant","responder":true,"text":"x"}', []];
  }

}
