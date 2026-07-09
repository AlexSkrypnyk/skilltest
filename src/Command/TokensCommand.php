<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Config\Discovery;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Config\SkillFiles;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\ExitCode;
use AlexSkrypnyk\SkillTest\Structure\StructureChecker;
use AlexSkrypnyk\SkillTest\Tokens\GitRef;
use AlexSkrypnyk\SkillTest\Tokens\TokenCounter;
use AlexSkrypnyk\SkillTest\Tokens\TokenDelta;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Tokens command.
 *
 * Token accounting so skill files stay small on purpose: `tokens count`
 * reports per-file counts for markdown files, and `tokens compare` diffs the
 * counts of every discovered skill's markdown files against a git ref so CI
 * can gate on skill bloat. Counts come from the same {@see TokenCounter} that
 * backs the `structure.token-budget` check - estimated by default, exact
 * byte-pair encoding when a vocabulary file is supplied. Growth beyond
 * `--threshold` or, under `--strict`, a file over its absolute limit fails
 * with exit 1; a bad action, path, ref, or vocabulary fails with exit 2.
 */
class TokensCommand extends Command {

  /**
   * The supported actions.
   */
  public const array ACTIONS = ['count', 'compare'];

  /**
   * The supported output formats.
   */
  public const array FORMATS = ['table', 'json'];

  /**
   * The supported sort orders for `count`.
   */
  public const array SORTS = ['path', 'tokens'];

  /**
   * Runs a git command; injected so compare is testable without real git.
   *
   * @var \Closure|null
   */
  protected ?\Closure $gitRunner;

  /**
   * Constructs a TokensCommand.
   *
   * @param \Closure|null $git_runner
   *   A runner taking the assembled git command and working directory and
   *   returning `[exitCode, stdout]`, or NULL to run git for real.
   */
  public function __construct(?\Closure $git_runner = NULL) {
    parent::__construct();
    $this->gitRunner = $git_runner;
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setName('tokens')
      ->setDescription('Count tokens in skill markdown files, or compare counts against a git ref')
      ->addArgument(name: 'action', mode: InputArgument::REQUIRED, description: 'The action: count or compare')
      ->addArgument(name: 'targets', mode: InputArgument::IS_ARRAY, description: 'Paths to count (count), or the git ref to compare against (compare)', default: [])
      ->addOption(name: 'dir', mode: InputOption::VALUE_REQUIRED, description: 'Repository root (default: current directory)')
      ->addOption(name: 'format', mode: InputOption::VALUE_REQUIRED, description: 'Output format: table or json', default: 'table')
      ->addOption(name: 'sort', mode: InputOption::VALUE_REQUIRED, description: 'Sort order for count: path or tokens', default: 'path')
      ->addOption(name: 'vocab', mode: InputOption::VALUE_REQUIRED, description: 'Tiktoken-format vocabulary file for exact BPE counts (default: estimation)')
      ->addOption(name: 'threshold', mode: InputOption::VALUE_REQUIRED, description: 'Fail compare when an existing file grows more than this percentage')
      ->addOption(name: 'strict', mode: InputOption::VALUE_NONE, description: 'Fail compare when any file exceeds its absolute token limit');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $root = $this->resolveRoot($input);
    $action = $input->getArgument('action');
    $format = $input->getOption('format');
    $sort = $input->getOption('sort');

    if (!is_string($action) || !in_array($action, self::ACTIONS, TRUE)) {
      return $this->error($output, sprintf('unknown action; expected one of: %s.', implode(', ', self::ACTIONS)));
    }

    if (!is_string($format) || !in_array($format, self::FORMATS, TRUE)) {
      return $this->error($output, sprintf('unknown format; expected one of: %s.', implode(', ', self::FORMATS)));
    }

    if (!is_string($sort) || !in_array($sort, self::SORTS, TRUE)) {
      return $this->error($output, sprintf('unknown sort; expected one of: %s.', implode(', ', self::SORTS)));
    }

    try {
      return $action === 'count'
        ? $this->runCount($input, $output, $root, $format, $sort)
        : $this->runCompare($input, $output, $root, $format);
    }
    catch (ConfigException $config_exception) {
      return $this->error($output, $config_exception->getMessage());
    }
  }

