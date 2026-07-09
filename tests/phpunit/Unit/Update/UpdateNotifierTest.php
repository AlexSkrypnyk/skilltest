<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Update;

use AlexSkrypnyk\SkillTest\Update\ReleaseClient;
use AlexSkrypnyk\SkillTest\Update\UpdateNotifier;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class UpdateNotifierTest.
 *
 * Unit test for the release-check notice: the disable paths (flag, env, CI,
 * source build), the once-a-day cache, and the version comparison, all with an
 * injected clock and fetcher.
 */
#[CoversClass(UpdateNotifier::class)]
#[Group('update')]
final class UpdateNotifierTest extends TestCase {

  /**
   * The virtual cache directory each test reads and writes under.
   */
  protected string $root = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->root = vfsStream::setup('root')->url();
  }

  public function testDisabledInCiWithoutAnyFetch(): void {
    $calls = [];
    $notifier = $this->notifier('1.0.0', '2.0.0', ['CI' => 'true'], 1000, $calls);

    $this->assertNull($notifier->notice($this->root, FALSE));
    $this->assertCount(0, $calls);
  }

  public function testDisabledByEnvironmentVariable(): void {
    $calls = [];
    $notifier = $this->notifier('1.0.0', '2.0.0', [UpdateNotifier::ENV_DISABLE => '1'], 1000, $calls);

    $this->assertNull($notifier->notice($this->root, FALSE));
    $this->assertCount(0, $calls);
  }

  public function testDisabledByFlag(): void {
    $calls = [];
    $notifier = $this->notifier('1.0.0', '2.0.0', [], 1000, $calls);

    $this->assertNull($notifier->notice($this->root, TRUE));
    $this->assertCount(0, $calls);
  }

  public function testSilentForNonVersionCurrent(): void {
    $calls = [];
    $notifier = $this->notifier('development', '2.0.0', [], 1000, $calls);

    $this->assertNull($notifier->notice($this->root, FALSE));
    $this->assertCount(0, $calls);
  }

  public function testNoticeWhenNewerReleaseExists(): void {
    $calls = [];
    $notifier = $this->notifier('1.0.0', '2.0.0', [], 1000, $calls);

    $notice = $notifier->notice($this->root, FALSE);

    $this->assertNotNull($notice);
    $this->assertStringContainsString('2.0.0', $notice);
    $this->assertStringContainsString('1.0.0', $notice);
    $this->assertStringContainsString('self-update', $notice);
    $this->assertCount(1, $calls);

    $cached = json_decode((string) file_get_contents($this->root . '/' . UpdateNotifier::CACHE_FILE), TRUE);
    $this->assertSame(['checked_at' => 1000, 'latest' => '2.0.0'], $cached);
  }

  public function testNoNoticeWhenAlreadyCurrent(): void {
    $calls = [];
    $notifier = $this->notifier('2.0.0', '2.0.0', [], 1000, $calls);

    $this->assertNull($notifier->notice($this->root, FALSE));
  }

  public function testNoNoticeWhenRemoteIsOlder(): void {
    $calls = [];
    $notifier = $this->notifier('2.0.0', '1.0.0', [], 1000, $calls);

    $this->assertNull($notifier->notice($this->root, FALSE));
  }

  public function testFreshCacheIsUsedWithoutFetching(): void {
    $this->seedCache(950, '3.0.0');
    $calls = [];
    $notifier = $this->notifier('1.0.0', '9.9.9', [], 1000, $calls);

    $notice = $notifier->notice($this->root, FALSE);

    $this->assertNotNull($notice);
    $this->assertStringContainsString('3.0.0', $notice);
    $this->assertCount(0, $calls);
  }

  public function testStaleCacheIsRefetched(): void {
    $this->seedCache(1000 - UpdateNotifier::TTL_SECONDS - 1, '1.0.0');
    $calls = [];
    $notifier = $this->notifier('1.0.0', '2.0.0', [], 1000, $calls);

    $notice = $notifier->notice($this->root, FALSE);

    $this->assertNotNull($notice);
    $this->assertStringContainsString('2.0.0', $notice);
    $this->assertCount(1, $calls);
  }

  public function testNetworkFailureIsSilent(): void {
    $calls = [];
    $notifier = $this->notifier('1.0.0', NULL, [], 1000, $calls);

    $this->assertNull($notifier->notice($this->root, FALSE));
    $this->assertCount(1, $calls);
  }

  public function testNonVersionRemoteTagIsSilent(): void {
    $calls = [];
    $notifier = $this->notifier('1.0.0', 'nightly', [], 1000, $calls);

    $this->assertNull($notifier->notice($this->root, FALSE));
  }

  public function testMalformedCacheIsIgnoredAndRefetched(): void {
    file_put_contents($this->root . '/' . UpdateNotifier::CACHE_FILE, '{"checked_at":"soon","latest":5}');
    $calls = [];
    $notifier = $this->notifier('1.0.0', '2.0.0', [], 1000, $calls);

    $notice = $notifier->notice($this->root, FALSE);

    $this->assertNotNull($notice);
    $this->assertCount(1, $calls);
  }

  public function testNonObjectCacheIsIgnoredAndRefetched(): void {
    file_put_contents($this->root . '/' . UpdateNotifier::CACHE_FILE, '"just a string"');
    $calls = [];
    $notifier = $this->notifier('1.0.0', '2.0.0', [], 1000, $calls);

    $notice = $notifier->notice($this->root, FALSE);

    $this->assertNotNull($notice);
    $this->assertCount(1, $calls);
  }

  public function testVersionPrefixIsStrippedForComparison(): void {
    $calls = [];
    $notifier = $this->notifier('1.4.0', 'v1.5.0', [], 1000, $calls);

    $notice = $notifier->notice($this->root, FALSE);

    $this->assertNotNull($notice);
    $this->assertStringContainsString('v1.5.0', $notice);
  }

  public function testUsesTheRealClockAndCreatesTheCacheDirectory(): void {
    $client = new ReleaseClient(static fn(string $url): array => [200, json_encode(['tag_name' => '2.0.0'], JSON_THROW_ON_ERROR)]);
    $notifier = new UpdateNotifier($client, [], '1.0.0');

    $notice = $notifier->notice($this->root . '/nested/cache', FALSE);

    $this->assertNotNull($notice);
    $this->assertStringContainsString('2.0.0', $notice);
    $this->assertFileExists($this->root . '/nested/cache/' . UpdateNotifier::CACHE_FILE);
  }

  /**
   * Builds a notifier over a fetcher returning a fixed tag and a fixed clock.
   *
   * @param string $current
   *   The current tool version.
   * @param string|null $tag
   *   The remote tag to return, or NULL to simulate a transport failure.
   * @param array<string, string> $env
   *   The process environment.
   * @param int $now
   *   The fixed clock value.
   * @param array<int, string> $calls
   *   The captured fetch URLs, appended to in place.
   *
   * @return \AlexSkrypnyk\SkillTest\Update\UpdateNotifier
   *   The notifier.
   */
  protected function notifier(string $current, ?string $tag, array $env, int $now, array &$calls): UpdateNotifier {
    $fetcher = function (string $url) use ($tag, &$calls): array {
      $calls[] = $url;

      return $tag === NULL ? [0, ''] : [200, json_encode(['tag_name' => $tag], JSON_THROW_ON_ERROR)];
    };

    return new UpdateNotifier(new ReleaseClient($fetcher), $env, $current, static fn(): int => $now);
  }

  /**
   * Seeds the cache file with a record.
   *
   * @param int $checked_at
   *   The check timestamp.
   * @param string $latest
   *   The cached latest tag.
   */
  protected function seedCache(int $checked_at, string $latest): void {
    file_put_contents($this->root . '/' . UpdateNotifier::CACHE_FILE, json_encode(['checked_at' => $checked_at, 'latest' => $latest], JSON_THROW_ON_ERROR));
  }

}
