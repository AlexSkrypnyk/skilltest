<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Run;

use AlexSkrypnyk\SkillTest\Run\Redactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class RedactorTest.
 *
 * Unit test for the environment secret redactor.
 */
#[CoversClass(Redactor::class)]
final class RedactorTest extends TestCase {

  public function testDisabledRedactorPassesTextThrough(): void {
    $redactor = Redactor::fromEnvironment(['ANTHROPIC_API_KEY' => 'sk-secret-value'], FALSE);

    $this->assertSame('token sk-secret-value stays', $redactor->redactString('token sk-secret-value stays'));
  }

  public function testRedactsCredentialNamedValuesOnly(): void {
    $env = [
      'ANTHROPIC_API_KEY' => 'sk-ant-secret-123',
      'CLAUDE_CODE_OAUTH_TOKEN' => 'oauth-token-456',
      'HOME' => '/Users/person',
      'PATH' => '/usr/local/bin',
    ];
    $redactor = Redactor::fromEnvironment($env, TRUE);

    $text = 'key sk-ant-secret-123 and oauth-token-456 under /Users/person on /usr/local/bin';

    $this->assertSame('key [REDACTED] and [REDACTED] under /Users/person on /usr/local/bin', $redactor->redactString($text));
  }

  public function testIgnoresIncidentalNamesAndShortValues(): void {
    $env = [
      'MONKEY_ID' => 'monkeybusiness',
      'FOO_KEY' => 'abc',
      'API_KEY' => 'long-enough-secret',
    ];
    $redactor = Redactor::fromEnvironment($env, TRUE);

    $text = 'monkeybusiness abc long-enough-secret';

    $this->assertSame('monkeybusiness abc [REDACTED]', $redactor->redactString($text));
  }

  public function testLongestSecretFirstLeavesNoResidue(): void {
    $env = [
      'A_TOKEN' => 'secret',
      'B_TOKEN' => 'secretlong',
    ];
    $redactor = Redactor::fromEnvironment($env, TRUE);

    $result = $redactor->redactString('secretlong and secret');

    $this->assertSame('[REDACTED] and [REDACTED]', $result);
    $this->assertStringNotContainsString('long', $result);
  }

  public function testDuplicateValuesCollapseToOneSecret(): void {
    $env = [
      'FIRST_TOKEN' => 'shared-secret',
      'SECOND_SECRET' => 'shared-secret',
    ];
    $redactor = Redactor::fromEnvironment($env, TRUE);

    $this->assertSame('[REDACTED] then [REDACTED]', $redactor->redactString('shared-secret then shared-secret'));
  }

  public function testRedactDocumentWalksNestedStringsAndKeepsScalars(): void {
    $redactor = new Redactor(['secret']);

    $document = [
      'a' => 'has secret inside',
      'b' => ['c' => 'secret', 'd' => 5],
      'e' => TRUE,
      'f' => NULL,
    ];

    $expected = [
      'a' => 'has [REDACTED] inside',
      'b' => ['c' => '[REDACTED]', 'd' => 5],
      'e' => TRUE,
      'f' => NULL,
    ];

    $this->assertSame($expected, $redactor->redactDocument($document));
  }

  public function testNoCredentialsProducesNoOpRedactor(): void {
    $redactor = Redactor::fromEnvironment(['HOME' => '/Users/person'], TRUE);

    $this->assertSame('untouched /Users/person', $redactor->redactString('untouched /Users/person'));
  }

  public function testNonStringEnvEntriesAreSkipped(): void {
    $env = [
      100 => 'integer-keyed-value',
      'API_KEY' => 12345,
      'SESSION_TOKEN' => 'real-secret-value',
    ];
    $redactor = Redactor::fromEnvironment($env, TRUE);

    $this->assertSame('integer-keyed-value 12345 [REDACTED]', $redactor->redactString('integer-keyed-value 12345 real-secret-value'));
  }

}
