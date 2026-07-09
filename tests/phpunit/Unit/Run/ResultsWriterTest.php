<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Run;

use AlexSkrypnyk\SkillTest\Run\Redactor;
use AlexSkrypnyk\SkillTest\Run\ResultsWriter;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class ResultsWriterTest.
 *
 * Unit test for persisting results, redacted, in both layouts.
 */
#[CoversClass(ResultsWriter::class)]
final class ResultsWriterTest extends TestCase {

  /**
   * A results document carrying a secret in one string leaf.
   */
  protected const array DOCUMENT = [
    'version' => '1',
    'run' => ['id' => 'st-x', 'note' => 'auth token sk-secret-xyz used'],
    'skills' => [],
  ];

  public function testWriteFileWritesRedactedPrettyJsonAndCreatesParents(): void {
    $root = vfsStream::setup('run');
    $writer = new ResultsWriter(new Redactor(['sk-secret-xyz']));

    $path = $writer->writeFile(self::DOCUMENT, vfsStream::url('run') . '/nested/deep/results.json');

    $this->assertSame(vfsStream::url('run') . '/nested/deep/results.json', $path);
    $this->assertTrue($root->hasChild('nested/deep/results.json'));

    $content = (string) file_get_contents($path);
    $this->assertStringContainsString('[REDACTED]', $content);
    $this->assertStringNotContainsString('sk-secret-xyz', $content);
    $this->assertStringContainsString("\n    ", $content, 'Expected pretty-printed JSON.');
    $this->assertSame("\n", substr($content, -1), 'Expected a trailing newline.');
    $this->assertStringContainsString('"note": "auth token [REDACTED] used"', $content);
  }

  public function testWriteDirCreatesTimestampedLayoutWithSeparateArtifacts(): void {
    $root = vfsStream::setup('run');
    $writer = new ResultsWriter(new Redactor(['sk-secret-xyz']));

    $artifacts = [
      'artifacts/haiku-1.jsonl' => '{"turn":1,"key":"sk-secret-xyz"}',
      'artifacts/haiku-2.jsonl' => '{"turn":2,"clean":true}',
    ];

    $run_dir = $writer->writeDir(self::DOCUMENT, vfsStream::url('run') . '/out', '20260709-041500', $artifacts);

    $this->assertSame(vfsStream::url('run') . '/out/20260709-041500', $run_dir);
    $this->assertTrue($root->hasChild('out/20260709-041500/results.json'));
    $this->assertTrue($root->hasChild('out/20260709-041500/artifacts/haiku-1.jsonl'));
    $this->assertTrue($root->hasChild('out/20260709-041500/artifacts/haiku-2.jsonl'));

    $transcript = (string) file_get_contents($run_dir . '/artifacts/haiku-1.jsonl');
    $this->assertSame('{"turn":1,"key":"[REDACTED]"}', $transcript);
    $this->assertStringNotContainsString('sk-secret-xyz', $transcript);

    $results = (string) file_get_contents($run_dir . '/' . ResultsWriter::RESULTS_FILE);
    $this->assertStringContainsString('[REDACTED]', $results);
  }

  public function testWriteDirWithoutArtifactsWritesOnlyResults(): void {
    $root = vfsStream::setup('run');
    $writer = new ResultsWriter(new Redactor([]));

    $run_dir = $writer->writeDir(self::DOCUMENT, vfsStream::url('run'), '20260709-041500');

    $this->assertTrue($root->hasChild('20260709-041500/results.json'));
    $this->assertFalse($root->hasChild('20260709-041500/artifacts'));
    $this->assertSame(vfsStream::url('run') . '/20260709-041500', $run_dir);
  }

  public function testDisabledRedactorLeavesSecretsInPlace(): void {
    vfsStream::setup('run');
    $writer = new ResultsWriter(Redactor::fromEnvironment(['ANTHROPIC_API_KEY' => 'sk-secret-xyz'], FALSE));

    $path = $writer->writeFile(self::DOCUMENT, vfsStream::url('run') . '/results.json');

    $this->assertStringContainsString('sk-secret-xyz', (string) file_get_contents($path));
  }

}
