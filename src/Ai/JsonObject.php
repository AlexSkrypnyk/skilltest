<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Ai;

/**
 * Extracts the first balanced JSON object from noisy model output.
 *
 * A model told to return JSON only still wraps it in prose or markdown
 * fences. Extraction unwraps a code fence, then locates the first `{` and its
 * matching `}` by brace counting that ignores braces inside strings. When no
 * object is present the trimmed input is returned unchanged rather than an
 * error.
 */
final readonly class JsonObject {

  /**
   * Extracts the first balanced JSON object from noisy model output.
   *
   * @param string $raw
   *   The raw model output, which may be wrapped in prose or code fences.
   *
   * @return string
   *   The extracted JSON object, or the trimmed input when none is found.
   */
  public static function extract(string $raw): string {
    $text = trim($raw);

    if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $matches) === 1) {
      $text = trim($matches[1]);
    }

    $start = strpos($text, '{');

    if ($start === FALSE) {
      return $text;
    }

    $depth = 0;
    $in_string = FALSE;
    $escaped = FALSE;
    $length = strlen($text);

    for ($i = $start; $i < $length; $i++) {
      $char = $text[$i];

      if ($in_string) {
        if ($escaped) {
          $escaped = FALSE;
        }
        elseif ($char === '\\') {
          $escaped = TRUE;
        }
        elseif ($char === '"') {
          $in_string = FALSE;
        }

        continue;
      }

      if ($char === '"') {
        $in_string = TRUE;
      }
      elseif ($char === '{') {
        $depth++;
      }
      elseif ($char === '}' && --$depth === 0) {
        return substr($text, $start, $i - $start + 1);
      }
    }

    return substr($text, $start);
  }

}