  /**
   * Runs the `count` action: per-file token counts for markdown files.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param string $root
   *   The repository root.
   * @param string $format
   *   The output format.
   * @param string $sort
   *   The sort order.
   *
   * @return int
   *   The exit code.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When a configured vocabulary cannot be read or parsed.
   */
  protected function runCount(InputInterface $input, OutputInterface $output, string $root, string $format, string $sort): int {
    $targets = $this->targets($input);

    if ($targets === []) {
      return $this->error($output, 'tokens count expects at least one path.');
    }

    $counter = $this->counter($input, $root);
    $rows = [];

    foreach ($targets as $target) {
      $absolute = $this->absoluteTarget($root, $target);

      if (is_dir($absolute)) {
        foreach (SkillFiles::markdownUnder($absolute) as $file) {
          $rows[$this->displayPath($root, $file)] = $counter->count($this->contents($file));
        }

        continue;
      }

      if (!is_file($absolute)) {
        return $this->error($output, sprintf("path '%s' does not exist.", $target));
      }

      $rows[$this->displayPath($root, $absolute)] = $counter->count($this->contents($absolute));
    }

    $rows = $this->sortRows($rows, $sort);

    if ($format === 'json') {
      $output->writeln($this->encode([
        'ok' => TRUE,
        'method' => $counter->method(),
        'files' => array_map(static fn(string $path): array => ['path' => $path, 'tokens' => $rows[$path]], array_keys($rows)),
        'total' => ['files' => count($rows), 'tokens' => array_sum($rows)],
      ]));

      return ExitCode::PASS;
    }

    $this->renderCountTable($output, $rows, $counter->method());

    return ExitCode::PASS;
  }

  /**
   * Runs the `compare` action: skill markdown counts against a git ref.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param string $root
   *   The repository root.
   * @param string $format
   *   The output format.
   *
   * @return int
   *   The exit code.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the configuration, the ref, the threshold, or the vocabulary is
   *   unusable.
   */
  protected function runCompare(InputInterface $input, OutputInterface $output, string $root, string $format): int {
    $targets = $this->targets($input);

    if (count($targets) > 1) {
      return $this->error($output, 'tokens compare expects at most one ref.');
    }

    $threshold = $this->threshold($input);
    $strict = (bool) $input->getOption('strict');
    $counter = $this->counter($input, $root);
    $loaded = (new ConfigLoader($root))->load();
    $git = new GitRef($root, $this->gitRunner);
    $ref = $git->resolve($targets[0] ?? NULL);

    [$deltas, $limits] = $this->collectDeltas($loaded, $git, $ref, $counter, $root);
    $violations = $this->violations($deltas, $limits, $threshold, $strict);

    if ($format === 'json') {
      $output->writeln($this->encode([
        'ok' => $violations === [],
        'ref' => $ref,
        'method' => $counter->method(),
        'threshold' => $threshold,
        'strict' => $strict,
        'files' => array_map(static fn(TokenDelta $delta): array => $delta->toArray(), $deltas),
        'violations' => $violations,
        'summary' => $this->compareSummary($deltas, $violations),
      ]));
    }
    else {
      $this->renderCompareTable($output, $deltas, $violations, $ref, $counter->method());
    }

    return $violations === [] ? ExitCode::PASS : ExitCode::FAIL;
  }

