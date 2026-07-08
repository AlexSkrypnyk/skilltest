<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Hooks;

use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Hooks\HookCase;
use AlexSkrypnyk\SkillTest\Hooks\HookRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class HookRunnerTest.
 *
 * Unit test for the hook runner, driving its verdict and configuration-error
 * logic through an injected process runner and filesystem probe so no script
 * is spawned and no file is touched.
 */
#[CoversClass(HookRunner::class)]
final class HookRunnerTest extends TestCase {

  /**
   * A runner that always reports the given exit code and stderr.
   *
   * @param int $exit
   *   The exit code to report.
   * @param string $stderr
   *   The stderr to report.
   *
   * @return \Closure
   *   The runner closure.
   */
  protected static function runnerReturning(int $exit, string $stderr): \Closure {
    return static fn(string $path, string $cwd, string $stdin): array => [$exit, $stderr];
  }

  /**
   * A ready predicate with a fixed answer.
   *
   * @param bool $ready
   *   Whether every script is considered runnable.
   *
   * @return \Closure
   *   The predicate closure.
   */
  protected static function readiness(bool $ready): \Closure {
    return static fn(string $path): bool => $ready;
  }

  /**
   * A hook declaration with a single case, for verdict tests.
   *
   * @param string $expect
   *   The case's expected decision.
   *
   * @return array<int, array<mixed>>
   *   The hooks list.
   */
  protected static function oneCase(string $expect): array {
    return [['script' => 'hooks/reject.php', 'cases' => [['tool' => 'Bash', 'input' => ['command' => 'gh pr create'], 'expect' => $expect]]]];
  }

  #[DataProvider('dataProviderVerdict')]
  public function testVerdict(string $expect, int $exit, string $stderr, bool $pass, array $message_contains): void {
    $runner = new HookRunner('/root', self::runnerReturning($exit, $stderr), self::readiness(TRUE));

    $results = $runner->run(self::oneCase($expect));

    $this->assertCount(1, $results);
    $result = $results[0];
    $this->assertInstanceOf(CheckResult::class, $result);
    $this->assertSame('hooks.reject', $result->id);
    $this->assertSame('hooks/reject.php', $result->label);
    $this->assertSame('gh pr create', $result->evidence);
    $this->assertSame($pass, $result->pass);

    foreach ($message_contains as $needle) {
      $this->assertStringContainsString($needle, $result->message);
    }
  }

  public static function dataProviderVerdict(): \Iterator {
    yield 'block passes on blocking exit' => [HookCase::EXPECT_BLOCK, 2, '', TRUE, ["hook 'hooks/reject.php' blocked Bash input as expected."]];
    yield 'allow passes on success exit' => [HookCase::EXPECT_ALLOW, 0, '', TRUE, ["hook 'hooks/reject.php' allowed Bash input as expected."]];
    yield 'block fails when allowed' => [HookCase::EXPECT_BLOCK, 0, '', FALSE, ["hook 'hooks/reject.php' on Bash input gh pr create: expected block (exit 2) but got exit 0."]];
    yield 'allow fails when blocked with stderr' => [HookCase::EXPECT_ALLOW, 2, 'blocked: not allowed', FALSE, ['expected allow (exit 0) but got exit 2', 'stderr: blocked: not allowed']];
    yield 'error exit fails with stderr' => [HookCase::EXPECT_BLOCK, 1, 'fatal boom', FALSE, ['got exit 1', 'stderr: fatal boom']];
    yield 'timeout fails naming the budget' => [HookCase::EXPECT_BLOCK, HookRunner::TIMEOUT_EXIT, '', FALSE, ['got exit 124', 'timed out after 10s']];
    yield 'timeout keeps partial stderr' => [HookCase::EXPECT_ALLOW, HookRunner::TIMEOUT_EXIT, 'partial output', FALSE, ['timed out after 10s', 'stderr: partial output']];
  }

  public function testNonCommandInputIsSummarisedAsJsonEvidence(): void {
    $hooks = [['script' => 'hooks/guard.php', 'cases' => [['tool' => 'Write', 'input' => ['file_path' => '/etc/x'], 'expect' => 'allow']]]];

    $results = (new HookRunner('/root', self::runnerReturning(0, ''), self::readiness(TRUE)))->run($hooks);

    $this->assertSame('{"file_path":"/etc/x"}', $results[0]->evidence);
  }

  public function testRunnerReceivesResolvedPathWorkingDirAndPayload(): void {
    $captured = [];
    $runner = function (string $path, string $cwd, string $stdin) use (&$captured): array {
      $captured = ['path' => $path, 'cwd' => $cwd, 'stdin' => $stdin];

      return [2, ''];
    };

    (new HookRunner('/repo/root', $runner, self::readiness(TRUE)))->run(self::oneCase(HookCase::EXPECT_BLOCK));

    $this->assertSame('/repo/root/hooks/reject.php', $captured['path']);
    $this->assertSame('/repo/root', $captured['cwd']);
    $this->assertSame('{"hook_event_name":"PreToolUse","tool_name":"Bash","tool_input":{"command":"gh pr create"}}', $captured['stdin']);
  }

