<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Live;

use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Live\Lifecycle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class LifecycleTest.
 *
 * Unit test for the lifecycle hook runner: phase ordering, template
 * substitution, abort-versus-warn semantics, custom exit codes, working
 * directories, and up-front validation, all driven through an injected runner.
 */
#[CoversClass(Lifecycle::class)]
final class LifecycleTest extends TestCase {

  public function testEmptyConfigRunsNothing(): void {
    $captured = [];
    $warnings = [];
    $lifecycle = new Lifecycle('/repo', [], $this->runner([], $captured), $this->warn($warnings));

    $lifecycle->beforeRun([]);
    $lifecycle->beforeTask([]);
    $lifecycle->afterTask([]);
    $lifecycle->afterRun([]);

    $this->assertSame([], $captured);
    $this->assertSame([], $warnings);
  }

  public function testHooksRunPerPhaseWithSubstitutedVariables(): void {
    $captured = [];
    $config = [
      Lifecycle::BEFORE_RUN => [['command' => 'reset {{ skill }}']],
      Lifecycle::BEFORE_TASK => [['command' => 'seed {{ task }} {{ trial }} {{ model }} {{ workspace }} {{ vars.site }} {{ unknown }}']],
      Lifecycle::AFTER_TASK => [['command' => 'note {{ trial }}']],
      Lifecycle::AFTER_RUN => [['command' => 'teardown']],
    ];
    $lifecycle = new Lifecycle('/repo', $config, $this->runner([0, 0, 0, 0], $captured));

    $lifecycle->beforeRun(['skill' => 'alpha']);
    $lifecycle->beforeTask(['task' => 'build', 'trial' => '2', 'model' => 'haiku', 'workspace' => '/ws', 'vars.site' => 'https://x']);
    $lifecycle->afterTask(['trial' => '2']);
    $lifecycle->afterRun([]);

    $this->assertSame('reset alpha', $captured[0][0]);
    $this->assertSame('seed build 2 haiku /ws https://x ', $captured[1][0]);
    $this->assertSame('note 2', $captured[2][0]);
    $this->assertSame('teardown', $captured[3][0]);
  }

  public function testContainerRunnerHandlesHooksExceptOnHost(): void {
    $host = [];
    $container = [];
    $config = [Lifecycle::BEFORE_TASK => [
      ['command' => 'seed'],
      ['command' => 'reset', 'on-host' => TRUE],
    ]];
    $lifecycle = new Lifecycle('/repo', $config, $this->runner([0], $host), containerRunner: $this->runner([0], $container));

    $lifecycle->beforeTask([]);

    // The plain hook runs in the container; only `on-host` stays on the host.
    $this->assertSame([['reset', '/repo']], $host);
    $this->assertSame([['seed', '/repo']], $container);
  }

  public function testBeforeHookAbortsWhenErrorOnFail(): void {
    $captured = [];
    $config = [Lifecycle::BEFORE_TASK => [['command' => 'reset', 'error-on-fail' => TRUE]]];
    $lifecycle = new Lifecycle('/repo', $config, $this->runner([1], $captured));

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage("lifecycle before-task hook 'reset' failed with exit 1 (expected 0).");

    $lifecycle->beforeTask([]);
  }

  public function testBeforeHookWithoutErrorOnFailOnlyWarns(): void {
    $captured = [];
    $warnings = [];
    $config = [Lifecycle::BEFORE_RUN => [['command' => 'reset']]];
    $lifecycle = new Lifecycle('/repo', $config, $this->runner([1], $captured), $this->warn($warnings));

    $lifecycle->beforeRun([]);

    $this->assertCount(1, $warnings);
    $this->assertStringContainsString("lifecycle before-run hook 'reset' failed with exit 1", $warnings[0]);
  }

  public function testAfterHookFailureAlwaysWarnsNeverAborts(): void {
    $captured = [];
    $warnings = [];
    $config = [Lifecycle::AFTER_TASK => [['command' => 'teardown', 'error-on-fail' => TRUE]]];
    $lifecycle = new Lifecycle('/repo', $config, $this->runner([1], $captured), $this->warn($warnings));

    $lifecycle->afterTask([]);

    $this->assertCount(1, $warnings);
    $this->assertStringContainsString("lifecycle after-task hook 'teardown' failed with exit 1", $warnings[0]);
  }

