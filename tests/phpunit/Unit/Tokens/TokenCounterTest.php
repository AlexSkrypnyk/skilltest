<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Tokens;

use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Tokens\TokenCounter;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class TokenCounterTest.
 *
 * Unit test for the token counter: the estimation heuristic pins its
 * reference values, byte-pair encoding merges by vocabulary rank, and a
 * broken vocabulary is a hard error rather than a silent method change.
 */
#[CoversClass(TokenCounter::class)]
final class TokenCounterTest extends TestCase {

  /**
   * A tiny handcrafted tiktoken-format vocabulary for deterministic tests.
   *
   * Base64-encoded tokens, one per line with their rank: the single letters
   * of 'hello', its two letter pairs, the words 'hell' and 'hello', the
   * space-prefixed ' world', 'it', and the contraction suffix.
   */
  protected const string VOCAB = "aA== 0\nZQ== 1\nbA== 2\nbw== 3\naGU= 4\nbGw= 5\naGVsbA== 6\naGVsbG8= 7\nIHdvcmxk 8\naXQ= 9\nJ3M= 10\n";

  #[DataProvider('dataProviderEstimate')]
  public function testEstimate(string $text, int $expected): void {
    $counter = new TokenCounter();

    $this->assertSame(TokenCounter::METHOD_ESTIMATE, $counter->method());
    $this->assertSame($expected, $counter->count($text));
    $this->assertSame($expected, $counter->count($text), 'counts are stable across runs');
  }

  public static function dataProviderEstimate(): \Iterator {
    yield 'empty text is zero' => ['', 0];
    yield 'exact multiple of four bytes' => ['abcd', 1];
    yield 'partial chunk rounds up' => ['abcde', 2];
    yield 'reference markdown snippet' => ["# Title\n\nA body line with words.\n", 9];
    yield 'multibyte counts bytes not characters' => ['héllo', 2];
  }

  #[DataProvider('dataProviderBpe')]
  public function testBpe(string $text, int $expected): void {
    $counter = new TokenCounter($this->vocabFile(self::VOCAB));

    $this->assertSame(TokenCounter::METHOD_BPE, $counter->method());
    $this->assertSame($expected, $counter->count($text));
    $this->assertSame($expected, $counter->count($text), 'counts are stable across runs');
  }

  public static function dataProviderBpe(): \Iterator {
    yield 'empty text is zero' => ['', 0];
    yield 'whole piece in the vocabulary is one token' => ['hello', 1];
    yield 'space-prefixed piece matches the splitter and the vocabulary' => ['hello world', 2];
    yield 'greedy merge follows the lowest rank' => ['helo', 3];
    yield 'repeated pair merges pairwise' => ['llll', 2];
    yield 'contraction splits into its own piece' => ["it's", 2];
    yield 'unknown symbol counts one token per byte' => ['hello!', 2];
    yield 'unknown multibyte letters count one token per byte' => ['é', 2];
    yield 'newlines split from words' => ["hello\nhello", 3];
  }

  public function testBpeFallsBackToEstimateForInvalidUtf8(): void {
    $counter = new TokenCounter($this->vocabFile(self::VOCAB));

    $this->assertSame(1, $counter->count("\xFF\xFE\xFA\xFB"));
  }

  public function testBpeSurfacesNonUtf8EngineFailures(): void {
    $counter = new TokenCounter($this->vocabFile(self::VOCAB));
    $jit = (string) ini_get('pcre.jit');
    $backtrack = (string) ini_get('pcre.backtrack_limit');

    // The JIT does not consult the backtrack limit, so it is disabled to make
    // the whitespace-run lookahead exhaust the limit deterministically.
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1');

    try {
      $this->expectException(\RuntimeException::class);
      $this->expectExceptionMessage('token pre-split failed');

      $counter->count(str_repeat(' ', 200) . 'x');
    }
    finally {
      ini_set('pcre.jit', $jit);
      ini_set('pcre.backtrack_limit', $backtrack);
    }
  }

  public function testMissingVocabIsConfigError(): void {
    $counter = new TokenCounter(vfsStream::setup('root')->url() . '/absent.tiktoken');

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('cannot be read');

    $counter->count('hello');
  }

  #[DataProvider('dataProviderMalformedVocabIsConfigError')]
  public function testMalformedVocabIsConfigError(string $vocab, string $message): void {
    $counter = new TokenCounter($this->vocabFile($vocab));

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage($message);

    $counter->count('hello');
  }

  public static function dataProviderMalformedVocabIsConfigError(): \Iterator {
    yield 'empty file has no ranks' => ["\n\n", 'contains no ranks'];
    yield 'line without a rank' => ["aGVsbG8=\n", "line 1 is not a 'base64token rank' pair"];
    yield 'line with a non-numeric rank' => ["aGVsbG8= x\n", "line 1 is not a 'base64token rank' pair"];
    yield 'line with invalid base64' => ["!!notbase64!! 1\n", "line 1 is not a 'base64token rank' pair"];
    yield 'later malformed line names its number' => ["aGVsbG8= 1\nbroken\n", "line 2 is not a 'base64token rank' pair"];
  }

  /**
   * Writes a vocabulary file into a fresh vfs root.
   *
   * @param string $content
   *   The vocabulary content.
   *
   * @return string
   *   The vocabulary file URL.
   */
  protected function vocabFile(string $content): string {
    return vfsStream::setup('root', NULL, ['vocab.tiktoken' => $content])->url() . '/vocab.tiktoken';
  }

}
