<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live\Mcp;

use AlexSkrypnyk\SkillTest\Config\Pcre;
use JsonSchema\Validator;

/**
 * Matches a mocked tool call's arguments against a declared response's matcher.
 *
 * A mock declares one of three matcher kinds per response, and this is the one
 * place each is evaluated so the stdio server and any caller agree on what
 * "matches" means. `exact` is a type-strict, order-independent deep equality
 * over the whole argument object; `regex` requires every named field's
 * stringified value to match its delimiter-less pattern, the same pattern
 * dialect the contract engine uses; `schema` validates the arguments against a
 * JSON Schema. Every method is pure and static so matching is unit-testable in
 * isolation from the server loop. When nothing matches, `score` ranks the
 * declared responses by how close each came, so the failure names the nearest
 * fixture rather than a bare "no match".
 */
final class McpMatcher {

  /**
   * The exact full-argument matcher kind.
   */
  public const string EXACT = 'exact';

  /**
   * The per-field regular-expression matcher kind.
   */
  public const string REGEX = 'regex';

  /**
   * The JSON Schema matcher kind.
   */
  public const string SCHEMA = 'schema';

  /**
   * Whether a normalised response's matcher accepts the given arguments.
   *
   * @param array<mixed> $response
   *   The normalised response, carrying its matcher kind and matcher.
   * @param array<mixed> $arguments
   *   The tool call arguments.
   *
   * @return bool
   *   TRUE when the matcher accepts the arguments.
   */
  public static function matches(array $response, array $arguments): bool {
    return match ($response['kind']) {
      self::EXACT => self::exact(is_array($response['matcher']) ? $response['matcher'] : [], $arguments),
      self::REGEX => self::regex(is_array($response['matcher']) ? $response['matcher'] : [], $arguments),
      // A response is normalised to exactly one of the three known kinds, so no
      // default arm is reachable; SCHEMA is the remaining case.
      default => self::schema($response['matcher'], $arguments),
    };
  }

  /**
   * Whether the arguments deep-equal the matcher: type-strict, key-order free.
   *
   * @param array<mixed> $matcher
   *   The exact matcher.
   * @param array<mixed> $arguments
   *   The tool call arguments.
   *
   * @return bool
   *   TRUE when the two are equal as canonical JSON.
   */
  public static function exact(array $matcher, array $arguments): bool {
    return self::canonical($matcher) === self::canonical($arguments);
  }

  /**
   * Whether every named field's stringified value matches its pattern.
   *
   * A field named by the matcher but absent from the arguments never matches.
   *
   * @param array<mixed> $matcher
   *   The field-to-pattern map.
   * @param array<mixed> $arguments
   *   The tool call arguments.
   *
   * @return bool
   *   TRUE when every field's value matches its pattern.
   */
  public static function regex(array $matcher, array $arguments): bool {
    foreach ($matcher as $field => $pattern) {
      if (!array_key_exists($field, $arguments)) {
        return FALSE;
      }

      if (preg_match(Pcre::delimit(self::stringify($pattern)), self::stringify($arguments[$field])) !== 1) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Whether the arguments validate against a JSON Schema.
   *
   * @param mixed $schema
   *   The JSON Schema, as a nested array.
   * @param array<mixed> $arguments
   *   The tool call arguments.
   *
   * @return bool
   *   TRUE when the arguments conform to the schema.
   */
  public static function schema(mixed $schema, array $arguments): bool {
    $validator = new Validator();
    $data = self::toObjectGraph($arguments);
    $validator->validate($data, self::toObjectGraph($schema));

    return $validator->isValid();
  }

  /**
   * A closeness score ranking how nearly a response matched the arguments.
   *
   * Used only to name the nearest fixture when nothing matched, so it needs to
   * be a stable, monotonic ranking rather than a precise metric: for `exact`
   * and `regex` it counts the fields that individually agreed, and for `schema`
   * the declared property and required names that appear in the arguments.
   *
   * @param array<mixed> $response
   *   The normalised response.
   * @param array<mixed> $arguments
   *   The tool call arguments.
   *
   * @return int
   *   The number of fields that agreed; higher is closer.
   */
  public static function score(array $response, array $arguments): int {
    $matcher = $response['matcher'];

    if ($response['kind'] === self::SCHEMA) {
      return self::schemaScore(is_array($matcher) ? $matcher : [], $arguments);
    }

    $count = 0;

    foreach (is_array($matcher) ? $matcher : [] as $field => $expected) {
      if (!array_key_exists($field, $arguments)) {
        continue;
      }

      if ($response['kind'] === self::REGEX) {
        $count += preg_match(Pcre::delimit(self::stringify($expected)), self::stringify($arguments[$field])) === 1 ? 1 : 0;

        continue;
      }

      $count += self::canonical($expected) === self::canonical($arguments[$field]) ? 1 : 0;
    }

    return $count;
  }

  /**
   * Counts a schema's declared property and required names present in the args.
   *
   * @param array<mixed> $schema
   *   The JSON Schema, as a nested array.
   * @param array<mixed> $arguments
   *   The tool call arguments.
   *
   * @return int
   *   The number of named fields that appear in the arguments.
   */
  protected static function schemaScore(array $schema, array $arguments): int {
    $names = array_keys(is_array($schema['properties'] ?? NULL) ? $schema['properties'] : []);

    foreach (is_array($schema['required'] ?? NULL) ? $schema['required'] : [] as $required) {
      $names[] = self::stringify($required);
    }

    $present = array_filter(array_unique($names), static fn(string $name): bool => array_key_exists($name, $arguments));

    return count($present);
  }

  /**
   * Renders a value as the string a regex is tested against.
   *
   * @param mixed $value
   *   The argument value.
   *
   * @return string
   *   A scalar verbatim, any structure as compact JSON.
   */
  protected static function stringify(mixed $value): string {
    if (is_scalar($value)) {
      return (string) $value;
    }

    return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
  }

  /**
   * Encodes a value as canonical JSON with every object's keys sorted.
   *
   * Two argument objects that differ only in key order encode identically,
   * while a string and a number never do, so the comparison is order-free but
   * type-strict.
   *
   * @param mixed $value
   *   The value to canonicalise.
   *
   * @return string
   *   The canonical JSON encoding.
   */
  protected static function canonical(mixed $value): string {
    return (string) json_encode(self::sortKeys($value), JSON_UNESCAPED_SLASHES);
  }

  /**
   * Recursively sorts array keys so key order never affects equality.
   *
   * @param mixed $value
   *   The value to sort.
   *
   * @return mixed
   *   The value with every nested array key-sorted.
   */
  protected static function sortKeys(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }

    $sorted = array_map(self::sortKeys(...), $value);
    ksort($sorted);

    return $sorted;
  }

  /**
   * Converts a nested array to the object graph the schema validator wants.
   *
   * @param mixed $value
   *   The value to convert.
   *
   * @return mixed
   *   The value re-decoded so maps become objects.
   */
  protected static function toObjectGraph(mixed $value): mixed {
    return json_decode((string) json_encode($value, JSON_UNESCAPED_SLASHES), FALSE);
  }

}
