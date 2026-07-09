<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Run\Report;

use AlexSkrypnyk\SkillTest\Run\Report\ReportOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Class ReportOptionsTest.
 *
 * Unit test for parsing and validating the run command's reporting options.
 */
#[CoversClass(ReportOptions::class)]
final class ReportOptionsTest extends TestCase {

  public function testDefaultsToHumanStdoutWantingNoDocument(): void {
    $options = ReportOptions::parse(FALSE, NULL, [], FALSE, NULL, NULL, NULL);

    $this->assertTrue($options->valid());
    $this->assertSame('human', $options->stdoutFormat());
    $this->assertFalse($options->wantsDocument());
    $this->assertFalse($options->writesArtifacts());
    $this->assertSame([], $options->junitTargets);
    $this->assertNull($options->sessionDir);
  }

  public function testJsonSelectsJsonStdoutButWritesNoArtifact(): void {
    $options = ReportOptions::parse(TRUE, NULL, [], FALSE, NULL, NULL, NULL);

    $this->assertSame('json', $options->stdoutFormat());
    $this->assertTrue($options->wantsDocument());
    $this->assertFalse($options->writesArtifacts());
  }

  public function testGithubCommentSelectsThatFormatAndWritesAnArtifact(): void {
    $options = ReportOptions::parse(FALSE, 'github-comment', [], FALSE, NULL, NULL, NULL);

    $this->assertTrue($options->valid());
    $this->assertTrue($options->githubComment);
    $this->assertSame('github-comment', $options->stdoutFormat());
    $this->assertTrue($options->wantsDocument());
    $this->assertTrue($options->writesArtifacts());
  }

  public function testJsonAndGithubCommentConflict(): void {
    $options = ReportOptions::parse(TRUE, 'github-comment', [], FALSE, NULL, NULL, NULL);

    $this->assertFalse($options->valid());
    $this->assertStringContainsString('single stdout format', $options->errors[0]->message);
  }

  public function testUnknownFormatIsAnError(): void {
    $options = ReportOptions::parse(FALSE, 'tap', [], FALSE, NULL, NULL, NULL);

    $this->assertFalse($options->valid());
    $this->assertStringContainsString("unknown format 'tap'", $options->errors[0]->message);
  }

  public function testJunitReporterParsesItsPath(): void {
    $options = ReportOptions::parse(FALSE, NULL, ['junit:build/junit.xml'], FALSE, NULL, NULL, NULL);

    $this->assertTrue($options->valid());
    $this->assertSame(['build/junit.xml'], $options->junitTargets);
    $this->assertTrue($options->wantsDocument());
    $this->assertTrue($options->writesArtifacts());
  }

  public function testMultipleJunitReportersAreAllParsed(): void {
    $options = ReportOptions::parse(FALSE, NULL, ['junit:a.xml', 'junit:b.xml'], FALSE, NULL, NULL, NULL);

    $this->assertSame(['a.xml', 'b.xml'], $options->junitTargets);
  }

  public function testUnknownReporterIsAnError(): void {
    $options = ReportOptions::parse(FALSE, NULL, ['tap:out.tap'], FALSE, NULL, NULL, NULL);

    $this->assertFalse($options->valid());
    $this->assertStringContainsString("unknown reporter 'tap:out.tap'", $options->errors[0]->message);
    $this->assertSame([], $options->junitTargets);
  }

  public function testJunitReporterWithoutAPathIsAnError(): void {
    $options = ReportOptions::parse(FALSE, NULL, ['junit:'], FALSE, NULL, NULL, NULL);

    $this->assertFalse($options->valid());
    $this->assertStringContainsString('junit requires a path', $options->errors[0]->message);
  }

  public function testSessionLogRequiresASessionDir(): void {
    $options = ReportOptions::parse(FALSE, NULL, [], TRUE, NULL, NULL, NULL);

    $this->assertFalse($options->valid());
    $this->assertStringContainsString('--session-log requires --session-dir', $options->errors[0]->message);
    $this->assertNull($options->sessionDir);
  }

  public function testSessionLogWithADirIsAccepted(): void {
    $options = ReportOptions::parse(FALSE, NULL, [], TRUE, 'runs', NULL, NULL);

    $this->assertTrue($options->valid());
    $this->assertSame('runs', $options->sessionDir);
    $this->assertTrue($options->writesArtifacts());
  }

  public function testSessionDirWithoutSessionLogIsIgnored(): void {
    $options = ReportOptions::parse(FALSE, NULL, [], FALSE, 'runs', NULL, NULL);

    $this->assertTrue($options->valid());
    $this->assertNull($options->sessionDir);
    $this->assertFalse($options->writesArtifacts());
  }

  public function testOutputFlagsWantTheDocumentAndWriteArtifacts(): void {
    $options = ReportOptions::parse(FALSE, NULL, [], FALSE, NULL, 'out.json', 'runs');

    $this->assertTrue($options->wantsDocument());
    $this->assertTrue($options->writesArtifacts());
    $this->assertSame('out.json', $options->outputFile);
    $this->assertSame('runs', $options->outputDir);
  }

  public function testEveryProblemIsCollected(): void {
    $options = ReportOptions::parse(TRUE, 'github-comment', ['tap:x'], TRUE, NULL, NULL, NULL);

    $this->assertCount(3, $options->errors);
  }

}
