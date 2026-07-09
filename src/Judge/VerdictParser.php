<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Judge;

use AlexSkrypnyk\SkillTest\Config\Data;

/**
 * Turns the raw text a judge returns into a structured verdict, defensively.
 *
 * A model told to "return JSON only" still wraps it in prose or markdown
 * fences, so parsing is hardened rather than a bare `json_decode`: the first
 * balanced JSON object is extracted from surrounding noise, code fences are
 * stripped, and every field is clamped to its expected type (ids to ints with a
 * positional fallback, `pass`/`unknown` to booleans). A per-criterion or
 * top-level `unknown` marks an abstention. Anything that cannot yield a usable
 * verdict - undecodable text, or an object with no criteria - is a judge
 * failure raised as a {@see JudgeException}, never a silent pass.
 */
final readonly class VerdictParser {

  /**
   * Parses a raw judge response into a verdict.
   *
   * @param string $raw
   *   The judge's stdout, which may be wrapped in prose or code fences.
   *
   * @return \AlexSkrypnyk\SkillTest\Judge\JudgeVerdict
   *   The parsed verdict.
   *
   * @throws \AlexSkrypnyk\SkillTest\Judge\JudgeException
   *   When the response cannot be decoded to an object carrying criteria.
   */
  public function parse(string $raw): JudgeVerdict {
    $decoded = json_decode(self::extractObject($raw), TRUE);

    if (!is_array($decoded)) {
      throw new JudgeException('the judge verdict is not valid JSON.');
    }

    $entries = $decoded['criteria'] ?? NULL;

    if (!is_array($entries)) {
      throw new JudgeException("the judge verdict has no 'criteria' array.");
    }

    $top_unknown = Data::toBoolOrNull($decoded['unknown'] ?? NULL) ?? FALSE;
    $criteria = [];
    $position = 0;

    foreach ($entries as $entry) {
      if (!is_array($entry)) {
        continue;
      }

      $position++;
      $id = Data::toIntOrNull($entry['id'] ?? NULL) ?? $position;
      $unknown = $top_unknown || (Data::toBoolOrNull($entry['unknown'] ?? NULL) ?? FALSE);
      $pass = !$unknown && (Data::toBoolOrNull($entry['pass'] ?? NULL) ?? FALSE);

      $criteria[] = new JudgeCriterion($id, $pass, $unknown);
    }

    if ($criteria === []) {
      throw new JudgeException('the judge verdict scored no criteria.');
    }

    return new JudgeVerdict($criteria, Data::toStringOrNull($decoded['reasoning'] ?? NULL) ?? '');
  }

  /**
   * Extracts the first balanced JSON object from noisy judge output.
   *
   * A markdown code fence is unwrapped first, then the first `{` and its
   * matching `}` are located with brace counting that ignores braces inside
   * strings, so leading prose and trailing commentary are discarded. When no
   * object is present the trimmed input is returned unchanged so the decode
   * step fails cleanly.
   *
   * @param string $raw
   *   The raw judge output.
   *
   * @return string
   *   The extracted JSON object, or the trimmed input when none is found.
   */
  protected static function extractObject(string $raw): string {
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
