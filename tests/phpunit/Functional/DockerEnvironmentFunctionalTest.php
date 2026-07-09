<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Functional;

use AlexSkrypnyk\SkillTest\Config\DockerConfig;
use AlexSkrypnyk\SkillTest\Live\DockerEnvironment;
use AlexSkrypnyk\SkillTest\Live\EnvironmentInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Class DockerEnvironmentFunctionalTest.
 *
 * Runs the shared environment contract against a real docker environment, so
 * a trial genuinely executes inside a container against a bind-mounted
 * workspace and leaves no container behind. It is skipped unless docker is
 * actually usable - the daemon answers and the test image is present locally -
 * so it never pulls a heavy image or fails a machine without docker; a
 * developer with docker runs `docker pull alpine` once to exercise it. Kept
 * small and cheap on purpose: the exhaustive command-shape assertions live in
 * the hermetic {@see DockerEnvironmentTest}.
 */
#[CoversClass(DockerEnvironment::class)]
#[Group('docker')]
final class DockerEnvironmentFunctionalTest extends EnvironmentTestCase {

  /**
   * The image the container trials run from when none is configured in the env.
   */
  public const string DEFAULT_TEST_IMAGE = 'alpine';

  /**
   * The image resolved for this run.
   */
  protected string $image;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->image = getenv('SKILLTEST_DOCKER_TEST_IMAGE') ?: self::DEFAULT_TEST_IMAGE;

    if (!$this->dockerUsable($this->image)) {
      $this->markTestSkipped(sprintf('docker is unavailable or the image %s is not present locally.', $this->image));
    }

    parent::setUp();
  }

  /**
   * {@inheritdoc}
   */
  protected function createEnvironment(string $workspace_base): EnvironmentInterface {
    return new DockerEnvironment($this->root, 1, 300.0, new DockerConfig($this->image, '', NULL, NULL), 'docker', [], NULL, NULL, NULL, $workspace_base);
  }

  /**
   * Whether docker answers and the given image is present locally.
   *
   * @param string $image
   *   The image the trials need.
   *
   * @return bool
   *   TRUE when the daemon is reachable and the image is present.
   */
  protected function dockerUsable(string $image): bool {
    $version = [];
    exec('docker version 2>&1', $version, $version_exit);

    if ($version_exit !== 0) {
      return FALSE;
    }

    $inspect = [];
    exec('docker image inspect ' . escapeshellarg($image) . ' 2>&1', $inspect, $inspect_exit);

    return $inspect_exit === 0;
  }

}
