<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Config;

/**
 * The `llm.docker` configuration: the image and limits for docker trials.
 *
 * The docker environment prepares one run image (a base `image` plus optional
 * `setup` build steps) once per run and starts a fresh container from it per
 * trial. `cpus` and `memory-mb` cap each container; both default to no limit so
 * an unconfigured docker run behaves like an ordinary container. Every omitted
 * value takes a built-in default, so a bare `environment: docker` is enough to
 * run against the official agent image.
 */
final readonly class DockerConfig {

  /**
   * The base image used when the file names none.
   */
  public const string DEFAULT_IMAGE = 'ghcr.io/alexskrypnyk/skilltest-agent:latest';

  /**
   * Constructs a DockerConfig.
   *
   * @param string $image
   *   The base image the run image is built from.
   * @param string $setup
   *   Extra Dockerfile instructions appended after the base image, or an empty
   *   string when the base image needs no project tooling.
   * @param float|null $cpus
   *   The per-container CPU limit, or NULL for no limit.
   * @param int|null $memoryMb
   *   The per-container memory limit in megabytes, or NULL for no limit.
   */
  public function __construct(
    public string $image,
    public string $setup,
    public ?float $cpus,
    public ?int $memoryMb,
  ) {}

  /**
   * Builds a DockerConfig from the parsed `llm.docker` block.
   *
   * @param array<mixed> $data
   *   The `llm.docker` mapping, or an empty array when the block is absent.
   *
   * @return self
   *   The docker configuration with defaults applied.
   */
  public static function fromArray(array $data): self {
    $image = Data::toStringOrNull(Data::get($data, 'image'));

    return new self(
      $image === NULL || $image === '' ? self::DEFAULT_IMAGE : $image,
      Data::toStringOrNull(Data::get($data, 'setup')) ?? '',
      Data::toFloatOrNull(Data::get($data, 'cpus')),
      Data::toIntOrNull(Data::get($data, 'memory-mb')),
    );
  }

}