  /**
   * Collects the token deltas and per-file absolute limits for compare.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded
   *   The loaded configuration.
   * @param \AlexSkrypnyk\SkillTest\Tokens\GitRef $git
   *   The git ref reader.
   * @param string $ref
   *   The resolved ref.
   * @param \AlexSkrypnyk\SkillTest\Tokens\TokenCounter $counter
   *   The token counter.
   * @param string $root
   *   The repository root.
   *
   * @return array{0: \AlexSkrypnyk\SkillTest\Tokens\TokenDelta[], 1: array<string, int>}
   *   The deltas sorted by path, and the absolute limit for each path.
   */
  protected function collectDeltas(LoadedConfig $loaded, GitRef $git, string $ref, TokenCounter $counter, string $root): array {
    $deltas = [];
    $limits = [];

    foreach ($loaded->skills as $skill) {
      $dir = dirname($skill->file);
      $marker = $dir . '/' . Discovery::MARKER;

      foreach (SkillFiles::markdownUnder($dir) as $file) {
        $relative = $this->displayPath($root, $file);
        $ref_content = $git->contentAt($ref, $relative);

        $deltas[] = new TokenDelta($relative, $counter->count($this->contents($file)), $ref_content === NULL ? NULL : $counter->count($ref_content));
        $limits[$relative] = $file === $marker ? $this->skillLimit($skill->effective->structure) : StructureChecker::DEFAULT_TOKEN_LIMIT;
      }
    }

    usort($deltas, static fn(TokenDelta $a, TokenDelta $b): int => strcmp($a->path, $b->path));

    return [$deltas, $limits];
  }

  /**
   * The absolute token limit a skill's `SKILL.md` is subject to.
   *
   * @param array<string, mixed> $structure
   *   The skill's effective structure block.
   *
   * @return int
   *   The configured `structure.token-budget` limit, or the default.
   */
  protected function skillLimit(array $structure): int {
    $params = Data::toArray(Data::get($structure, 'params', StructureChecker::CHECK_TOKEN_BUDGET));

    return Data::toIntOrNull(Data::get($params, 'limit')) ?? StructureChecker::DEFAULT_TOKEN_LIMIT;
  }

  /**
   * Evaluates the gate rules over the deltas.
   *
   * Growth gating applies only to files that existed at the ref - new files
   * are exempt by design so adding a skill is never itself a regression - and
   * growth from an empty file counts as unbounded, violating any threshold.
   * The strict gate applies each file's absolute limit to every file,
   * including new ones.
   *
   * @param \AlexSkrypnyk\SkillTest\Tokens\TokenDelta[] $deltas
   *   The deltas.
   * @param array<string, int> $limits
   *   The absolute limit for each path.
   * @param float|null $threshold
   *   The growth threshold percentage, or NULL when not gating on growth.
   * @param bool $strict
   *   Whether to enforce absolute limits.
   *
   * @return string[]
   *   The violation messages.
   */
  protected function violations(array $deltas, array $limits, ?float $threshold, bool $strict): array {
    $violations = [];

    foreach ($deltas as $delta) {
      $ref_tokens = $delta->refTokens;

      if ($threshold !== NULL && $ref_tokens !== NULL) {
        $growth = $delta->growthPct();

        if ($growth !== NULL && $growth > $threshold) {
          $violations[] = sprintf('%s grew %.1f%% (%d -> %d tokens), above the %.1f%% threshold.', $delta->path, $growth, $ref_tokens, $delta->tokens, $threshold);
        }
        elseif ($ref_tokens === 0 && $delta->tokens > 0) {
          $violations[] = sprintf('%s grew from 0 to %d tokens, above the %.1f%% threshold.', $delta->path, $delta->tokens, $threshold);
        }
      }

      $limit = $limits[$delta->path] ?? StructureChecker::DEFAULT_TOKEN_LIMIT;

      if ($strict && $delta->tokens > $limit) {
        $violations[] = sprintf('%s is %d tokens, above the absolute limit of %d.', $delta->path, $delta->tokens, $limit);
      }
    }

    return $violations;
  }

