<?php

/**
 * @file
 * Main entry point for the application.
 */

declare(strict_types=1);

use Symfony\Component\Console\Application;
use AlexSkrypnyk\App\Command\JokeCommand;
use AlexSkrypnyk\App\Command\SayHelloCommand;

// @codeCoverageIgnoreStart
$application = new Application('skilltest', '@skilltest-version@');

$command = new JokeCommand();
$application->add($command);
$application->setDefaultCommand((string) $command->getName());

$command = new SayHelloCommand();
$application->add($command);

$application->run();
// @codeCoverageIgnoreEnd
