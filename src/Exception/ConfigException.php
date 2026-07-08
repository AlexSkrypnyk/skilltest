<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Exception;

/**
 * A hard configuration error that maps to the CONFIG_ERROR (2) exit code.
 *
 * Thrown for problems that make a configuration unloadable at all - a file
 * that will not parse, or a schema major the tool cannot read. Coherence
 * problems that can be accumulated and reported together are collected as
 * validation messages instead of thrown.
 */
final class ConfigException extends \RuntimeException {

  /**
   * Constructs a ConfigException.
   *
   * The source file is kept in its own property rather than the engine-managed
   * \Exception::$file, which must keep pointing at the throwing PHP file.
   *
   * @param string $message
   *   The human-readable error message.
   * @param string $sourceFile
   *   The configuration file the error relates to.
   * @param string $pointer
   *   A dotted pointer to the offending key, when known.
   */
  public function __construct(
    string $message,
    protected readonly string $sourceFile = '',
    protected readonly string $pointer = '',
  ) {
    parent::__construct($message);
  }

  /**
   * Returns the configuration file the error relates to.
   *
   * @return string
   *   The file path, or an empty string when not file-specific.
   */
  public function configFile(): string {
    return $this->sourceFile;
  }

  /**
   * Returns the dotted pointer to the offending key.
   *
   * @return string
   *   The pointer, or an empty string when not key-specific.
   */
  public function pointer(): string {
    return $this->pointer;
  }

}
