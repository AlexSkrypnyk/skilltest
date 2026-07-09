<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Command;

use AlexSkrypnyk\SkillTest\Config\ConfigLoader;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedSkill;
use AlexSkrypnyk\SkillTest\Config\SchemaVersion;
use AlexSkrypnyk\SkillTest\Contract\CheckResult;
use AlexSkrypnyk\SkillTest\Contract\TranscriptGrader;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;
use AlexSkrypnyk\SkillTest\ExitCode;
use AlexSkrypnyk\SkillTest\Grade\Grader;
use AlexSkrypnyk\SkillTest\Grade\RescoreResult;
use AlexSkrypnyk\SkillTest\Judge\Judge;
use AlexSkrypnyk\SkillTest\Live\AgentPreflight;
use AlexSkrypnyk\SkillTest\Live\LlmSuite;
use AlexSkrypnyk\SkillTest\Results\ResultsDocument;
use AlexSkrypnyk\SkillTest\Results\ResultsException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The `grade` command: re-grade offline, without executing an agent.
 *
 * Grading is a pure function of a transcript and a contract, so it can be run
 * without the run that produced the transcript. Two modes serve two needs:
 * `--transcript <file> --skill <name>` asserts a skill's contract against any
 * transcript - the same verdict the deterministic transcript group reaches, on
 * an arbitrary file; and `--results <file>` re-scores every trial in a saved run
 * against the current contract, so a tightened rule shows exactly which trials
 * it would now fail. Both are token-free; only `--judge` spends tokens, to
 * re-run the rubric against each trial's stored transcript. Exit codes mirror
 * the tool contract: `0` pass, `1` a failing check or re-scored verdict, `2` a
 * configuration error.
 */
class GradeCommand extends Command {

  use ResultsCommandTrait;

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setName('grade')
      ->setDescription('Re-grade a transcript or re-score a saved run without executing an agent')
      ->addOption(name: 'dir', mode: InputOption::VALUE_REQUIRED, description: 'Repository root (default: current directory)')
      ->addOption(name: 'transcript', mode: InputOption::VALUE_REQUIRED, description: "Assert a skill's contract against this transcript file")
      ->addOption(name: 'skill', mode: InputOption::VALUE_REQUIRED, description: 'The skill whose contract to assert (required with --transcript)')
      ->addOption(name: 'results', mode: InputOption::VALUE_REQUIRED, description: 'Re-score this saved results.json against the current contract')
      ->addOption(name: 'judge', mode: InputOption::VALUE_NONE, description: 'Re-run the judge when re-scoring (spends tokens; needs an authenticated agent)')
      ->addOption(name: 'json', mode: InputOption::VALUE_NONE, description: 'Emit the machine-readable result on stdout and nothing else');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $stderr = $this->stderr($output);
    $root = $this->resolveRoot($input);
    $json = (bool) $input->getOption('json');

    $transcript = $this->stringOption($input, 'transcript');
    $results = $this->stringOption($input, 'results');

    if ($transcript === NULL && $results === NULL) {
      return $this->configError($stderr, 'pass --transcript <file> (with --skill) or --results <file>.');
    }

    if ($transcript !== NULL && $results !== NULL) {
      return $this->configError($stderr, 'pass only one of --transcript or --results.');
    }

    try {
      $loaded = (new ConfigLoader($root))->load();
    }
    catch (ConfigException $config_exception) {
      return $this->configError($stderr, $config_exception->getMessage());
    }

    if ($transcript !== NULL) {
      return $this->gradeTranscript($loaded, $root, $transcript, $this->stringOption($input, 'skill'), $output, $stderr, $json);
    }

