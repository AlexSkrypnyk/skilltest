<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Migrate;

use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\Migrate\Migrator;
use AlexSkrypnyk\SkillTest\Tests\Traits\ArrayPathTrait;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Class MigratorTest.
 *
 * Unit test for the schema migrator: current-major no-ops, older-major
 * rewrites for YAML and JSON, and the error surface for missing, malformed,
 * newer-major, and non-mapping files.
 */
#[CoversClass(Migrator::class)]
#[Group('migrate')]
final class MigratorTest extends TestCase {

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

  public function testCurrentMajorYamlIsLeftUnchanged(): void {
    $file = $this->write('eval.yaml', "version: \"1\"\nskill: demo\n");

    $result = (new Migrator())->migrate($file);

    $this->assertFalse($result->changed);
    $this->assertSame('1.0', $result->from);
    $this->assertSame('1.0', $result->to);
    $this->assertStringContainsString('already at the current schema (major 1)', $result->message);
    $this->assertSame("version: \"1\"\nskill: demo\n", (string) file_get_contents($file));
  }

  public function testCurrentMajorAheadMinorIsStillCurrent(): void {
    $file = $this->write('eval.yaml', "version: \"1.5\"\nskill: demo\n");

    $result = (new Migrator())->migrate($file);

    $this->assertFalse($result->changed);
    $this->assertSame('1.5', $result->from);
    $this->assertStringContainsString('already at the current schema', $result->message);
  }

  public function testMissingVersionIsTreatedAsCurrent(): void {
    $file = $this->write('skilltest.yml', "skill: demo\n");

    $result = (new Migrator())->migrate($file);

    $this->assertFalse($result->changed);
    $this->assertSame("skill: demo\n", (string) file_get_contents($file));
  }

  public function testOlderMajorYamlIsRewrittenAndStructurePreserved(): void {
    $file = $this->write('eval.yaml', "version: \"0\"\nskill: demo\ncontract:\n  tools:\n    allowed: [Bash]\n");

    $result = (new Migrator())->migrate($file);

    $this->assertTrue($result->changed);
    $this->assertSame('0.0', $result->from);
    $this->assertSame('1', $result->to);
    $this->assertStringContainsString('migrated from schema 0.0 to 1', $result->message);

    $reparsed = $this->parsedYaml($file);
    $this->assertSame('1', $this->path($reparsed, 'version'));
    $this->assertSame('demo', $this->path($reparsed, 'skill'));
    $this->assertSame(['Bash'], $this->path($reparsed, 'contract', 'tools', 'allowed'));
  }

  public function testOlderMajorSkilltestYmlIsRewritten(): void {
    $file = $this->write('skilltest.yml', "version: \"0\"\npaths:\n  skills: skills\n");

    $result = (new Migrator())->migrate($file);

    $this->assertTrue($result->changed);
    $reparsed = $this->parsedYaml($file);
    $this->assertSame('1', $this->path($reparsed, 'version'));
    $this->assertSame('skills', $this->path($reparsed, 'paths', 'skills'));
  }

  public function testOlderMajorJsonResultsIsRewrittenAsJson(): void {
    $file = $this->write('results.json', '{"version": "0", "totals": {"checks": 3}}');

    $result = (new Migrator())->migrate($file);

    $this->assertTrue($result->changed);
    $this->assertSame('1', $result->to);

    $written = (string) file_get_contents($file);
    $reparsed = $this->decodedJson($written);
    $this->assertSame('1', $this->path($reparsed, 'version'));
    $this->assertSame(3, $this->path($reparsed, 'totals', 'checks'));
    $this->assertStringContainsString("\n", $written, 'JSON is pretty-printed.');
  }

  public function testCurrentMajorJsonIsLeftUnchanged(): void {
    $file = $this->write('results.json', '{"version": "1"}');

    $result = (new Migrator())->migrate($file);

    $this->assertFalse($result->changed);
    $this->assertSame('{"version": "1"}', (string) file_get_contents($file));
  }

  public function testNewerMajorIsRefused(): void {
    $file = $this->write('eval.yaml', "version: \"2\"\nskill: demo\n");

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('newer than this tool supports');

    (new Migrator())->migrate($file);
  }

  public function testMissingFileIsError(): void {
    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('file not found');

    (new Migrator())->migrate($this->root . '/absent.yaml');
  }

  public function testMalformedYamlIsError(): void {
    $file = $this->write('eval.yaml', "version: \"1\"\n\tbad: indentation\n");

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('malformed YAML');

    (new Migrator())->migrate($file);
  }

  public function testMalformedJsonIsError(): void {
    $file = $this->write('results.json', '{not json');

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('malformed JSON');

    (new Migrator())->migrate($file);
  }

  public function testNonMappingYamlIsError(): void {
    $file = $this->write('eval.yaml', "- one\n- two\n");

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('expected a mapping');

    (new Migrator())->migrate($file);
  }

  public function testNonMappingJsonIsError(): void {
    $file = $this->write('results.json', '[1, 2, 3]');

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('expected a mapping');

    (new Migrator())->migrate($file);
  }

  public function testNonScalarVersionIsError(): void {
    $file = $this->write('eval.yaml', "version:\n  major: 1\n");

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('version must be a scalar');

    (new Migrator())->migrate($file);
  }

  public function testUnparseableVersionIsError(): void {
    $file = $this->write('eval.yaml', "version: \"not-a-version\"\n");

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('Invalid schema version');

    (new Migrator())->migrate($file);
  }

  /**
   * Parses a YAML file into a mapping, failing when it is not one.
   *
   * @param string $file
   *   The file to parse.
   *
   * @return array<mixed>
   *   The parsed mapping.
   */
  protected function parsedYaml(string $file): array {
    $data = Yaml::parse((string) file_get_contents($file));

    if (!is_array($data)) {
      $this->fail('Expected a YAML mapping.');
    }

    return $data;
  }

  /**
   * Decodes a JSON string into an object, failing when it is not one.
   *
   * @param string $json
   *   The JSON to decode.
   *
   * @return array<mixed>
   *   The decoded object.
   */
  protected function decodedJson(string $json): array {
    $data = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);

    if (!is_array($data)) {
      $this->fail('Expected a JSON object.');
    }

    return $data;
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
