<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Run;

use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Coverage\CoverageRow;
use AlexSkrypnyk\SkillTest\Run\RunReport;
use AlexSkrypnyk\SkillTest\Run\SkillRunResult;
use AlexSkrypnyk\SkillTest\Security\SecurityFinding;
use AlexSkrypnyk\SkillTest\Structure\StructureResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class RunReportTest.
 *
 * Unit test for the run report arithmetic and results document.
 */
#[CoversClass(RunReport::class)]
#[CoversClass(SkillRunResult::class)]
final class RunReportTest extends TestCase {

  public function testEmptyReportHasNoChecksAndPasses(): void {
    $report = new RunReport([], [], []);

    $this->assertSame(0, $report->checks());
    $this->assertSame(0, $report->failures());
    $this->assertSame(0, $report->suppressed());
    $this->assertFalse($report->failed());
  }

  public function testCountsAcrossEveryGroup(): void {
    $report = new RunReport(
      [
        new SkillRunResult(
          'alpha',
          'skills/alpha',
          [
            StructureResult::pass('structure.frontmatter', 'alpha', 'ok'),
            StructureResult::fail('structure.files-exist', 'alpha', 'missing', 'skills/alpha/SKILL.md', 3, 'references/x.md'),
            StructureResult::suppressed('structure.name-matches-dir', 'alpha', 'legacy dir'),
          ],
          [new SecurityFinding('security.curl-pipe-shell', 'skills/alpha/SKILL.md', 9, 'curl x | bash', 'pipes a download into a shell')],
          [
            CheckResult::pass('contract.tools.required', 'Bash', 'Bash', 'used'),
            CheckResult::fail('contract.commands.required', 'builds', '', 'never matched'),
          ],
        ),
        new SkillRunResult('beta', 'skills/beta', [], [], [], 'no transcript fixture declared'),
      ],
      [
        CheckResult::pass('hooks.reject-push', 'hooks/reject-push.sh', '{}', 'blocked as expected'),
        CheckResult::fail('hooks.reject-push', 'hooks/reject-push.sh', '{}', 'allowed but must block'),
      ],
      [new CoverageRow('gamma', 'skills/gamma', FALSE, FALSE, 0, FALSE, NULL)],
    );

    $this->assertSame(9, $report->checks());
    $this->assertSame(5, $report->failures());
    $this->assertSame(1, $report->suppressed());
    $this->assertTrue($report->failed());
  }

  public function testToResultsDocumentShape(): void {
    $report = new RunReport(
      [
        new SkillRunResult(
          'alpha',
          'skills/alpha',
          [StructureResult::fail('structure.files-exist', 'alpha', 'missing', 'skills/alpha/SKILL.md', 3, 'references/x.md')],
          [new SecurityFinding('security.curl-pipe-shell', 'skills/alpha/SKILL.md', 9, 'curl x | bash', 'pipes a download into a shell')],
          [CheckResult::pass('contract.tools.required', 'Bash', 'Bash', 'used')],
        ),
      ],
      [CheckResult::fail('hooks.reject-push', 'hooks/reject-push.sh', '{"tool":"Bash"}', 'allowed but must block')],
      [new CoverageRow('gamma', 'skills/gamma', FALSE, FALSE, 0, FALSE, NULL)],
    );

    $tool = ['name' => 'skilltest', 'version' => 'development'];
    $run = ['id' => 'st-1', 'started' => '2026-01-01T00:00:00+00:00', 'duration_ms' => 5, 'command' => 'run', 'environment' => 'host'];
    $document = $report->toResults('1', $tool, $run);

    $this->assertSame('1', $document['version']);
    $this->assertSame($tool, $document['tool']);
    $this->assertSame($run, $document['run']);

    $skills = $document['skills'];
    $this->assertIsArray($skills);
    $this->assertCount(1, $skills);
    $this->assertSame('alpha', $skills[0]['skill']);
    $this->assertSame('skills/alpha', $skills[0]['path']);

    $deterministic = $skills[0]['deterministic'];
    $this->assertSame('structure.files-exist', $deterministic['structure'][0]['check']);
    $this->assertFalse($deterministic['structure'][0]['pass']);
    $this->assertSame('security.curl-pipe-shell', $deterministic['security'][0]['check']);
    $this->assertFalse($deterministic['security'][0]['pass']);
    $this->assertSame('contract.tools.required', $deterministic['transcript'][0]['check']);
    $this->assertTrue($deterministic['transcript'][0]['pass']);
    $this->assertSame('Bash', $deterministic['transcript'][0]['evidence']);

    $hooks = $document['hooks'];
    $this->assertIsArray($hooks);
    $this->assertSame('hooks.reject-push', $hooks[0]['check']);
    $this->assertFalse($hooks[0]['pass']);

    $coverage = $document['coverage'];
    $this->assertIsArray($coverage);
    $this->assertSame('coverage.eval-exists', $coverage['violations'][0]['check']);
    $this->assertFalse($coverage['violations'][0]['pass']);
    $this->assertStringContainsString("skill 'gamma' has no eval.yaml", $coverage['violations'][0]['message']);

    $this->assertSame(['checks' => 5, 'failures' => 4, 'trials' => 0, 'tokens' => ['in' => 0, 'out' => 0], 'cost_usd' => 0.0], $document['totals']);
  }

  public function testCoverageMessageNamesTheSkill(): void {
    $row = new CoverageRow('orphan', 'skills/orphan', FALSE, FALSE, 0, FALSE, NULL);

    $this->assertSame("skill 'orphan' has no eval.yaml and is not excluded (add an eval.yaml or exclude it with a reason).", RunReport::coverageMessage($row));
  }

}
