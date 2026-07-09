<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Traits;

/**
 * Trait JunitSchemaValidationTrait.
 *
 * Validates JUnit XML against the committed schema, so the reporter's own tests
 * and the command tests assert the same conformance a CI test-summary renderer
 * relies on rather than eyeballing a handful of elements.
 *
 * @mixin \PHPUnit\Framework\TestCase
 */
trait JunitSchemaValidationTrait {

  /**
   * Resolves the absolute path to the committed JUnit XSD.
   *
   * @return string
   *   The schema path, independent of the calling test's location.
   */
  protected function junitSchemaPath(): string {
    return dirname(__DIR__, 3) . '/schema/junit.xsd';
  }

  /**
   * Asserts a JUnit document is well-formed and matches the committed schema.
   *
   * @param string $xml
   *   The JUnit XML.
   */
  protected function assertMatchesJunitSchema(string $xml): void {
    $dom = new \DOMDocument();
    $this->assertTrue($dom->loadXML($xml), 'JUnit output is not well-formed XML.');

    $previous = libxml_use_internal_errors(TRUE);
    $valid = $dom->schemaValidate($this->junitSchemaPath());
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $messages = array_map(static fn(\LibXMLError $error): string => trim($error->message), $errors);
    $this->assertTrue($valid, 'JUnit output does not match the schema: ' . implode('; ', $messages));
  }

}
