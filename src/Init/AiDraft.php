<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Init;

use AlexSkrypnyk\SkillTest\Config\Data;

/**
 * A model's draft of the parts of an `eval.yaml` that need judgement.
 *
 * `init --ai` asks the model for tasks that trigger the skill, command
 * patterns the skill should run, and binary rubric criteria - each tagged with
 * a confidence so low-confidence guesses can be flagged for human review. The
 * model is untrusted input: the JSON object is located even when wrapped in
 * prose or a code fence, structurally-invalid entries are dropped, scalars are
 * collapsed to a single line so they render safely, and anything unparseable
 * yields NULL so the caller falls back to the deterministic template.
 */
final readonly class AiDraft {

  /**
   * The confidence marker that flags an entry for human review.
   */
  public const string CONFIDENCE_LOW = 'low';

  /**
   * Constructs an AiDraft.
   *
   * @param array<int, array{name: string, prompt: string, low: bool}> $tasks
   *   The drafted llm tasks.
   * @param array<int, array{label: string, pattern: string, low: bool}> $commands
   *   The drafted required command patterns.
   * @param array<int, array{text: string, low: bool}> $rubric
   *   The drafted binary rubric criteria.
   */
  public function __construct(
    public array $tasks,
    public array $commands,
    public array $rubric,
  ) {}

  /**
   * Parses a model reply into a draft.
   *
   * @param string $response
   *   The raw model stdout.
   *
   * @return self|null
   *   The parsed draft, or NULL when no JSON object could be decoded.
   */
  public static function fromResponse(string $response): ?self {
    $data = self::decode($response);

    if ($data === NULL) {
      return NULL;
    }

    return new self(self::tasks($data), self::commands($data), self::rubric($data));
  }

  /**
   * Decodes the JSON object from a model reply.
   *
   * @param string $response
   *   The raw model stdout.
   *
   * @return array<mixed>|null
   *   The decoded object, or NULL when none is present. A JSON array is not an
   *   object and is rejected so the caller falls back to the template.
   */
  protected static function decode(string $response): ?array {
    $trimmed = trim($response);

    if ($trimmed === '') {
      return NULL;
    }

    $whole = self::object(json_decode($trimmed, TRUE));

    if ($whole !== NULL) {
      return $whole;
    }

    $start = strpos($trimmed, '{');
    $end = strrpos($trimmed, '}');

    if ($start === FALSE || $end === FALSE || $end < $start) {
      return NULL;
    }

    return self::object(json_decode(substr($trimmed, $start, $end - $start + 1), TRUE));
  }

  /**
   * Narrows a decoded value to a JSON object (an associative array).
   *
   * @param mixed $decoded
   *   A json_decode result.
   *
   * @return array<mixed>|null
   *   The value when it is a non-list array, NULL otherwise.
   */
  protected static function object(mixed $decoded): ?array {
    return is_array($decoded) && !array_is_list($decoded) ? $decoded : NULL;
  }

  /**
   * Extracts the drafted tasks.
   *
   * @param array<mixed> $data
   *   The decoded object.
   *
   * @return array<int, array{name: string, prompt: string, low: bool}>
   *   The valid tasks, in order.
   */
  protected static function tasks(array $data): array {
    $out = [];

    foreach (Data::toArrayList(Data::get($data, 'tasks')) as $entry) {
      $name = self::clean(Data::toStringOrNull(Data::get($entry, 'name')));
      $prompt = self::clean(Data::toStringOrNull(Data::get($entry, 'prompt')));

      if ($name === '' || $prompt === '') {
        continue;
      }

      $out[] = ['name' => $name, 'prompt' => $prompt, 'low' => self::isLow($entry)];
    }

    return $out;
  }

  /**
   * Extracts the drafted required command patterns.
   *
   * @param array<mixed> $data
   *   The decoded object.
   *
   * @return array<int, array{label: string, pattern: string, low: bool}>
   *   The valid command patterns, in order.
   */
  protected static function commands(array $data): array {
    $out = [];

    foreach (Data::toArrayList(Data::get($data, 'commands')) as $entry) {
      $label = self::clean(Data::toStringOrNull(Data::get($entry, 'label')));
      $pattern = self::clean(Data::toStringOrNull(Data::get($entry, 'pattern')));

      if ($label === '' || $pattern === '') {
        continue;
      }

      $out[] = ['label' => $label, 'pattern' => $pattern, 'low' => self::isLow($entry)];
    }

    return $out;
  }

  /**
   * Extracts the drafted rubric criteria.
   *
   * Each entry may be a bare string or an object with `text` and `confidence`.
   *
   * @param array<mixed> $data
   *   The decoded object.
   *
   * @return array<int, array{text: string, low: bool}>
   *   The valid criteria, in order.
   */
  protected static function rubric(array $data): array {
    $out = [];

    foreach (Data::toArray(Data::get($data, 'rubric')) as $entry) {
      $text = self::clean(Data::toStringOrNull(is_array($entry) ? Data::get($entry, 'text') : $entry));
      $low = is_array($entry) && self::isLow($entry);

      if ($text === '') {
        continue;
      }

      $out[] = ['text' => $text, 'low' => $low];
    }

    return $out;
  }

  /**
   * Whether an entry is flagged low-confidence.
   *
   * @param array<mixed> $entry
   *   The entry.
   *
   * @return bool
   *   TRUE when the entry's confidence is "low".
   */
  protected static function isLow(array $entry): bool {
    $confidence = Data::toStringOrNull(Data::get($entry, 'confidence'));

    return $confidence !== NULL && strtolower(trim($confidence)) === self::CONFIDENCE_LOW;
  }

  /**
   * Collapses a scalar to a single trimmed line.
   *
   * @param string|null $value
   *   The raw value, or NULL.
   *
   * @return string
   *   The value with runs of whitespace collapsed to single spaces; empty
   *   string when the value is NULL.
   */
  protected static function clean(?string $value): string {
    if ($value === NULL) {
      return '';
    }

    return trim((string) preg_replace('/\s+/', ' ', $value));
  }

}
