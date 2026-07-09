<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Run;

/**
 * Scrubs environment secret values out of persisted artifacts.
 *
 * Before any results file, transcript, or session log is written, the value of
 * every credential-bearing environment variable is replaced with a fixed
 * placeholder wherever it appears verbatim, so a token exported for an llm run
 * never lands on disk. Redaction is on by default; a disabled redactor carries
 * no secrets and passes text through untouched.
 *
 * A variable is treated as credential-bearing when its name contains a
 * delimited credential word (`API_KEY`, `*_TOKEN`, `*_SECRET`, `PASSWORD`,
 * `PASSPHRASE`, `CREDENTIALS`), which catches `ANTHROPIC_API_KEY` and
 * `CLAUDE_CODE_OAUTH_TOKEN` without matching incidental names like `MONKEY_ID`.
 * Redacting the value of every environment variable verbatim (`HOME`, `PATH`,
 * `LANG`) would corrupt legitimate content, so detection is scoped to the
 * credential-shaped names and to values long enough to be a real secret.
 */
final readonly class Redactor {

  /**
   * The placeholder written in place of a redacted secret value.
   */
  public const string PLACEHOLDER = '[REDACTED]';

  /**
   * The shortest environment value treated as a secret worth redacting.
   *
   * A one or two character value (a flag, an index) shared by an env var and
   * ordinary content would redact that content wherever it appeared; a real
   * credential is always longer than this floor.
   */
  protected const int MIN_SECRET_LENGTH = 4;

  /**
   * Matches an environment variable name that carries a credential value.
   */
  protected const string CREDENTIAL_NAME = '/(?:^|_)(?:KEY|TOKEN|SECRET|PASSWORD|PASSPHRASE|CREDENTIALS?)(?:_|$)/i';

  /**
   * Constructs a Redactor.
   *
   * @param string[] $secrets
   *   The distinct secret values to scrub, ordered longest first so a secret
   *   that is a substring of another is replaced whole before the shorter one
   *   can match a fragment of it.
   */
  public function __construct(
    protected array $secrets,
  ) {}

  /**
   * Builds a redactor from an environment map.
   *
   * @param array<mixed> $env
   *   The environment variables, keyed by name, as returned by `getenv()`.
   * @param bool $enabled
   *   Whether redaction is active; when FALSE the redactor is a no-op.
   *
   * @return self
   *   The redactor seeded with the credential values found in the environment.
   */
  public static function fromEnvironment(array $env, bool $enabled): self {
    if (!$enabled) {
      return new self([]);
    }

    $secrets = [];

    foreach ($env as $name => $value) {
      if (!is_string($name) || !is_string($value)) {
        continue;
      }

      if (strlen($value) < self::MIN_SECRET_LENGTH) {
        continue;
      }

      if (preg_match(self::CREDENTIAL_NAME, $name) === 1) {
        $secrets[$value] = $value;
      }
    }

    $secrets = array_values($secrets);
    usort($secrets, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

    return new self($secrets);
  }

  /**
   * Replaces every verbatim occurrence of a secret in a string.
   *
   * @param string $text
   *   The text to scrub.
   *
   * @return string
   *   The text with secret values replaced by the placeholder.
   */
  public function redactString(string $text): string {
    return str_replace($this->secrets, self::PLACEHOLDER, $text);
  }

  /**
   * Recursively redacts every string leaf of a document.
   *
   * Keys are structural and left intact; only string values are scrubbed, and
   * nested arrays are walked so a secret cannot hide inside a sub-document.
   *
   * @param array<mixed> $document
   *   The document to scrub.
   *
   * @return array<mixed>
   *   The document with every string leaf redacted.
   */
  public function redactDocument(array $document): array {
    $out = [];

    foreach ($document as $key => $value) {
      if (is_string($value)) {
        $out[$key] = $this->redactString($value);
      }
      elseif (is_array($value)) {
        $out[$key] = $this->redactDocument($value);
      }
      else {
        $out[$key] = $value;
      }
    }

    return $out;
  }

}
