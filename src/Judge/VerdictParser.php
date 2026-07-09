<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Judge;

use AlexSkrypnyk\SkillTest\Ai\JsonObject;
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
    $decoded = json_decode(JsonObject::extract($raw), TRUE);

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

}
