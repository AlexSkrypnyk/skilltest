<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Update;

/**
 * Reads GitHub release metadata and assets through one injected fetcher.
 *
 * Both the self-update command and the once-a-day release-check notice need the
 * latest published tag, and self-update additionally needs the release's PHAR
 * and its checksums file; this centralises the URL scheme (mirroring
 * `install.sh`) and the JSON shape so neither caller hand-rolls a GitHub URL.
 * Every network read goes through the injected fetcher - a `(url): [status,
 * body]` closure - so the whole client is exercised offline; the live fetcher
 * is the one untested seam, wired only in production.
 */
final readonly class ReleaseClient {

  /**
   * The default `owner/name` repository releases are read from.
   */
  public const string DEFAULT_REPO = 'alexskrypnyk/skilltest';

  /**
   * The released PHAR asset name.
   */
  public const string PHAR_NAME = 'skilltest.phar';

  /**
   * The released SHA-256 checksums asset name.
   */
  public const string CHECKSUMS_NAME = 'skilltest.phar.sha256';

  /**
   * Constructs a ReleaseClient.
   *
   * @param \Closure(string): array{0: int, 1: string} $fetcher
   *   A fetcher taking a URL and returning `[http status, body]`; a status of
   *   0 signals a transport failure.
   * @param string $repo
   *   The `owner/name` repository to read releases from.
   */
  public function __construct(
    protected \Closure $fetcher,
    protected string $repo = self::DEFAULT_REPO,
  ) {
  }

  /**
   * The latest published release tag, or NULL when it cannot be read.
   *
   * @return string|null
   *   The `tag_name`, or NULL on any transport, status, or shape failure.
   */
  public function latestTag(): ?string {
    [$status, $body] = ($this->fetcher)(sprintf('https://api.github.com/repos/%s/releases/latest', $this->repo));

    if ($status !== 200) {
      return NULL;
    }

    $decoded = json_decode($body, TRUE);

    if (!is_array($decoded)) {
      return NULL;
    }

    $tag = $decoded['tag_name'] ?? NULL;

    return is_string($tag) && $tag !== '' ? $tag : NULL;
  }

  /**
   * Downloads a release asset body, or NULL when it cannot be read.
   *
   * @param string $url
   *   The asset URL.
   *
   * @return string|null
   *   The asset body, or NULL on any transport or status failure.
   */
  public function download(string $url): ?string {
    [$status, $body] = ($this->fetcher)($url);

    return $status === 200 ? $body : NULL;
  }

  /**
   * The download URL of the released PHAR for a tag.
   *
   * @param string $tag
   *   The release tag.
   *
   * @return string
   *   The PHAR asset URL.
   */
  public function pharUrl(string $tag): string {
    return $this->assetUrl($tag, self::PHAR_NAME);
  }

  /**
   * The download URL of the released checksums file for a tag.
   *
   * @param string $tag
   *   The release tag.
   *
   * @return string
   *   The checksums asset URL.
   */
  public function checksumsUrl(string $tag): string {
    return $this->assetUrl($tag, self::CHECKSUMS_NAME);
  }

  /**
   * Builds a release asset download URL.
   *
   * @param string $tag
   *   The release tag.
   * @param string $name
   *   The asset file name.
   *
   * @return string
   *   The asset URL.
   */
  protected function assetUrl(string $tag, string $name): string {
    return sprintf('https://github.com/%s/releases/download/%s/%s', $this->repo, $tag, $name);
  }

  /**
   * Builds the live HTTP fetcher used in production.
   *
   * @return \Closure(string): array{0: int, 1: string}
   *   The fetcher.
   */
  public static function liveFetcher(): \Closure {
    // @codeCoverageIgnoreStart
    return static function (string $url): array {
      $context = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "User-Agent: skilltest\r\nAccept: application/vnd.github+json\r\n",
        'follow_location' => 1,
        'timeout' => 10,
        'ignore_errors' => TRUE,
      ]]);

      $body = @file_get_contents($url, FALSE, $context);
      $status = 0;

      foreach ($http_response_header as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $matches) === 1) {
          $status = (int) $matches[1];
        }
      }

      return [$status, $body === FALSE ? '' : $body];
    };
    // @codeCoverageIgnoreEnd
  }

}
