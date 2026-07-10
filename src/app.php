<?php

/**
 * @file
 * Main entry point for the application.
 */

declare(strict_types=1);

use AlexSkrypnyk\SkillTest\Command\CacheCommand;
use AlexSkrypnyk\SkillTest\Command\CompareCommand;
use AlexSkrypnyk\SkillTest\Command\CoverageCommand;
use AlexSkrypnyk\SkillTest\Command\GateCommand;
use AlexSkrypnyk\SkillTest\Command\GradeCommand;
use AlexSkrypnyk\SkillTest\Command\InitCommand;
use AlexSkrypnyk\SkillTest\Command\LlmCommand;
use AlexSkrypnyk\SkillTest\Command\MatrixCommand;
use AlexSkrypnyk\SkillTest\Command\McpServeCommand;
use AlexSkrypnyk\SkillTest\Command\MigrateCommand;
use AlexSkrypnyk\SkillTest\Command\RecordCommand;
use AlexSkrypnyk\SkillTest\Command\ReportCommand;
use AlexSkrypnyk\SkillTest\Command\RunCommand;
use AlexSkrypnyk\SkillTest\Command\SecurityCommand;
use AlexSkrypnyk\SkillTest\Command\SelfUpdateCommand;
use AlexSkrypnyk\SkillTest\Command\StructureCommand;
use AlexSkrypnyk\SkillTest\Command\TokensCommand;
use AlexSkrypnyk\SkillTest\Command\ValidateCommand;
use AlexSkrypnyk\SkillTest\Command\VersionCommand;
use AlexSkrypnyk\SkillTest\Update\ReleaseClient;
use AlexSkrypnyk\SkillTest\Update\UpdateNotifier;
use AlexSkrypnyk\SkillTest\Version;
use Symfony\Component\Console\Application;

// @codeCoverageIgnoreStart
$application = new Application(Version::NAME, Version::id());

$notifier = new UpdateNotifier(new ReleaseClient(ReleaseClient::liveFetcher()), getenv(), Version::id());

$application->add(new VersionCommand());
$application->add(new ValidateCommand());
$application->add(new CoverageCommand());
$application->add(new SecurityCommand());
$application->add(new InitCommand());
$application->add(new StructureCommand());
$application->add(new TokensCommand());
$application->add(new RunCommand($notifier));
$application->add(new LlmCommand());
$application->add(new MatrixCommand());
$application->add(new RecordCommand());
$application->add(new McpServeCommand());
$application->add(new MigrateCommand());
$application->add(new SelfUpdateCommand());
$application->add(new CacheCommand());
$application->add(new GradeCommand());
$application->add(new GateCommand());
$application->add(new CompareCommand());
$application->add(new ReportCommand());

$application->setDefaultCommand('run');

$application->run();
// @codeCoverageIgnoreEnd
