<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\Ai\PromptRunner;
use AlexSkrypnyk\SkillTest\ExitCode;
use AlexSkrypnyk\SkillTest\Init\AiDraft;
use AlexSkrypnyk\SkillTest\Init\EvalScaffold;
use AlexSkrypnyk\SkillTest\Init\LineDiff;
use AlexSkrypnyk\SkillTest\Init\SkillManifest;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Init command.
 *
 * Scaffolds a `validate`-passing `eval.yaml` next to a skill's `SKILL.md`. The
 * template mode needs no credentials; `--ai` additionally drafts tasks, command
 * patterns, and a rubric from the skill body through the one-shot prompt seam,
 * flagging low-confidence guesses for review and falling back to the template
 * when the model is unavailable. Apply is merge-safe: an existing file is never
 * clobbered without `--force`; instead the command prints a diff of what it
 * would have written and exits 1.
 */
class InitCommand extends Command {

  /**
   * The skill marker the scaffold reads from.
   */
  public const string SKILL_FILE = 'SKILL.md';

  /**
   * The per-skill config file the scaffold writes.
   */
  public const string EVAL_FILE = 'eval.yaml';

  /**
   * The one-shot prompt seam used by `--ai`.
   */
  protected PromptRunner $promptRunner;

  /**
   * Constructs an InitCommand.
   *
   * @param \AlexSkrypnyk\SkillTest\Ai\PromptRunner|null $prompt_runner
   *   The prompt seam for `--ai`; defaults to a real `claude -p` runner.
   */
  public function __construct(?PromptRunner $prompt_runner = NULL) {
    parent::__construct();

    $this->promptRunner = $prompt_runner ?? new PromptRunner();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setName('init')
      ->setDescription('Scaffold an eval.yaml for a skill directory from its SKILL.md')
      ->addArgument(name: 'path', mode: InputArgument::OPTIONAL, description: 'Skill directory (default: current directory)', default: '.')
      ->addOption(name: 'ai', mode: InputOption::VALUE_NONE, description: 'Draft tasks, patterns, and a rubric from the skill body with an authenticated claude')
      ->addOption(name: 'force', mode: InputOption::VALUE_NONE, description: 'Overwrite an existing eval.yaml');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $dir = $this->resolveDir($input);
    $skill_file = $dir . '/' . self::SKILL_FILE;

    if (!is_file($skill_file)) {
      $output->writeln(sprintf('ERROR no %s found in %s.', self::SKILL_FILE, $dir));

      return ExitCode::CONFIG_ERROR;
    }

    $contents = file_get_contents($skill_file);

    // @codeCoverageIgnoreStart
    if ($contents === FALSE) {
      $output->writeln(sprintf('ERROR could not read %s.', $skill_file));

      return ExitCode::CONFIG_ERROR;
    }
    // @codeCoverageIgnoreEnd
    $manifest = SkillManifest::fromString($contents);
    $skill = $this->skillName($manifest, $dir);
    $draft = $input->getOption('ai') === TRUE ? $this->draft($manifest, $output) : NULL;

    $proposed = EvalScaffold::render($skill, $manifest, $draft);
    $target = $dir . '/' . self::EVAL_FILE;

    if (is_file($target) && $input->getOption('force') !== TRUE) {
      return $this->preview($target, $proposed, $output);
    }

    // @codeCoverageIgnoreStart
    if (file_put_contents($target, $proposed) === FALSE) {
      $output->writeln(sprintf('ERROR could not write %s.', $target));

      return ExitCode::CONFIG_ERROR;
    }
    // @codeCoverageIgnoreEnd
    $output->writeln(sprintf('Wrote %s', $target));

    return ExitCode::PASS;
  }

  /**
   * Resolves the skill directory from the path argument.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   *
   * @return string
   *   The skill directory without a trailing slash.
   */
  protected function resolveDir(InputInterface $input): string {
    $path = $input->getArgument('path');

    if (!is_string($path) || $path === '') {
      // @codeCoverageIgnoreStart
      $path = '.';
      // @codeCoverageIgnoreEnd
    }

    return rtrim($path, '/');
  }

  /**
   * Resolves the skill name from the manifest, falling back to the directory.
   *
   * @param \AlexSkrypnyk\SkillTest\Init\SkillManifest $manifest
   *   The parsed manifest.
   * @param string $dir
   *   The skill directory.
   *
   * @return string
   *   The skill name.
   */
  protected function skillName(SkillManifest $manifest, string $dir): string {
    $name = $manifest->name;

    if ($name !== NULL && trim($name) !== '') {
      return trim($name);
    }

    $base = basename($dir);

    // @codeCoverageIgnoreStart
    if ($base === '' || $base === '.') {
      $cwd = getcwd();
      $base = $cwd === FALSE ? 'skill' : basename($cwd);
    }
    // @codeCoverageIgnoreEnd
    return $base;
  }

  /**
   * Drafts eval content from the skill body, or notes when it is unavailable.
   *
   * @param \AlexSkrypnyk\SkillTest\Init\SkillManifest $manifest
   *   The parsed manifest.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   *
   * @return \AlexSkrypnyk\SkillTest\Init\AiDraft|null
   *   The draft, or NULL when the model is unavailable or unparseable.
   */
  protected function draft(SkillManifest $manifest, OutputInterface $output): ?AiDraft {
    $response = $this->promptRunner->run($this->prompt($manifest));
    $draft = $response === NULL ? NULL : AiDraft::fromResponse($response);

    if (!$draft instanceof AiDraft) {
      $output->writeln('AI drafting unavailable; wrote the deterministic template instead.');
    }

    return $draft;
  }

  /**
   * Builds the drafting prompt from the skill body.
   *
   * @param \AlexSkrypnyk\SkillTest\Init\SkillManifest $manifest
   *   The parsed manifest.
   *
   * @return string
   *   The prompt handed to the model.
   */
  protected function prompt(SkillManifest $manifest): string {
    return implode("\n", [
      'You are drafting a skilltest `eval.yaml` for a Claude Code skill.',
      'From the SKILL.md body below, return a JSON object with:',
      '- "tasks": 1-3 objects {name, prompt, confidence} whose prompt would trigger the skill.',
      '- "commands": objects {label, pattern, confidence}; pattern is a delimiter-less regex the skill is expected to run.',
      '- "rubric": 2-5 objects {text, confidence} that are binary pass/fail checks a judge would apply.',
      'Set "confidence" to "low" for any guess a human should double-check, otherwise "high".',
      'Return ONLY the JSON object, with no surrounding prose.',
      '',
      'SKILL.md body:',
      $manifest->body,
    ]);
  }

  /**
   * Prints a diff of the proposed file and returns the fail code.
   *
   * @param string $target
   *   The existing target file.
   * @param string $proposed
   *   The proposed contents.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   *
   * @return int
   *   The fail exit code.
   */
  protected function preview(string $target, string $proposed, OutputInterface $output): int {
    $existing = file_get_contents($target);

    // @codeCoverageIgnoreStart
    if ($existing === FALSE) {
      $existing = '';
    }
    // @codeCoverageIgnoreEnd
    $output->writeln(sprintf('%s already exists; not overwritten. Re-run with --force to replace it.', $target));
    $output->writeln('');
    $output->writeln('Diff (- existing, + proposed):');
    $output->writeln(LineDiff::unified($existing, $proposed));

    return ExitCode::FAIL;
  }

}
