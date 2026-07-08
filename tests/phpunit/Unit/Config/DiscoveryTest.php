<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Config;

use AlexSkrypnyk\SkillTest\Config\Discovery;
use AlexSkrypnyk\SkillTest\Config\RepoConfig;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class DiscoveryTest.
 *
 * Unit test for skill discovery.
 */
#[CoversClass(Discovery::class)]
final class DiscoveryTest extends TestCase {

  public function testDiscoversDepthOneAndTwo(): void {
    $root = vfsStream::setup('root', NULL, [
      'skills' => [
        'foo' => ['SKILL.md' => 'x'],
        'group' => [
          'bar' => ['SKILL.md' => 'x'],
          'deep' => ['sub' => ['SKILL.md' => 'x']],
        ],
        'notaskill' => ['README.md' => 'x'],
        'SKILL.md' => 'stray file, not a directory',
      ],
    ]);

    $discovery = new Discovery($root->url(), RepoConfig::fromArray([]));

    $this->assertSame(['skills/foo', 'skills/group/bar'], $discovery->skills());
  }

  public function testMissingSkillsPath(): void {
    $root = vfsStream::setup('root', NULL, ['other' => []]);

    $discovery = new Discovery($root->url(), RepoConfig::fromArray([]));

    $this->assertSame([], $discovery->skills());
  }

  public function testMultiplePathsAreDeduplicatedAndSorted(): void {
    $root = vfsStream::setup('root', NULL, [
      'a' => ['one' => ['SKILL.md' => 'x']],
      'b' => ['two' => ['SKILL.md' => 'x']],
    ]);

    $discovery = new Discovery($root->url(), RepoConfig::fromArray(['paths' => ['skills' => ['b', 'a', 'a']]]));

    $this->assertSame(['a/one', 'b/two'], $discovery->skills());
  }

}
