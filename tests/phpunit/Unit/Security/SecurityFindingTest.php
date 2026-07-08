<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Security;

use AlexSkrypnyk\SkillTest\Security\SecurityFinding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class SecurityFindingTest.
 *
 * Unit test for a single security finding.
 */
#[CoversClass(SecurityFinding::class)]
final class SecurityFindingTest extends TestCase {

  public function testConstruct(): void {
    $finding = new SecurityFinding('security.curl-pipe-shell', 'skills/foo/install.sh', 12, 'curl x | bash', 'pipes a remote download into a shell (curl | bash)');

    $this->assertSame('security.curl-pipe-shell', $finding->check);
    $this->assertSame('skills/foo/install.sh', $finding->file);
    $this->assertSame(12, $finding->line);
    $this->assertSame('curl x | bash', $finding->evidence);
    $this->assertSame('pipes a remote download into a shell (curl | bash)', $finding->description);
  }

  public function testRender(): void {
    $finding = new SecurityFinding('security.destructive-delete', 'skills/foo/clean.sh', 3, 'rm -rf /', 'destructive recursive delete at the filesystem root');

    $this->assertSame('security.destructive-delete skills/foo/clean.sh:3 - destructive recursive delete at the filesystem root [rm -rf /]', $finding->render());
  }

  public function testToArray(): void {
    $finding = new SecurityFinding('security.forbidden-tokens', 'skills/foo/run.sh', 7, 'echo ACME_SECRET', "forbidden token 'ACME_SECRET' appears in a shipped file");

    $this->assertSame([
      'check' => 'security.forbidden-tokens',
      'file' => 'skills/foo/run.sh',
      'line' => 7,
      'evidence' => 'echo ACME_SECRET',
      'description' => "forbidden token 'ACME_SECRET' appears in a shipped file",
    ], $finding->toArray());
  }

}
