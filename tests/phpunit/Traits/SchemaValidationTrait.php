<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Traits;

use JsonSchema\Validator;

/**
 * Trait SchemaValidationTrait.
 *
 * Validates results documents against the committed JSON Schema, so both the
 * schema's own tests and the command tests assert conformance the same way
 * rather than hand-checking a handful of keys.
 *
 * @mixin \PHPUnit\Framework\TestCase
 */
trait SchemaValidationTrait {

  /**
   * Resolves the absolute path to the committed results JSON Schema.
   *
   * @return string
   *   The schema path, independent of the calling test's location.
   */
  protected function resultsSchemaPath(): string {
    return dirname(__DIR__, 3) . '/schema/results.schema.json';
  }

  /**
   * Validates a results document against the schema, returning the errors.
   *
   * @param string $json
   *   The results document as a JSON string.
   *
   * @return array<int, array<string, mixed>>
   *   The schema violations, empty when the document conforms.
   */
  protected function resultsSchemaErrors(string $json): array {
    $data = json_decode($json, FALSE, 512, JSON_THROW_ON_ERROR);
    $schema = json_decode((string) file_get_contents($this->resultsSchemaPath()), FALSE, 512, JSON_THROW_ON_ERROR);

    $validator = new Validator();
    $validator->validate($data, $schema);

    return $validator->getErrors();
  }

  /**
   * Asserts a results document conforms to the committed JSON Schema.
   *
   * @param string $json
   *   The results document as a JSON string.
   */
  protected function assertMatchesResultsSchema(string $json): void {
    $errors = $this->resultsSchemaErrors($json);

    $this->assertSame([], $errors, 'Results document does not match the schema: ' . json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  }

}
