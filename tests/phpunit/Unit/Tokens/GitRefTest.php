<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Tokens;

use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Tokens\GitRef;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class GitRefTest.
 *
 * Unit test for git ref resolution and ref-side content reads, exercised
 * through the injected runner so no real repository is involved.
 */
#[CoversClass(GitRef::class)]
final class GitRefTest extends TestCase {

  public function testResolveReturnsRequestedRefWhenItExists(): void {
    $git = new GitRef('/repo', $this->runner(['feature' => [0, '']]));

    $this->assertSame('feature', $git->resolve('feature'));
  }

  public function testResolveRejectsRequestedRefThatDoesNotExist(): void {
    $git = new GitRef('/repo', $this->runner([]));

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage("git ref 'bogus' does not resolve");

    $git->resolve('bogus');
  }

  public function testResolveDefaultsToOriginMain(): void {
    $git = new GitRef('/repo', $this->runner(['origin/main' => [0, '']]));

    $this->assertSame('origin/main', $git->resolve(NULL));
  }

  public function testResolveFallsBackToMain(): void {
    $git = new GitRef('/repo', $this->runner(['main' => [0, '']]));

    $this->assertSame('main', $git->resolve(NULL));
    $this->assertSame('main', $git->resolve(''));
  }

  public function testResolveRejectsWhenNoDefaultRefExists(): void {
    $git = new GitRef('/repo', $this->runner([]));

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage("neither 'origin/main' nor 'main' resolves");

    $git->resolve(NULL);
  }

  public function testContentAtReturnsStdoutOnSuccess(): void {
    $calls = [];
    $runner = function (string $command, string $cwd) use (&$calls): array {
      $calls[] = [$command, $cwd];

      return [0, "file body\n"];
    };
    $git = new GitRef('/repo/', $runner);

    $this->assertSame("file body\n", $git->contentAt('main', 'skills/foo/SKILL.md'));
    $this->assertSame("git show 'main:skills/foo/SKILL.md'", $calls[0][0]);
    $this->assertSame('/repo', $calls[0][1], 'the trailing slash is trimmed from the working directory');
  }

  public function testContentAtReturnsNullWhenFileAbsentAtRef(): void {
    $git = new GitRef('/repo', static fn(): array => [128, '']);

    $this->assertNull($git->contentAt('main', 'skills/foo/SKILL.md'));
  }

  public function testExistsVerifiesTheCommitForm(): void {
    $commands = [];
    $runner = function (string $command, string $cwd) use (&$commands): array {
      $commands[] = $command;

      return [1, ''];
    };

    $this->assertFalse((new GitRef('/repo', $runner))->exists('origin/main'));
    $this->assertSame("git rev-parse --verify --quiet 'origin/main^{commit}'", $commands[0]);
  }

  /**
   * Builds a runner that reports only the given refs as existing.
   *
   * @param array<string, array{0: int, 1: string}> $refs
   *   The result for each ref name; any other ref fails verification.
   *
   * @return \Closure
   *   The runner.
   */
  protected function runner(array $refs): \Closure {
    return static function (string $command, string $cwd) use ($refs): array {
      foreach ($refs as $ref => $result) {
        if (str_contains($command, "'" . $ref . "^{commit}'")) {
          return $result;
        }
      }

      return [1, ''];
    };
  }

}
