<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Config;

use AlexSkrypnyk\SkillTest\Config\DockerConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class DockerConfigTest.
 *
 * Unit test for the `llm.docker` configuration value object.
 */
#[CoversClass(DockerConfig::class)]
final class DockerConfigTest extends TestCase {

  public function testDefaults(): void {
    $docker = DockerConfig::fromArray([]);

    $this->assertSame(DockerConfig::DEFAULT_IMAGE, $docker->image);
    $this->assertSame('', $docker->setup);
    $this->assertNull($docker->cpus);
    $this->assertNull($docker->memoryMb);
  }

  #[DataProvider('dataProviderFromArray')]
  public function testFromArray(array $data, string $image, string $setup, ?float $cpus, ?int $memory_mb): void {
    $docker = DockerConfig::fromArray($data);

    $this->assertSame($image, $docker->image);
    $this->assertSame($setup, $docker->setup);
    $this->assertSame($cpus, $docker->cpus);
    $this->assertSame($memory_mb, $docker->memoryMb);
  }

  public static function dataProviderFromArray(): \Iterator {
    yield 'all values' => [
      ['image' => 'my/image:1', 'setup' => 'RUN apt-get install -y php', 'cpus' => 1.5, 'memory-mb' => 512],
      'my/image:1',
      'RUN apt-get install -y php',
      1.5,
      512,
    ];
    yield 'empty image falls back to default' => [
      ['image' => '', 'setup' => ''],
      DockerConfig::DEFAULT_IMAGE,
      '',
      NULL,
      NULL,
    ];
    yield 'integer cpus coerces to float' => [
      ['cpus' => 2, 'memory-mb' => '256'],
      DockerConfig::DEFAULT_IMAGE,
      '',
      2.0,
      256,
    ];
    yield 'non-numeric limits are dropped' => [
      ['cpus' => 'lots', 'memory-mb' => 'plenty'],
      DockerConfig::DEFAULT_IMAGE,
      '',
      NULL,
      NULL,
    ];
  }

}
