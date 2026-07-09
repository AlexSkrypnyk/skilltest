<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Run\Report;

use AlexSkrypnyk\SkillTest\Validation\ValidationMessage;

/**
 * Parses and validates the run command's reporting options in one place.
 *
 * `--json`, `--format`, `--reporter`, `--session-log`/`--session-dir`, and the
 * `--output`/`--output-dir` persistence flags all shape what the run produces
 * beyond the human report. Collecting their parsing and validation here keeps
 * the command thin and lets an impossible combination - two stdout formats, an
 * unknown reporter, a session log with nowhere to write - fail as a config
 * error before the suite runs, with every problem reported at once.
 */
final readonly class ReportOptions {

  /**
   * The one `--format` value the run command renders beyond the human report.
   */
  public const string GITHUB_COMMENT = 'github-comment';

  /**
   * The reporter spec prefix that selects the JUnit XML reporter.
   */
  public const string JUNIT_PREFIX = 'junit:';

  /**
   * Constructs a ReportOptions.
   *
   * @param bool $json
   *   Whether the JSON stdout contract is in effect.
   * @param bool $githubComment
   *   Whether the github-comment stdout format is in effect.
   * @param string[] $junitTargets
   *   The file paths each JUnit reporter writes to.
   * @param string|null $sessionDir
   *   The directory the session log is written to, or NULL when off.
   * @param string|null $outputFile
   *   The `--output` file destination, or NULL.
   * @param string|null $outputDir
   *   The `--output-dir` parent directory, or NULL.
   * @param \AlexSkrypnyk\SkillTest\Validation\ValidationMessage[] $errors
   *   The validation errors; empty when every option is coherent.
   */
  protected function __construct(
    public bool $json,
    public bool $githubComment,
    public array $junitTargets,
    public ?string $sessionDir,
    public ?string $outputFile,
    public ?string $outputDir,
    public array $errors,
  ) {}

  /**
   * Parses the raw option values into a validated ReportOptions.
   *
   * @param bool $json
   *   The `--json` flag.
   * @param string|null $format
   *   The `--format` value, or NULL when unset.
   * @param string[] $reporters
   *   The `--reporter` specs, in order.
   * @param bool $session_log
   *   The `--session-log` flag.
   * @param string|null $session_dir
   *   The `--session-dir` value, or NULL when unset.
   * @param string|null $output_file
   *   The `--output` value, or NULL when unset.
   * @param string|null $output_dir
   *   The `--output-dir` value, or NULL when unset.
   *
   * @return self
   *   The parsed options, carrying any validation errors.
   */
  public static function parse(bool $json, ?string $format, array $reporters, bool $session_log, ?string $session_dir, ?string $output_file, ?string $output_dir): self {
    $errors = [];

    $github_comment = $format === self::GITHUB_COMMENT;

    if ($format !== NULL && !$github_comment) {
      $errors[] = ValidationMessage::error('', '', sprintf("unknown format '%s'; the run command supports: %s (use --json for JSON output).", $format, self::GITHUB_COMMENT));
    }

    if ($json && $github_comment) {
      $errors[] = ValidationMessage::error('', '', 'choose a single stdout format: --json or --format github-comment, not both.');
    }

    $junit = [];

    foreach ($reporters as $reporter) {
      if (!str_starts_with($reporter, self::JUNIT_PREFIX)) {
        $errors[] = ValidationMessage::error('', '', sprintf("unknown reporter '%s'; supported: junit:<path>.", $reporter));

        continue;
      }

      $path = substr($reporter, strlen(self::JUNIT_PREFIX));

      if ($path === '') {
        $errors[] = ValidationMessage::error('', '', 'reporter junit requires a path (junit:<path>).');

        continue;
      }

      $junit[] = $path;
    }

    $session = NULL;

    if ($session_log && $session_dir === NULL) {
      $errors[] = ValidationMessage::error('', '', '--session-log requires --session-dir <dir>.');
    }
    elseif ($session_log) {
      $session = $session_dir;
    }

    return new self($json, $github_comment, $junit, $session, $output_file, $output_dir, $errors);
  }

  /**
   * Whether every option is coherent.
   *
   * @return bool
   *   TRUE when there are no validation errors.
   */
  public function valid(): bool {
    return $this->errors === [];
  }

  /**
   * The stdout format the run should emit.
   *
   * @return string
   *   One of `github-comment`, `json`, or `human`.
   */
  public function stdoutFormat(): string {
    if ($this->githubComment) {
      return 'github-comment';
    }

    return $this->json ? 'json' : 'human';
  }

  /**
   * Whether any requested output needs the results document to be built.
   *
   * @return bool
   *   TRUE when a reporter, a stdout document, or a persistence flag is active.
   */
  public function wantsDocument(): bool {
    return $this->json || $this->writesArtifacts();
  }

  /**
   * Whether any output leaves the process as a redactable external artifact.
   *
   * The persisted results, a JUnit file, the session log, and the PR-bound
   * github-comment all carry run detail off the machine, so a disabled
   * redaction warning is owed whenever one of them is active; plain `--json`
   * to stdout is a local debugging convenience and is excluded.
   *
   * @return bool
   *   TRUE when at least one external artifact is written or emitted.
   */
  public function writesArtifacts(): bool {
    return $this->githubComment || $this->junitTargets !== [] || $this->sessionDir !== NULL || $this->outputFile !== NULL || $this->outputDir !== NULL;
  }

}
