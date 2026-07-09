<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Live\ResponderConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class ResponderConfigTest.
 *
 * Unit test for parsing and resolving a task's responder configuration.
 */
#[CoversClass(ResponderConfig::class)]
final class ResponderConfigTest extends TestCase {

  /**
   * The model aliases every case resolves against.
   */
  protected const array ALIASES = ['haiku' => 'claude-haiku-4-5', 'opus' => 'claude-opus-4-8'];

  public function testPlainTaskHasNoResponder(): void {
    $this->assertNotInstanceOf(ResponderConfig::class, ResponderConfig::fromTask(['name' => 'plain', 'prompt' => 'Go'], 'eval.yaml', 'opus', self::ALIASES));
  }

  public function testResolvesAndPinsItsOwnModel(): void {
    $task = ['responder' => ['instructions' => 'You are the owner.', 'max-followups' => 6, 'model' => 'haiku']];

    $config = ResponderConfig::fromTask($task, 'eval.yaml', 'opus', self::ALIASES);

    $this->assertInstanceOf(ResponderConfig::class, $config);
    $this->assertSame('You are the owner.', $config->instructions);
    $this->assertSame(6, $config->maxFollowups);
    $this->assertSame('claude-haiku-4-5', $config->model);
  }

  public function testModelDefaultsToTheJudgeModel(): void {
    $task = ['responder' => ['instructions' => 'persona', 'max-followups' => 1]];

    $config = ResponderConfig::fromTask($task, 'eval.yaml', 'opus', self::ALIASES);

    $this->assertInstanceOf(ResponderConfig::class, $config);
    $this->assertSame('claude-opus-4-8', $config->model);
  }

  public function testAnUnaliasedModelIsUsedVerbatim(): void {
    $task = ['responder' => ['instructions' => 'persona', 'max-followups' => 2, 'model' => 'claude-sonnet-5']];

    $config = ResponderConfig::fromTask($task, 'eval.yaml', 'opus', self::ALIASES);

    $this->assertInstanceOf(ResponderConfig::class, $config);
    $this->assertSame('claude-sonnet-5', $config->model);
  }

  #[DataProvider('dataProviderRejects')]
  public function testRejects(mixed $responder, ?string $judge_model, string $message): void {
    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage($message);

    ResponderConfig::fromTask(['responder' => $responder], 'eval.yaml', $judge_model, self::ALIASES);
  }

  public static function dataProviderRejects(): \Iterator {
    yield 'a scalar responder' => ['nope', 'opus', "must be a mapping"];
    yield 'missing instructions' => [['max-followups' => 1], 'opus', "requires non-empty 'instructions'"];
    yield 'blank instructions' => [['instructions' => '   ', 'max-followups' => 1], 'opus', "requires non-empty 'instructions'"];
    yield 'missing max-followups' => [['instructions' => 'p'], 'opus', "'max-followups' to be an integer of at least 1"];
    yield 'a zero max-followups' => [['instructions' => 'p', 'max-followups' => 0], 'opus', 'at least 1'];
    yield 'a non-integer max-followups' => [['instructions' => 'p', 'max-followups' => 'lots'], 'opus', 'at least 1'];
    yield 'no model anywhere' => [['instructions' => 'p', 'max-followups' => 1], NULL, 'has no model'];
  }

}