  /**
   * Builds the machine-readable compare summary counts.
   *
   * @param \AlexSkrypnyk\SkillTest\Tokens\TokenDelta[] $deltas
   *   The deltas.
   * @param string[] $violations
   *   The violation messages.
   *
   * @return array{files: int, changed: int, new: int, violations: int}
   *   The summary counts.
   */
  protected function compareSummary(array $deltas, array $violations): array {
    $changed = count(array_filter($deltas, static fn(TokenDelta $delta): bool => !$delta->isNew() && $delta->delta() !== 0));
    $new = count(array_filter($deltas, static fn(TokenDelta $delta): bool => $delta->isNew()));

    return [
      'files' => count($deltas),
      'changed' => $changed,
      'new' => $new,
      'violations' => count($violations),
    ];
  }

  /**
   * Renders the count rows as an aligned table with a totals line.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param array<string, int> $rows
   *   The token counts keyed by display path, already sorted.
   * @param string $method
   *   The counting method.
   */
  protected function renderCountTable(OutputInterface $output, array $rows, string $method): void {
    $width = 6;

    foreach ($rows as $tokens) {
      $width = max($width, strlen((string) $tokens));
    }

    $output->writeln(str_pad('TOKENS', $width, ' ', STR_PAD_LEFT) . '  PATH');

    foreach ($rows as $path => $tokens) {
      $output->writeln(str_pad((string) $tokens, $width, ' ', STR_PAD_LEFT) . '  ' . $path);
    }

    $output->writeln('');
    $output->writeln(sprintf('%d file(s), %d token(s) total (method: %s).', count($rows), array_sum($rows), $method));
  }

  /**
   * Renders the compare rows, violations, and summary line.
   *
   * Unchanged existing files are counted but not listed, so the report draws
   * the eye to what moved: grown, shrunk, and new files, then violations.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param \AlexSkrypnyk\SkillTest\Tokens\TokenDelta[] $deltas
   *   The deltas, sorted by path.
   * @param string[] $violations
   *   The violation messages.
   * @param string $ref
   *   The resolved ref.
   * @param string $method
   *   The counting method.
   */
  protected function renderCompareTable(OutputInterface $output, array $deltas, array $violations, string $ref, string $method): void {
    $notable = array_values(array_filter($deltas, static fn(TokenDelta $delta): bool => $delta->isNew() || $delta->delta() !== 0));

    foreach ($notable as $delta) {
      $output->writeln($this->renderDelta($delta));
    }

    if ($notable !== []) {
      $output->writeln('');
    }

    foreach ($violations as $violation) {
      $output->writeln('FAIL ' . $violation);
    }

    if ($violations !== []) {
      $output->writeln('');
    }

    $summary = $this->compareSummary($deltas, $violations);
    $output->writeln(sprintf('%d file(s) compared against %s: %d changed, %d new, %d violation(s) (method: %s).', $summary['files'], $ref, $summary['changed'], $summary['new'], $summary['violations'], $method));
  }

  /**
   * Renders one notable delta as a single scannable line.
   *
   * @param \AlexSkrypnyk\SkillTest\Tokens\TokenDelta $delta
   *   The delta.
   *
   * @return string
   *   The rendered line.
   */
  protected function renderDelta(TokenDelta $delta): string {
    $ref_tokens = $delta->refTokens;

    if ($ref_tokens === NULL) {
      return sprintf('%s (new) %d token(s)', $delta->path, $delta->tokens);
    }

    $growth = $delta->growthPct();
    $pct = $growth === NULL ? 'n/a' : sprintf('%+.1f%%', $growth);

    return sprintf('%s %d -> %d token(s) (%+d, %s)', $delta->path, $ref_tokens, $delta->tokens, $delta->delta(), $pct);
  }

  /**
   * The positional targets as a clean string list.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   *
   * @return string[]
   *   The targets.
   */
  protected function targets(InputInterface $input): array {
    return Data::toStringList($input->getArgument('targets'));
  }

