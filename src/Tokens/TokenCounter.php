<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tokens;

use AlexSkrypnyk\SkillTest\Exception\ConfigException;

/**
 * The one token counter behind every token-accounting feature.
 *
 * The `tokens` command and the `structure.token-budget` check must agree on
 * every count, so both go through this class. Two methods are supported: real
 * byte-pair encoding against a user-supplied tiktoken-format vocabulary, and a
 * documented estimation heuristic when no vocabulary is configured. The
 * estimator is the default on purpose - the deterministic suite runs with no
 * network and the distributed executable bundles no multi-megabyte vocabulary,
 * so exact counts are an explicit, offline opt-in (drop a vocabulary file in
 * the repository and point at it). Counts are pure functions of the text and
 * the vocabulary, so they are stable across runs and environments. A
 * configured vocabulary that cannot be read or parsed is a hard configuration
 * error, never a silent fall back to estimation - the two methods produce
 * different numbers, and a budget gate must not change scales quietly.
 */
final class TokenCounter {

  /**
   * The method name reported for byte-pair-encoded counts.
   */
  public const string METHOD_BPE = 'bpe';

  /**
   * The method name reported for estimated counts.
   */
  public const string METHOD_ESTIMATE = 'estimate';

  /**
   * Bytes per token assumed by the estimation heuristic.
   *
   * The widely used rule of thumb for English text and markup: one token per
   * ~4 bytes. Estimates are documented as approximate; only the stability of
   * the number matters for budget gating.
   */
  public const int ESTIMATE_BYTES_PER_TOKEN = 4;

  /**
   * The pre-tokeniser pattern applied before byte-pair merging.
   *
   * The cl100k-style splitter: contractions, letter runs with an optional
   * leading symbol, digit runs capped at three, symbol runs with an optional
   * leading space, and whitespace runs. Splitting first keeps merges inside
   * word-like pieces, which is what makes BPE counts match tokenizer output.
   */
  public const string SPLIT_PATTERN = "/(?i:'s|'t|'re|'ve|'m|'ll|'d)|[^\\r\\n\\p{L}\\p{N}]?\\p{L}+|\\p{N}{1,3}| ?[^\\s\\p{L}\\p{N}]+[\\r\\n]*|\\s*[\\r\\n]+|\\s+(?!\\S)|\\s+/u";

  /**
   * The parsed vocabulary ranks keyed by token bytes, NULL until loaded.
   *
   * @var array<string, int>|null
   */
  protected ?array $ranks = NULL;

  /**
   * Constructs a TokenCounter.
   *
   * @param string|null $vocabPath
   *   A tiktoken-format vocabulary file (`base64token rank` per line) for
   *   exact byte-pair-encoded counts, or NULL to use the estimation heuristic.
   */
  public function __construct(protected ?string $vocabPath = NULL) {}

  /**
   * The counting method this counter reports its numbers under.
   *
   * @return string
   *   Either {@see self::METHOD_BPE} or {@see self::METHOD_ESTIMATE}.
   */
  public function method(): string {
    return $this->vocabPath === NULL ? self::METHOD_ESTIMATE : self::METHOD_BPE;
  }

  /**
   * Counts the tokens in a text.
   *
   * @param string $text
   *   The text to count.
   *
   * @return int
   *   The token count.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When a configured vocabulary cannot be read or parsed.
   */
  public function count(string $text): int {
    if ($this->vocabPath === NULL) {
      return $this->estimate($text);
    }

    return $this->countBpe($text, $this->ranks());
  }

  /**
   * Estimates the token count from the byte length.
   *
   * @param string $text
   *   The text to count.
   *
   * @return int
   *   The estimated token count.
   */
  protected function estimate(string $text): int {
    return (int) ceil(strlen($text) / self::ESTIMATE_BYTES_PER_TOKEN);
  }

