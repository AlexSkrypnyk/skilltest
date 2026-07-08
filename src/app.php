<?php

/**
 * @file
 * Main entry point for the application.
 */

declare(strict_types=1);

use AlexSkrypnyk\SkillTest\Command\VersionCommand;
use AlexSkrypnyk\SkillTest\Version;
use Symfony\Component\Console\Application;

// @codeCoverageIgnoreStart
$application = new Application(Version::NAME, Version::id());

$application->add(new VersionCommand());

$application->run();
// @codeCoverageIgnoreEnd
