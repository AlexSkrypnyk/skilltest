<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Gate;

use AlexSkrypnyk\SkillTest\Gate\GateFinding;
use AlexSkrypnyk\SkillTest\Gate\GateReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class GateReportTest.
 *
 * Unit test for the gate report: the derived verdict, the drop, and the
 * partitioning of failing and warning findings.
 */
#[CoversClass(GateReport::class)]
final class GateReportTest extends TestCase {

  public function testNoFindingsPasses(): void {
    $report = new GateReport(1.0, 1.0, 0.0, []);

    $this->assertFalse($report->failed());
    $this->assertSame([], $report->failingFindings());
    $this->assertSame([], $report->warningFindings());
  }

  public function testFailingFindingFailsTheGate(): void {
    $report = new GateReport(1.0, 0.9, 0.0, [GateFinding::warn('new-task', 'a'), GateFinding::fail('regression', 'b')]);

    $this->assertTrue($report->failed());
  }

  public function testWarningsAloneDoNotFail(): void {
    $report = new GateReport(1.0, 1.0, 0.0, [GateFinding::warn('new-task', 'a')]);

    $this->assertFalse($report->failed());
  }

  public function testPartitionsFindings(): void {
    $fail = GateFinding::fail('regression', 'b');
    $warn = GateFinding::warn('new-task', 'a');
    $report = new GateReport(1.0, 0.9, 0.0, [$warn, $fail]);

    $this->assertSame([$fail], $report->failingFindings());
    $this->assertSame([$warn], $report->warningFindings());
  }

  public function testDropIsInPercentagePoints(): void {
    $report = new GateReport(1.0, 0.95, 0.0, []);

    $this->assertEqualsWithDelta(5.0, $report->drop(), 0.0001);
  }

}
