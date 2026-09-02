<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Traits;

use AlexSkrypnyk\SkillTest\Live\AgentPreflight;
use AlexSkrypnyk\SkillTest\Live\DockerPreflight;

/**
 * Trait AgentStubTrait.
 *
 * Points the agent and docker seams at stub commands.
 */
trait AgentStubTrait {

  /**
   * Points the agent seam at a stub command.
   *
   * @param string $command
   *   The stub command prefix.
   */
  protected function useAgent(string $command): void {
    putenv(AgentPreflight::ENV_AGENT . '=' . $command);
  }

  /**
   * Writes a stub docker binary and returns its command prefix.
   *
   * The stub answers the daemon probe with the given exit and, for `run`,
   * emits the canned transcript to stdout, so the whole docker path is
   * exercised without a real daemon.
   *
   * @param string $root
   *   The repository root the stub lives under.
   * @param string $name
   *   The stub filename stem.
   * @param string|null $stream
   *   The stream-json a `run` emits, or NULL to emit nothing.
   * @param int $version_exit
   *   The `version` probe's exit code; non-zero marks the daemon down.
   *
   * @return string
   *   The `php <path>` command prefix.
   */
  protected function dockerStub(string $root, string $name, ?string $stream, int $version_exit = 0): string {
    $path = $root . '/' . $name . '-docker.php';
    $stream_file = $root . '/' . $name . '-docker-stream.txt';
    file_put_contents($stream_file, $stream ?? '');

    $body = "<?php\n";
    $body .= '$sub = $argv[1] ?? "";' . "\n";
    $body .= 'if ($sub === "version") { exit(' . $version_exit . "); }\n";
    $body .= 'if ($sub === "run") { readfile(' . var_export($stream_file, TRUE) . "); exit(0); }\n";
    $body .= "exit(0);\n";
    file_put_contents($path, $body);

    return 'php ' . escapeshellarg($path);
  }

  /**
   * Points the docker seam at a stub command.
   *
   * @param string $command
   *   The stub command prefix.
   */
  protected function useDocker(string $command): void {
    putenv(DockerPreflight::ENV_DOCKER . '=' . $command);
  }

}