    return $this->gradeResults($loaded, $root, (string) $results, (bool) $input->getOption('judge'), $output, $stderr, $json);
  }

  /**
   * Grades one transcript file against a named skill's contract.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded
   *   The loaded configuration.
   * @param string $root
   *   The repository root.
   * @param string $transcript_file
   *   The transcript file to grade.
   * @param string|null $skill_name
   *   The skill whose contract to assert.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param \Symfony\Component\Console\Output\OutputInterface $stderr
   *   The error output.
   * @param bool $json
   *   Whether to emit the machine-readable result.
   *
   * @return int
   *   The exit code.
   */
  protected function gradeTranscript(LoadedConfig $loaded, string $root, string $transcript_file, ?string $skill_name, OutputInterface $output, OutputInterface $stderr, bool $json): int {
    if ($skill_name === NULL) {
      return $this->configError($stderr, 'the --skill option is required with --transcript.');
    }

    if (!is_file($transcript_file)) {
      return $this->configError($stderr, sprintf('transcript file not found: %s.', $transcript_file));
    }

    $skill = $this->matchSkill($loaded, $skill_name);

    if (!$skill instanceof LoadedSkill) {
      return $this->configError($stderr, sprintf("no skill named '%s' with an %s was found.", $skill_name, $loaded->repo->evalFile));
    }

    $checks = (new TranscriptGrader($root, $loaded->repo->aliases))->grade($transcript_file, $skill->effective->contract, $skill->effective->checks, dirname($skill->file));
    $pass = array_reduce($checks, static fn(bool $carry, CheckResult $result): bool => $carry && $result->pass, TRUE);

    if ($json) {
      $output->writeln($this->encode(['ok' => $pass, 'skill' => $skill_name, 'checks' => array_map(static fn(CheckResult $result): array => $result->toArray(), $checks)]), OutputInterface::VERBOSITY_QUIET);

      return $pass ? ExitCode::PASS : ExitCode::FAIL;
    }

    $output->writeln(sprintf("Graded %s against skill '%s': %d check(s).", $transcript_file, $skill_name, count($checks)));

    if ($pass) {
      $output->writeln('Contract holds.');

      return ExitCode::PASS;
    }

    $output->writeln('Contract failed:');

    foreach ($checks as $check) {
      if (!$check->pass) {
        $output->writeln($this->failureLine($check));
      }
    }

    return ExitCode::FAIL;
  }

  /**
   * Re-scores a saved run against the current contract, optionally re-judging.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded
   *   The loaded configuration.
   * @param string $root
   *   The repository root.
   * @param string $results_file
   *   The saved results.json to re-score.
   * @param bool $rejudge
   *   Whether to re-run the judge (spends tokens).
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param \Symfony\Component\Console\Output\OutputInterface $stderr
   *   The error output.
   * @param bool $json
   *   Whether to emit the re-scored document.
   *
   * @return int
   *   The exit code.
   */
  protected function gradeResults(LoadedConfig $loaded, string $root, string $results_file, bool $rejudge, OutputInterface $output, OutputInterface $stderr, bool $json): int {
    try {
      $document = ResultsDocument::fromFile($results_file);
    }
    catch (ResultsException $results_exception) {
      return $this->configError($stderr, $results_exception->getMessage());
    }

    if (!$document->isCurrentMajor()) {
      return $this->configError($stderr, sprintf("the results file is schema version '%s'; this tool reads major %d (run 'skilltest migrate').", $document->version(), SchemaVersion::CURRENT_MAJOR));
    }

    $judge = NULL;

    if ($rejudge) {
      $preflight = new AgentPreflight(getenv());
      $problem = $preflight->problem();

      if ($problem !== NULL) {
        return $this->configError($stderr, $problem);
      }

      $judge = new Judge((string) $preflight->binary(), NULL, $this->judgeTimeout());
    }

    $result = (new Grader($root, $judge))->rescore($document->data, $loaded, dirname($results_file));
    $failing = $this->failingVerdicts(new ResultsDocument($result->document));

    if ($json) {
      $output->writeln($this->encodePretty($result->document), OutputInterface::VERBOSITY_QUIET);

      return $failing === 0 ? ExitCode::PASS : ExitCode::FAIL;
    }

    $this->reportRescore($output, $result, $failing);

    return $failing === 0 ? ExitCode::PASS : ExitCode::FAIL;
  }

  /**
   * Renders the human re-score summary.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param \AlexSkrypnyk\SkillTest\Grade\RescoreResult $result
   *   The re-score result.
   * @param int $failing
   *   The number of failing task-on-model verdicts after re-scoring.
   */
  protected function reportRescore(OutputInterface $output, RescoreResult $result, int $failing): void {
    $output->writeln(sprintf('Re-scored %d trial(s): %d newly failing, %d newly passing.', $result->trialsRescored, $result->newlyFailing, $result->newlyPassing));

    foreach ($result->notes as $note) {
      $output->writeln('  note: ' . $note);
    }

    $output->writeln($failing === 0 ? 'All task-on-model verdicts pass.' : sprintf('%d task-on-model verdict(s) fail after re-scoring.', $failing));
  }

  /**
   * The number of failing task-on-model verdicts in a document.
   *
   * @param \AlexSkrypnyk\SkillTest\Results\ResultsDocument $document
   *   The document to tally.
   *
   * @return int
   *   The failing-verdict count.
   */
  protected function failingVerdicts(ResultsDocument $document): int {
    $failing = 0;

    foreach ($document->tasks() as $view) {
      foreach ($view->modelPassed as $passed) {
        $failing += $passed ? 0 : 1;
      }
    }

    return $failing;
  }

  /**
   * Finds the loaded skill whose name matches, or NULL.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded
   *   The loaded configuration.
   * @param string $name
   *   The requested skill name.
   *
   * @return \AlexSkrypnyk\SkillTest\Config\LoadedSkill|null
   *   The matching skill, or NULL.
   */
  protected function matchSkill(LoadedConfig $loaded, string $name): ?LoadedSkill {
    foreach ($loaded->skills as $skill) {
      if ($skill->effective->skill === $name) {
        return $skill;
      }
    }

    return NULL;
  }

  /**
   * Renders one failed check as an indented line with its evidence.
   *
   * @param \AlexSkrypnyk\SkillTest\Contract\CheckResult $failure
   *   The failed check.
   *
   * @return string
   *   The rendered line.
   */
  protected function failureLine(CheckResult $failure): string {
    $line = sprintf('  %s FAIL - %s', $failure->id, $failure->message);

    return $failure->evidence === '' ? $line : sprintf('%s [%s]', $line, $failure->evidence);
  }

  /**
   * Resolves the judge timeout from the environment, or the default.
   *
   * @return float
   *   The timeout in seconds.
   */
  protected function judgeTimeout(): float {
    $value = getenv(LlmSuite::ENV_TIMEOUT);

    return is_string($value) && is_numeric($value) ? (float) $value : Judge::DEFAULT_TIMEOUT;
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
    return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
  }

  /**
   * Encodes a results document as pretty JSON with a trailing newline.
   *
   * @param array<string, mixed> $document
   *   The results document.
   *
   * @return string
   *   The encoded JSON.
   */
  protected function encodePretty(array $document): string {
    return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
  }

}
