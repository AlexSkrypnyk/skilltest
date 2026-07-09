<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Update;

use AlexSkrypnyk\SkillTest\Update\ReleaseClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class ReleaseClientTest.
 *
 * Unit test for the release client: parsing the latest tag, downloading assets,
 * and building the asset URLs, all through an injected fetcher.
 */
#[CoversClass(ReleaseClient::class)]
#[Group('update')]
final class ReleaseClientTest extends TestCase {

  public function testLatestTagReadsTagName(): void {
    $urls = [];
    $client = new ReleaseClient($this->fetcher([200, '{"tag_name": "1.4.0"}'], $urls));

    $this->assertSame('1.4.0', $client->latestTag());
    $this->assertSame('https://api.github.com/repos/alexskrypnyk/skilltest/releases/latest', $urls[0]);
  }

  public function testLatestTagUsesConfiguredRepo(): void {
    $urls = [];
    $client = new ReleaseClient($this->fetcher([200, '{"tag_name": "2.0.0"}'], $urls), 'acme/tool');

    $client->latestTag();

    $this->assertSame('https://api.github.com/repos/acme/tool/releases/latest', $urls[0]);
  }

  public function testLatestTagIsNullOnNon200(): void {
    $client = new ReleaseClient($this->fetcher([404, 'Not Found']));

    $this->assertNull($client->latestTag());
  }

  public function testLatestTagIsNullOnNonObjectJson(): void {
    $client = new ReleaseClient($this->fetcher([200, '"a string"']));

    $this->assertNull($client->latestTag());
  }

  public function testLatestTagIsNullWhenTagMissing(): void {
    $client = new ReleaseClient($this->fetcher([200, '{"name": "release"}']));

    $this->assertNull($client->latestTag());
  }

  public function testLatestTagIsNullWhenTagEmpty(): void {
    $client = new ReleaseClient($this->fetcher([200, '{"tag_name": ""}']));

    $this->assertNull($client->latestTag());
  }

  public function testDownloadReturnsBodyOn200(): void {
    $client = new ReleaseClient($this->fetcher([200, 'asset-bytes']));

    $this->assertSame('asset-bytes', $client->download('https://example.com/asset'));
  }

  public function testDownloadIsNullOnTransportFailure(): void {
    $client = new ReleaseClient($this->fetcher([0, '']));

    $this->assertNull($client->download('https://example.com/asset'));
  }

  public function testAssetUrlsFollowTheReleaseScheme(): void {
    $client = new ReleaseClient($this->fetcher([200, '']));

    $this->assertSame('https://github.com/alexskrypnyk/skilltest/releases/download/1.4.0/skilltest.phar', $client->pharUrl('1.4.0'));
    $this->assertSame('https://github.com/alexskrypnyk/skilltest/releases/download/1.4.0/skilltest.phar.sha256', $client->checksumsUrl('1.4.0'));
  }

  /**
   * Builds a fetcher returning a fixed outcome and capturing requested URLs.
   *
   * @param array{0: int, 1: string} $outcome
   *   The `[status, body]` to return for every URL.
   * @param array<int, string> $urls
   *   The captured URLs, appended to in place.
   *
   * @return \Closure
   *   The fetcher closure.
   */
  protected function fetcher(array $outcome, array &$urls = []): \Closure {
    return function (string $url) use ($outcome, &$urls): array {
      $urls[] = $url;

      return $outcome;
    };
  }

}
