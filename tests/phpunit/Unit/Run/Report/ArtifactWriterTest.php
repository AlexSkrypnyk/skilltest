<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Run\Report;

use AlexSkrypnyk\SkillTest\Run\Report\ArtifactWriter;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class ArtifactWriterTest.
 *
 * Unit test for writing a reporter artifact and creating its parent tree.
 */
#[CoversClass(ArtifactWriter::class)]
final class ArtifactWriterTest extends TestCase {

  public function testWriteCreatesMissingParentsAndReturnsThePath(): void {
    $root = vfsStream::setup('run');
    $writer = new ArtifactWriter();

    $path = $writer->write(vfsStream::url('run') . '/nested/deep/report.xml', '<testsuites/>');

    $this->assertSame(vfsStream::url('run') . '/nested/deep/report.xml', $path);
    $this->assertTrue($root->hasChild('nested/deep/report.xml'));
    $this->assertSame('<testsuites/>', (string) file_get_contents($path));
  }

  public function testWriteReusesAnExistingDirectory(): void {
    $root = vfsStream::setup('run');
    $writer = new ArtifactWriter();

    $writer->write(vfsStream::url('run') . '/session.ndjson', "{\"seq\":1}\n");
    $writer->write(vfsStream::url('run') . '/session.ndjson', "{\"seq\":2}\n");

    $this->assertTrue($root->hasChild('session.ndjson'));
    $this->assertSame("{\"seq\":2}\n", (string) file_get_contents(vfsStream::url('run') . '/session.ndjson'));
  }

}