  public function testAbsoluteScriptPathIsNotPrefixedWithRoot(): void {
    $captured = NULL;
    $ready = function (string $path) use (&$captured): bool {
      $captured = $path;

      return TRUE;
    };
    $hooks = [['script' => '/opt/hooks/abs.php', 'cases' => [['tool' => 'Bash', 'input' => ['command' => 'x'], 'expect' => 'block']]]];

    $results = (new HookRunner('/repo/root', self::runnerReturning(2, ''), $ready))->run($hooks);

    $this->assertSame('/opt/hooks/abs.php', $captured);
    $this->assertSame('hooks.abs', $results[0]->id);
  }

  public function testEmptyHooksListYieldsNoResults(): void {
    $this->assertSame([], (new HookRunner('/root', self::runnerReturning(0, ''), self::readiness(TRUE)))->run([]));
  }

  #[DataProvider('dataProviderZeroCaseHook')]
  public function testReadyHookWithoutCasesYieldsNoResults(array $hooks): void {
    $this->assertSame([], (new HookRunner('/root', self::runnerReturning(0, ''), self::readiness(TRUE)))->run($hooks));
  }

  public static function dataProviderZeroCaseHook(): \Iterator {
    yield 'explicit empty cases' => [[['script' => 'hooks/x.php', 'cases' => []]]];
    yield 'no cases key' => [[['script' => 'hooks/x.php']]];
  }

  public function testMultipleHooksAndCasesReturnResultsInOrder(): void {
    $runner = static fn(string $path, string $cwd, string $stdin): array => [str_contains($stdin, 'block-me') ? 2 : 0, ''];
    $hooks = [
      ['script' => 'hooks/first.php', 'cases' => [
        ['tool' => 'Bash', 'input' => ['command' => 'block-me one'], 'expect' => 'block'],
        ['tool' => 'Bash', 'input' => ['command' => 'allow two'], 'expect' => 'allow'],
      ]],
      ['script' => 'hooks/second.php', 'cases' => [
        ['tool' => 'Bash', 'input' => ['command' => 'allow three'], 'expect' => 'allow'],
      ]],
    ];

    $results = (new HookRunner('/root', $runner, self::readiness(TRUE)))->run($hooks);

    $this->assertCount(3, $results);
    $this->assertSame(['hooks.first', 'hooks.first', 'hooks.second'], array_map(static fn(CheckResult $r): string => $r->id, $results));
    $this->assertSame(['block-me one', 'allow two', 'allow three'], array_map(static fn(CheckResult $r): string => $r->evidence, $results));
    $this->assertSame([TRUE, TRUE, TRUE], array_map(static fn(CheckResult $r): bool => $r->pass, $results));
  }

  #[DataProvider('dataProviderConfigError')]
  public function testConfigErrorsThrow(array $hooks, bool $ready, string $message, string $pointer): void {
    $runner = new HookRunner('/root', self::runnerReturning(2, ''), self::readiness($ready));

    try {
      $runner->run($hooks);
      $this->fail('Expected a ConfigException.');
    }
    catch (ConfigException $config_exception) {
      $this->assertSame($message, $config_exception->getMessage());
      $this->assertSame($pointer, $config_exception->pointer());
    }
  }

  public static function dataProviderConfigError(): \Iterator {
    yield 'missing script key' => [[['cases' => []]], TRUE, 'hook is missing a script.', 'hooks.0.script'];
    yield 'empty script string' => [[['script' => '', 'cases' => []]], TRUE, 'hook is missing a script.', 'hooks.0.script'];
    yield 'script not runnable' => [[['script' => 'hooks/x.php', 'cases' => []]], FALSE, 'hook script not found or not executable: hooks/x.php', 'hooks.0.script'];
    yield 'case missing tool' => [[['script' => 'hooks/x.php', 'cases' => [['expect' => 'block']]]], TRUE, 'hook case is missing a tool.', 'hooks.0.cases.0.tool'];
    yield 'case empty tool' => [[['script' => 'hooks/x.php', 'cases' => [['tool' => '', 'expect' => 'block']]]], TRUE, 'hook case is missing a tool.', 'hooks.0.cases.0.tool'];
    yield 'case bad expect' => [[['script' => 'hooks/x.php', 'cases' => [['tool' => 'Bash', 'expect' => 'maybe']]]], TRUE, "hook case expect must be 'block' or 'allow'.", 'hooks.0.cases.0.expect'];
    yield 'case missing expect' => [[['script' => 'hooks/x.php', 'cases' => [['tool' => 'Bash']]]], TRUE, "hook case expect must be 'block' or 'allow'.", 'hooks.0.cases.0.expect'];
    yield 'second hook script error keeps its index' => [[['script' => 'hooks/ok.php', 'cases' => []], ['cases' => []]], TRUE, 'hook is missing a script.', 'hooks.1.script'];
  }

}
