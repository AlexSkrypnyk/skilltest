<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Structure;

use AlexSkrypnyk\SkillTest\Structure\StructureResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class StructureResultTest.
 *
 * Unit test for the structure result value object.
 */
#[CoversClass(StructureResult::class)]
final class StructureResultTest extends TestCase {

  public function testPassCarriesStatusAndMessage(): void {
    $result = StructureResult::pass('structure.frontmatter', 'foo', 'looks good.');

    $this->assertSame('structure.frontmatter', $result->check);
    $this->assertSame('foo', $result->skill);
    $this->assertSame(StructureResult::PASS, $result->status);
    $this->assertSame('looks good.', $result->message);
    $this->assertSame('', $result->file);
    $this->assertSame(0, $result->line);
    $this->assertSame('', $result->evidence);
    $this->assertNull($result->reason);
    $this->assertFalse($result->failed());
  }

  public function testFailCarriesLocationAndEvidence(): void {
    $result = StructureResult::fail('structure.files-exist', 'foo', 'missing.', 'skills/foo/SKILL.md', 12, 'refs/x.md');

    $this->assertSame(StructureResult::FAIL, $result->status);
    $this->assertSame('skills/foo/SKILL.md', $result->file);
    $this->assertSame(12, $result->line);
    $this->assertSame('refs/x.md', $result->evidence);
    $this->assertTrue($result->failed());
  }

  public function testSuppressedCarriesReason(): void {
    $result = StructureResult::suppressed('structure.name-matches-dir', 'foo', 'legacy directory.');

    $this->assertSame(StructureResult::SUPPRESSED, $result->status);
    $this->assertSame('legacy directory.', $result->reason);
    $this->assertSame('suppressed: legacy directory.', $result->message);
    $this->assertFalse($result->failed());
  }

  public function testRenderUsesSkillWhenNoFile(): void {
    $result = StructureResult::pass('structure.frontmatter', 'foo', 'ok.');

    $this->assertSame('structure.frontmatter PASS foo - ok.', $result->render());
  }

  public function testRenderUsesFileLineAndEvidence(): void {
    $result = StructureResult::fail('structure.files-exist', 'foo', 'missing.', 'skills/foo/SKILL.md', 12, 'refs/x.md');

    $this->assertSame('structure.files-exist FAIL skills/foo/SKILL.md:12 - missing. [refs/x.md]', $result->render());
  }

  public function testRenderSuppressed(): void {
    $result = StructureResult::suppressed('structure.name-matches-dir', 'foo', 'legacy.');

    $this->assertSame('structure.name-matches-dir SUPPRESSED foo - suppressed: legacy.', $result->render());
  }

  public function testToArrayExposesEveryField(): void {
    $result = StructureResult::fail('structure.frontmatter', 'foo', 'bad.', 'skills/foo/SKILL.md', 1, 'x');

    $this->assertSame([
      'check' => 'structure.frontmatter',
      'skill' => 'foo',
      'status' => 'fail',
      'message' => 'bad.',
      'file' => 'skills/foo/SKILL.md',
      'line' => 1,
      'evidence' => 'x',
      'reason' => NULL,
    ], $result->toArray());
  }

}
