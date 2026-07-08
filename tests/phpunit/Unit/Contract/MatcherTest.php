<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Contract;

use AlexSkrypnyk\SkillTest\Contract\Matcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class MatcherTest.
 *
 * Unit test for command pattern and pack matching.
 */
#[CoversClass(Matcher::class)]
final class MatcherTest extends TestCase {

  public function testExpandPlainRegexReturnsItself(): void {
    $this->assertSame(['\bgit\s+status\b'], Matcher::expand('\bgit\s+status\b'));
  }

  public function testExpandPackReturnsPackPatterns(): void {
    $patterns = Matcher::expand('pack:git-mutations');

    $this->assertContains('\bgit\s+reset\s+--hard\b', $patterns);
  }

  public function testExpandUnknownPackIsEmpty(): void {
    $this->assertSame([], Matcher::expand('pack:nope'));
  }

  #[DataProvider('dataProviderFirstMatch')]
  public function testFirstMatch(array $commands, string $pattern, ?string $expected): void {
    $this->assertSame($expected, Matcher::firstMatch($commands, $pattern));
  }

  public static function dataProviderFirstMatch(): \Iterator {
    yield 'plain regex match returns command' => [['git status', 'git push'], '\bgit\s+push\b', 'git push'];
    yield 'plain regex no match returns null' => [['git status'], '\bgit\s+push\b', NULL];
    yield 'returns the first matching command in order' => [['ls', 'git push', 'git commit -m x'], 'pack:git-mutations', 'git push'];
    yield 'no commands returns null' => [[], 'pack:git-mutations', NULL];
    yield 'unknown pack never matches' => [['git push'], 'pack:nope', NULL];

    // Acceptance: git-mutations matches a push, not a status.
    yield 'git-mutations matches push' => [['git push'], 'pack:git-mutations', 'git push'];
    yield 'git-mutations ignores status' => [['git status'], 'pack:git-mutations', NULL];
    yield 'git-mutations matches reset --hard' => [['git reset --hard HEAD~1'], 'pack:git-mutations', 'git reset --hard HEAD~1'];
    yield 'git-mutations ignores soft reset' => [['git reset --soft HEAD~1'], 'pack:git-mutations', NULL];

    // Acceptance: gh-mutations matches a create, not a view.
    yield 'gh-mutations matches pr create' => [['gh pr create -t x'], 'pack:gh-mutations', 'gh pr create -t x'];
    yield 'gh-mutations ignores pr view' => [['gh pr view 1'], 'pack:gh-mutations', NULL];
    yield 'gh-mutations matches project item-edit' => [['gh project item-edit 1'], 'pack:gh-mutations', 'gh project item-edit 1'];
    yield 'gh-mutations ignores project item-list' => [['gh project item-list 1'], 'pack:gh-mutations', NULL];
    yield 'gh-mutations matches mutating api method' => [['gh api repos/x/y -X POST'], 'pack:gh-mutations', 'gh api repos/x/y -X POST'];
    yield 'gh-mutations matches lowercase api method' => [['gh api repos/x/y --method delete'], 'pack:gh-mutations', 'gh api repos/x/y --method delete'];
    yield 'gh-mutations ignores read-only api' => [['gh api repos/x/y'], 'pack:gh-mutations', NULL];

    yield 'gh-readonly matches pr view' => [['gh pr view 1'], 'pack:gh-readonly', 'gh pr view 1'];
    yield 'gh-readonly ignores pr create' => [['gh pr create -t x'], 'pack:gh-readonly', NULL];

    yield 'package-installs matches npm global' => [['npm i -g agent-browser'], 'pack:package-installs', 'npm i -g agent-browser'];
    yield 'package-installs matches composer global' => [['composer global require x/y'], 'pack:package-installs', 'composer global require x/y'];
    yield 'package-installs ignores local npm install' => [['npm install'], 'pack:package-installs', NULL];

    yield 'network-fetch matches curl url' => [['curl -sSL https://example.com/x'], 'pack:network-fetch', 'curl -sSL https://example.com/x'];
    yield 'network-fetch ignores curl version' => [['curl --version'], 'pack:network-fetch', NULL];

    yield 'system-temp matches tmp path' => [['echo x > /tmp/out'], 'pack:system-temp', 'echo x > /tmp/out'];
    yield 'system-temp matches tmpdir var' => [['cp x "$TMPDIR/y"'], 'pack:system-temp', 'cp x "$TMPDIR/y"'];
    yield 'system-temp ignores project temp' => [['echo x > .artifacts/tmp/out'], 'pack:system-temp', NULL];
  }

}