  /**
   * Counts tokens by byte-pair encoding against the vocabulary.
   *
   * @param string $text
   *   The text to count.
   * @param array<string, int> $ranks
   *   The vocabulary ranks keyed by token bytes.
   *
   * @return int
   *   The token count.
   */
  protected function countBpe(string $text, array $ranks): int {
    if ($text === '') {
      return 0;
    }

    // The splitter is a Unicode pattern, so bytes that are not valid UTF-8
    // cannot be pre-tokenised; such text gets the estimation heuristic rather
    // than an O(n^2) merge over the whole blob or a hard failure. Only that
    // one failure downgrades: any other engine failure (backtrack, recursion,
    // or JIT stack limits) must surface, or an exact count would silently
    // change scales.
    if (preg_match_all(self::SPLIT_PATTERN, $text, $matches) === FALSE) {
      if (preg_last_error() === PREG_BAD_UTF8_ERROR) {
        return $this->estimate($text);
      }

      throw new \RuntimeException('token pre-split failed: ' . preg_last_error_msg() . '.');
    }

    $count = 0;

    foreach ($matches[0] as $piece) {
      $count += $this->pieceTokens($piece, $ranks);
    }

    return $count;
  }

  /**
   * Counts the tokens one pre-split piece merges down to.
   *
   * Standard byte-pair encoding: start from single bytes and repeatedly merge
   * the adjacent pair whose joined bytes carry the lowest vocabulary rank,
   * until no adjacent pair is in the vocabulary. Bytes the vocabulary does not
   * cover remain single segments and count one token each.
   *
   * @param string $piece
   *   The pre-split piece.
   * @param array<string, int> $ranks
   *   The vocabulary ranks keyed by token bytes.
   *
   * @return int
   *   The token count for the piece.
   */
  protected function pieceTokens(string $piece, array $ranks): int {
    if (isset($ranks[$piece])) {
      return 1;
    }

    $parts = str_split($piece);

    while (count($parts) > 1) {
      $best_rank = NULL;
      $best_index = NULL;
      $last = count($parts) - 1;

      for ($index = 0; $index < $last; $index++) {
        $rank = $ranks[$parts[$index] . $parts[$index + 1]] ?? NULL;

        if ($rank !== NULL && ($best_rank === NULL || $rank < $best_rank)) {
          $best_rank = $rank;
          $best_index = $index;
        }
      }

      if ($best_index === NULL) {
        break;
      }

      array_splice($parts, $best_index, 2, [$parts[$best_index] . $parts[$best_index + 1]]);
    }

    return count($parts);
  }

  /**
   * The vocabulary ranks, loaded and memoised on first use.
   *
   * @return array<string, int>
   *   The ranks keyed by token bytes.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the vocabulary cannot be read or parsed.
   */
  protected function ranks(): array {
    if ($this->ranks === NULL) {
      $this->ranks = $this->loadVocab((string) $this->vocabPath);
    }

    return $this->ranks;
  }

  /**
   * Parses a tiktoken-format vocabulary file into a rank map.
   *
   * @param string $path
   *   The vocabulary file path.
   *
   * @return array<string, int>
   *   The ranks keyed by token bytes.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the file cannot be read, a line is malformed, or no ranks parse.
   */
  protected function loadVocab(string $path): array {
    $content = @file_get_contents($path);

    if ($content === FALSE) {
      throw new ConfigException(sprintf("vocabulary file '%s' cannot be read.", $path), $path);
    }

    $ranks = [];

    foreach (explode("\n", $content) as $index => $line) {
      $trimmed = trim($line);

      if ($trimmed === '') {
        continue;
      }

      $parts = explode(' ', $trimmed);
      $bytes = count($parts) === 2 ? base64_decode($parts[0], TRUE) : FALSE;

      if ($bytes === FALSE || $bytes === '' || !ctype_digit($parts[1])) {
        throw new ConfigException(sprintf("vocabulary file '%s' line %d is not a 'base64token rank' pair.", $path, $index + 1), $path);
      }

      $ranks[$bytes] = (int) $parts[1];
    }

    if ($ranks === []) {
      throw new ConfigException(sprintf("vocabulary file '%s' contains no ranks.", $path), $path);
    }

    return $ranks;
  }

}
