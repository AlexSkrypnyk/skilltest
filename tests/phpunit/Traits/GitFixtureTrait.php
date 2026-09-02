<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Traits;

/**
 * Trait GitFixtureTrait.
 *
 * Builds a seeded git repository fixture for tests.
 */
trait GitFixtureTrait {

  /**
   * The git clone used as a repo source.
   */
  protected string $source;

  /**
   * Initialises the git source clone with one commit.
   */
  protected function initSource(): void {
    mkdir($this->source, 0777, TRUE);
    file_put_contents($this->source . '/hello.txt', 'hi');
    $this->git('init', $this->source);
    $this->git('config user.email test@example.com', $this->source);
    $this->git('config user.name Test', $this->source);
    $this->git('add -A', $this->source);
    $this->git('commit -m seed', $this->source);
  }

  /**
   * Runs a git command in a directory and returns its output.
   *
   * @param string $args
   *   The git arguments.
   * @param string $cwd
   *   The working directory.
   *
   * @return string
   *   The combined command output.
   */
  protected function git(string $args, string $cwd): string {
    $output = [];
    exec('git -C ' . escapeshellarg($cwd) . ' ' . $args . ' 2>&1', $output);

    return implode("\n", $output);
  }

}
