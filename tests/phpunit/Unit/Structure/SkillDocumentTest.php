<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Structure;

use AlexSkrypnyk\SkillTest\Structure\SkillDocument;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class SkillDocumentTest.
 *
 * Unit test for the SKILL.md frontmatter and body parser.
 */
#[CoversClass(SkillDocument::class)]
final class SkillDocumentTest extends TestCase {

  public function testValidFrontmatterParsesWithBodyOffset(): void {
    $document = SkillDocument::fromString("---\nname: foo\ndescription: bar\n---\n# Body\ntext\n");

    $this->assertTrue($document->frontmatterPresent);
    $this->assertTrue($document->frontmatterValid);
    $this->assertSame('foo', $document->frontmatter['name']);
    $this->assertSame('bar', $document->frontmatter['description']);
    $this->assertSame("# Body\ntext\n", $document->body);
    $this->assertSame(5, $document->bodyStartLine);
  }

  public function testNoFrontmatterTreatsWholeFileAsBody(): void {
    $document = SkillDocument::fromString("# Just a heading\nno frontmatter here\n");

    $this->assertFalse($document->frontmatterPresent);
    $this->assertFalse($document->frontmatterValid);
    $this->assertSame([], $document->frontmatter);
    $this->assertSame("# Just a heading\nno frontmatter here\n", $document->body);
    $this->assertSame(1, $document->bodyStartLine);
  }

  public function testUnterminatedFrontmatterIsPresentButInvalid(): void {
    $document = SkillDocument::fromString("---\nname: foo\nno closing fence\n");

    $this->assertTrue($document->frontmatterPresent);
    $this->assertFalse($document->frontmatterValid);
    $this->assertSame([], $document->frontmatter);
    $this->assertSame('', $document->body);
  }

  public function testMalformedFrontmatterIsPresentButInvalid(): void {
    $document = SkillDocument::fromString("---\nname: [unclosed\n---\n# Body\n");

    $this->assertTrue($document->frontmatterPresent);
    $this->assertFalse($document->frontmatterValid);
    $this->assertSame([], $document->frontmatter);
  }

  public function testNonMappingFrontmatterIsInvalid(): void {
    $document = SkillDocument::fromString("---\n- one\n- two\n---\n# Body\n");

    $this->assertTrue($document->frontmatterPresent);
    $this->assertFalse($document->frontmatterValid);
    $this->assertSame([], $document->frontmatter);
  }

  public function testScalarFrontmatterIsInvalid(): void {
    $document = SkillDocument::fromString("---\njust a bare string\n---\n# Body\n");

    $this->assertTrue($document->frontmatterPresent);
    $this->assertFalse($document->frontmatterValid);
    $this->assertSame([], $document->frontmatter);
  }

  public function testCarriageReturnsOnFencesAreTolerated(): void {
    $document = SkillDocument::fromString("---\r\nname: foo\r\n---\r\nbody\r\n");

    $this->assertTrue($document->frontmatterValid);
    $this->assertSame('foo', $document->frontmatter['name']);
  }

  public function testFromFileReadsAndParses(): void {
    $root = vfsStream::setup('root', NULL, [
      'SKILL.md' => "---\nname: read-me\ndescription: from disk\n---\nbody\n",
    ]);

    $document = SkillDocument::fromFile($root->url() . '/SKILL.md');

    $this->assertTrue($document->frontmatterValid);
    $this->assertSame('read-me', $document->frontmatter['name']);
  }

  public function testFromFileMissingYieldsEmptyDocument(): void {
    $document = SkillDocument::fromFile('vfs://root/does-not-exist.md');

    $this->assertSame('', $document->content);
    $this->assertFalse($document->frontmatterPresent);
    $this->assertSame(1, $document->bodyStartLine);
  }

}
