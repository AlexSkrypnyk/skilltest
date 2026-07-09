<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Config;

use AlexSkrypnyk\SkillTest\Config\SkillFiles;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class SkillFilesTest.
 *
 * Unit test for the shared skill-file walker.
 */
#[CoversClass(SkillFiles::class)]
final class SkillFilesTest extends TestCase {

  /**
   * A real temporary directory to clean up, when a test creates one.
   */
  protected string $tempDir = '';

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->tempDir !== '' && is_dir($this->tempDir)) {
      $this->removeTree($this->tempDir);
    }

    parent::tearDown();
  }

  public function testReturnsSortedNestedFiles(): void {
    $root = vfsStream::setup('root', NULL, [
      'skill' => [
        'SKILL.md' => "x\n",
        'scripts' => ['b.sh' => "b\n", 'a.sh' => "a\n"],
      ],
    ])->url();

    $files = SkillFiles::under($root . '/skill');

    $this->assertSame([
      $root . '/skill/SKILL.md',
      $root . '/skill/scripts/a.sh',
      $root . '/skill/scripts/b.sh',
    ], $files);
  }

  public function testDoesNotFollowSymlinks(): void {
    $this->tempDir = getcwd() . '/.artifacts/tmp/skillfiles-' . getmypid() . '-' . uniqid();
    mkdir($this->tempDir . '/outside', 0777, TRUE);
    file_put_contents($this->tempDir . '/outside/secret.sh', "secret\n");
    mkdir($this->tempDir . '/skill', 0777, TRUE);
    file_put_contents($this->tempDir . '/skill/SKILL.md', "x\n");

    if (@symlink($this->tempDir . '/outside', $this->tempDir . '/skill/escape') === FALSE) {
      $this->markTestSkipped('The filesystem does not support symlinks.');
    }

    $files = SkillFiles::under($this->tempDir . '/skill');

    $this->assertSame([$this->tempDir . '/skill/SKILL.md'], $files);
  }

  /**
   * Recursively removes a path, unlinking symlinks without following them.
   *
   * @param string $path
   *   The path to remove.
   */
  protected function removeTree(string $path): void {
    if (is_link($path)) {
      unlink($path);

      return;
    }

    if (is_dir($path)) {
      foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
        $this->removeTree($path . '/' . $entry);
      }

      rmdir($path);

      return;
    }

    if (is_file($path)) {
      unlink($path);
    }
  }

}
