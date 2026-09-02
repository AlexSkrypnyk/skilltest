<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\ExitCode;
use AlexSkrypnyk\SkillTest\Live\TrialCache;
use AlexSkrypnyk\SkillTest\Version;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cache command.
 *
 * Manages the llm result cache the `llm --cache` run reads and writes under
 * the repo's `.skilltest/cache/`. The only action, `clear`, removes every
 * cached trial result so the next `--cache` run re-executes from scratch; it
 * discards the cache wholesale rather than through content-change
 * invalidation.
 */
class CacheCommand extends Command {

  use SharedCommandTrait;

  /**
   * The supported actions.
   */
  public const array ACTIONS = ['clear'];

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setName('cache')
      ->setDescription('Manage the llm result cache: clear removes every cached trial result')
      ->addArgument(name: 'action', mode: InputArgument::REQUIRED, description: 'The action: clear')
      ->addOption(name: 'dir', mode: InputOption::VALUE_REQUIRED, description: 'Repository root (default: current directory)');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $action = $input->getArgument('action');

    if (!is_string($action) || !in_array($action, self::ACTIONS, TRUE)) {
      $output->writeln(sprintf('ERROR unknown action; expected one of: %s.', implode(', ', self::ACTIONS)));

      return ExitCode::CONFIG_ERROR;
    }

    $cache = new TrialCache($this->resolveRoot($input) . '/' . TrialCache::CACHE_DIR, Version::id());
    $removed = $cache->clear();

    $output->writeln(sprintf('cleared %d cached trial result(s).', $removed));

    return ExitCode::PASS;
  }

}