  /**
   * Parses the growth threshold option.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   *
   * @return float|null
   *   The threshold percentage, or NULL when not given.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the value is not a non-negative number.
   */
  protected function threshold(InputInterface $input): ?float {
    $raw = $input->getOption('threshold');

    if ($raw === NULL) {
      return NULL;
    }

    if (!is_string($raw) || !is_numeric($raw) || (float) $raw < 0) {
      throw new ConfigException('threshold must be a non-negative number of percent.');
    }

    return (float) $raw;
  }

  /**
   * Builds the counter, resolving any vocabulary option against the root.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   * @param string $root
   *   The repository root.
   *
   * @return \AlexSkrypnyk\SkillTest\Tokens\TokenCounter
   *   The counter.
   */
  protected function counter(InputInterface $input, string $root): TokenCounter {
    $vocab = $input->getOption('vocab');

    if (!is_string($vocab) || $vocab === '') {
      return new TokenCounter();
    }

    return new TokenCounter($this->absoluteTarget($root, $vocab));
  }

  /**
   * Resolves a target path: as given when it exists, else against the root.
   *
   * @param string $root
   *   The repository root.
   * @param string $target
   *   The path as the user gave it.
   *
   * @return string
   *   The resolved path.
   */
  protected function absoluteTarget(string $root, string $target): string {
    if (file_exists($target)) {
      return $target;
    }

    return rtrim($root, '/') . '/' . ltrim($target, '/');
  }

  /**
   * Renders a path relative to the repository root when it is under it.
   *
   * @param string $root
   *   The repository root.
   * @param string $path
   *   The path to render.
   *
   * @return string
   *   The root-relative path, or the path unchanged.
   */
  protected function displayPath(string $root, string $path): string {
    $prefix = rtrim($root, '/') . '/';

    return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
  }

  /**
   * Reads a file, treating an unreadable one as empty.
   *
   * @param string $path
   *   The file path.
   *
   * @return string
   *   The file contents.
   */
  protected function contents(string $path): string {
    $content = @file_get_contents($path);

    // @codeCoverageIgnoreStart
    return $content === FALSE ? '' : $content;
    // @codeCoverageIgnoreEnd
  }

  /**
   * Sorts count rows by the requested order.
   *
   * @param array<string, int> $rows
   *   The token counts keyed by display path.
   * @param string $sort
   *   The sort order: `path` byte-ascending, `tokens` descending with the
   *   path as the tiebreak.
   *
   * @return array<string, int>
   *   The sorted rows.
   */
  protected function sortRows(array $rows, string $sort): array {
    ksort($rows);

    if ($sort === 'tokens') {
      arsort($rows);
    }

    return $rows;
  }

  /**
   * Resolves the repository root from the option or the current directory.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   *
   * @return string
   *   The repository root.
   */
  protected function resolveRoot(InputInterface $input): string {
    $dir = $input->getOption('dir');

    if (is_string($dir) && $dir !== '') {
      return $dir;
    }

    $cwd = getcwd();

    // @codeCoverageIgnoreStart
    if ($cwd === FALSE) {
      return '.';
    }
    // @codeCoverageIgnoreEnd
    return $cwd;
  }

  /**
   * Reports one error line and returns the config-error exit code.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param string $message
   *   The error message.
   *
   * @return int
   *   The config-error exit code.
   */
  protected function error(OutputInterface $output, string $message): int {
    $output->writeln('ERROR ' . $message);

    return ExitCode::CONFIG_ERROR;
  }

  /**
   * Encodes a payload as a single JSON line.
   *
   * @param array<string, mixed> $payload
   *   The payload to encode.
   *
   * @return string
   *   The JSON encoding.
   */
  protected function encode(array $payload): string {
    // Percentages are floats by contract; without the zero-fraction flag a
    // whole-number growth such as 20.0 would serialise as an integer and
    // change type under consumers' decoders.
    return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
  }

}
