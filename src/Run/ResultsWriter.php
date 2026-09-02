<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Run;

use AlexSkrypnyk\File\File;

/**
 * Persists a results document to disk, redacted, in either supported layout.
 *
 * Transcripts and other artifacts are written as separate files and
 * referenced from the JSON by relative path, never embedded. Every byte -
 * the document and each artifact - passes through the redactor before it is
 * written, so no persisted artifact carries an environment secret.
 */
final readonly class ResultsWriter {

  /**
   * The results filename inside a run directory.
   */
  public const string RESULTS_FILE = 'results.json';

  /**
   * Constructs a ResultsWriter.
   *
   * @param \AlexSkrypnyk\SkillTest\Run\Redactor $redactor
   *   The redactor applied to the document and every artifact before writing.
   */
  public function __construct(
    protected Redactor $redactor,
  ) {}

  /**
   * Writes the results document to a single file.
   *
   * @param array<string, mixed> $document
   *   The results document.
   * @param string $file
   *   The destination file path; missing parent directories are created.
   *
   * @return string
   *   The path written.
   */
  public function writeFile(array $document, string $file): string {
    File::dump($file, $this->encode($document));

    return $file;
  }

  /**
   * Writes a timestamped run directory with the document and its artifacts.
   *
   * @param array<string, mixed> $document
   *   The results document.
   * @param string $dir
   *   The parent directory holding one subdirectory per run.
   * @param string $timestamp
   *   The run subdirectory name (a UTC timestamp).
   * @param array<string, string> $artifacts
   *   Artifact contents keyed by their path relative to the run directory, as
   *   referenced from the document (e.g. `artifacts/haiku-1.jsonl`).
   *
   * @return string
   *   The run directory written.
   */
  public function writeDir(array $document, string $dir, string $timestamp, array $artifacts = []): string {
    $run_dir = rtrim($dir, '/') . '/' . $timestamp;
    File::mkdir($run_dir);
    File::dump($run_dir . '/' . self::RESULTS_FILE, $this->encode($document));

    foreach ($artifacts as $relative => $content) {
      File::dump($run_dir . '/' . $relative, $this->redactor->redactString($content));
    }

    return $run_dir;
  }

  /**
   * Redacts and encodes the document as pretty JSON with a trailing newline.
   *
   * @param array<string, mixed> $document
   *   The results document.
   *
   * @return string
   *   The encoded JSON.
   */
  protected function encode(array $document): string {
    return json_encode($this->redactor->redactDocument($document), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
  }

}
