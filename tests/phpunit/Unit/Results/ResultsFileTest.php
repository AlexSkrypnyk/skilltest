<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Results;

use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Results\ResultsFile;
use AlexSkrypnyk\SkillTest\Tests\Traits\ArrayPathTrait;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Class ResultsFileTest.
 *
 * Unit test for the read-only results loader: a current-major document is
 * decoded, and missing, malformed, non-mapping, non-scalar-version,
 * unparseable-version, and incompatible-major files are configuration errors.
 */
#[CoversClass(ResultsFile::class)]
#[Group('results')]
final class ResultsFileTest extends TestCase {

  use ArrayPathTrait;

  /**
   * The virtual filesystem root each test writes fixtures under.
   */
  protected string $root = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->root = vfsStream::setup('root')->url();
  }

  public function testCurrentMajorDocumentIsDecoded(): void {
    $file = $this->write('results.json', '{"version": "1", "totals": {"checks": 5}}');

    $document = ResultsFile::load($file);

    $this->assertSame('1', $this->path($document, 'version'));
    $this->assertSame(5, $this->path($document, 'totals', 'checks'));
  }

  public function testAheadMinorIsReadable(): void {
    $file = $this->write('results.json', '{"version": "1.7"}');

    $document = ResultsFile::load($file);

    $this->assertSame('1.7', $this->path($document, 'version'));
  }

  public function testMissingVersionIsTreatedAsCurrent(): void {
    $file = $this->write('results.json', '{"totals": {"checks": 0}}');

    $document = ResultsFile::load($file);

    $this->assertSame(0, $this->path($document, 'totals', 'checks'));
  }

  public function testEmptyObjectIsReadable(): void {
    $file = $this->write('results.json', '{}');

    $this->assertSame([], ResultsFile::load($file));
  }

  public function testMissingFileIsError(): void {
    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('results file not found');

    ResultsFile::load($this->root . '/absent.json');
  }

  public function testMalformedJsonIsError(): void {
    $file = $this->write('results.json', '{not json');

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('malformed JSON');

    ResultsFile::load($file);
  }

  public function testNonMappingIsError(): void {
    $file = $this->write('results.json', '[1, 2, 3]');

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('expected a results object');

    ResultsFile::load($file);
  }

  public function testNonScalarVersionIsError(): void {
    $file = $this->write('results.json', '{"version": {"major": 1}}');

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('version must be a scalar');

    ResultsFile::load($file);
  }

  public function testUnparseableVersionIsError(): void {
    $file = $this->write('results.json', '{"version": "not-a-version"}');

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('Invalid schema version');

    ResultsFile::load($file);
  }

  public function testNewerMajorIsRefusedWithMigratePointer(): void {
    $file = $this->write('results.json', '{"version": "2"}');

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('skilltest migrate');

    ResultsFile::load($file);
  }

  public function testOlderMajorIsRefusedWithMigratePointer(): void {
    $file = $this->write('results.json', '{"version": "0"}');

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('cannot read');

    ResultsFile::load($file);
  }

  /**
   * Writes a fixture file under the virtual root.
   *
   * @param string $name
   *   The file name.
   * @param string $contents
   *   The file contents.
   *
   * @return string
   *   The file URL.
   */
  protected function write(string $name, string $contents): string {
    $file = $this->root . '/' . $name;
    file_put_contents($file, $contents);

    return $file;
  }

}