  public function testDefaultWarnSwallowsFailure(): void {
    $captured = [];
    $config = [Lifecycle::AFTER_RUN => [['command' => 'teardown']]];
    $lifecycle = new Lifecycle('/repo', $config, $this->runner([1], $captured));

    $lifecycle->afterRun([]);

    $this->assertCount(1, $captured);
  }

  public function testMalformedHookThrowsAtConstruction(): void {
    $captured = [];

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage("a lifecycle hook requires a 'command'.");

    new Lifecycle('/repo', [Lifecycle::BEFORE_RUN => [['working-directory' => 'x']]], $this->runner([], $captured));
  }

  #[DataProvider('dataProviderExitCodesNormalisation')]
  public function testExitCodesNormalisation(mixed $codes, int $exit, bool $accepted): void {
    $captured = [];
    $warnings = [];
    $config = [Lifecycle::AFTER_TASK => [['command' => 'run', 'exit-codes' => $codes]]];
    $lifecycle = new Lifecycle('/repo', $config, $this->runner([$exit], $captured), $this->warn($warnings));

    $lifecycle->afterTask([]);

    $this->assertSame($accepted, $warnings === []);
  }

  public static function dataProviderExitCodesNormalisation(): \Iterator {
    yield 'default accepts zero' => [NULL, 0, TRUE];
    yield 'default rejects nonzero' => [NULL, 1, FALSE];
    yield 'list accepts a member' => [[0, 3], 3, TRUE];
    yield 'list rejects a non-member' => [[1, 2], 0, FALSE];
    yield 'scalar code accepted' => [5, 5, TRUE];
    yield 'empty list falls back to default' => [[], 0, TRUE];
    yield 'non-int list falls back to default' => [['x'], 0, TRUE];
    yield 'non-int list rejects nonzero' => [['x'], 1, FALSE];
  }

  #[DataProvider('dataProviderWorkingDirectoryResolution')]
  public function testWorkingDirectoryResolution(?string $directory, string $expected): void {
    $captured = [];
    $hook = ['command' => 'run'];
    if ($directory !== NULL) {
      $hook['working-directory'] = $directory;
    }
    $lifecycle = new Lifecycle('/repo', [Lifecycle::BEFORE_RUN => [$hook]], $this->runner([0], $captured));

    $lifecycle->beforeRun([]);

    $this->assertSame($expected, $captured[0][1]);
  }

  public static function dataProviderWorkingDirectoryResolution(): \Iterator {
    yield 'default is the root' => [NULL, '/repo'];
    yield 'empty is the root' => ['', '/repo'];
    yield 'relative resolves under the root' => ['playground', '/repo/playground'];
    yield 'absolute is used verbatim' => ['/srv/bed', '/srv/bed'];
  }

  /**
   * Builds a fake runner that captures its calls and returns queued exit codes.
   *
   * @param int[] $exits
   *   The per-call exit codes; a missing entry defaults to zero.
   * @param array<int, array{0: string, 1: string}> $captured
   *   The captured `[command, cwd]` pairs, appended to in place.
   *
   * @return \Closure
   *   The runner closure.
   */
  protected function runner(array $exits, array &$captured): \Closure {
    $index = 0;

    return function (string $command, string $cwd) use ($exits, &$index, &$captured): array {
      $captured[] = [$command, $cwd];
      $exit = $exits[$index] ?? 0;
      $index++;

      return [$exit, ''];
    };
  }

  /**
   * Builds a warn sink that collects messages into the given array.
   *
   * @param string[] $warnings
   *   The collected warning messages, appended to in place.
   *
   * @return \Closure
   *   The warn closure.
   */
  protected function warn(array &$warnings): \Closure {
    return function (string $message) use (&$warnings): void {
      $warnings[] = $message;
    };
  }

}
