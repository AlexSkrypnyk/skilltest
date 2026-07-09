<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tokens;

/**
 * One file's token count now against its count at the compared ref.
 *
 * A comparison row carries the two counts and derives everything a report or
 * a gate needs from them: whether the file is new at the ref, the absolute
 * delta, and the growth percentage. Growth is undefined for a new file (there
 * is nothing to grow from) and for a file that was empty at the ref (any
 * growth from zero is unbounded), so `growthPct()` is NULL in both cases and
 * gating logic handles them explicitly instead of dividing by zero.
 */
final readonly class TokenDelta {

  /**
   * Constructs a TokenDelta.
   *
   * @param string $path
   *   The file path relative to the repository root.
   * @param int $tokens
   *   The current token count.
   * @param int|null $refTokens
   *   The token count at the compared ref, or NULL when the file is new.
   */
  public function __construct(
    public string $path,
    public int $tokens,
    public ?int $refTokens,
  ) {}

  /**
   * Whether the file does not exist at the compared ref.
   *
   * @return bool
   *   TRUE when the file is new.
   */
  public function isNew(): bool {
    return $this->refTokens === NULL;
  }

  /**
   * The token count change against the ref.
   *
   * @return int
   *   The delta; a new file's whole count.
   */
  public function delta(): int {
    return $this->tokens - ($this->refTokens ?? 0);
  }

  /**
   * The growth percentage against the ref.
   *
   * @return float|null
   *   The growth rounded to one decimal, or NULL when the file is new or was
   *   empty at the ref.
   */
  public function growthPct(): ?float {
    if ($this->refTokens === NULL || $this->refTokens === 0) {
      return NULL;
    }

    return round(($this->tokens - $this->refTokens) / $this->refTokens * 100, 1);
  }

  /**
   * Returns the row as a plain array for machine output.
   *
   * @return array{path: string, tokens: int, ref_tokens: int|null, delta: int, growth_pct: float|null, new: bool}
   *   The row fields.
   */
  public function toArray(): array {
    return [
      'path' => $this->path,
      'tokens' => $this->tokens,
      'ref_tokens' => $this->refTokens,
      'delta' => $this->delta(),
      'growth_pct' => $this->growthPct(),
      'new' => $this->isNew(),
    ];
  }

}
