<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Run;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class RunNetworkGuardTest.
 *
 * Environment assertion for the run path's no-network guarantee: the sources
 * that implement the `run` command must contain no network primitive, so the
 * deterministic gate cannot acquire a network dependency unnoticed.
 */
#[CoversNothing]
final class RunNetworkGuardTest extends TestCase {

  /**
   * Network primitives that must never appear in the run path sources.
   */
  protected const array FORBIDDEN = [
    'curl_init',
    'curl_exec',
    'fsockopen',
    'pfsockopen',
    'stream_socket_client',
    'socket_create',
    'GuzzleHttp',
    'HttpClient',
    'http://',
    'https://',
    'file_get_contents(\'http',
    'file_get_contents("http',
  ];

  #[DataProvider('dataProviderRunPathSources')]
  public function testRunPathSourceContainsNoNetworkPrimitive(string $file): void {
    $source = file_get_contents($file);
    $this->assertIsString($source);

    foreach (self::FORBIDDEN as $token) {
      $this->assertStringNotContainsString($token, $source, sprintf('%s must not reference %s', basename($file), $token));
    }
  }

  /**
   * Data provider for testRunPathSourceContainsNoNetworkPrimitive.
   *
   * @return array<string, array{0: string}>
   *   One case per run path source file.
   */
  public static function dataProviderRunPathSources(): array {
    $src = dirname(__DIR__, 4) . '/src';
    $files = glob($src . '/Run/*.php') ?: [];
    $files[] = $src . '/Command/RunCommand.php';

    $cases = [];

    foreach ($files as $file) {
      $cases[basename($file)] = [$file];
    }

    return $cases;
  }

}
