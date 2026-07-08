<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Contract;

use AlexSkrypnyk\SkillTest\Config\Data;

/**
 * Asserts a skill's declared contract against a transcript.
 *
 * Grades the world, not the words: it reads the tool-use events a transcript
 * records and answers the contract's questions - which tools, commands, and
 * sub-skills had to appear, and which must never. Bash commands are normalised
 * through the repo aliases first, so every invocation form of an aliased binary
 * collapses to one before matching. Every assertion becomes a
 * {@see CheckResult} carrying the evidence, so a failure is debuggable from the
 * report alone. No model is involved; the identical checker grades the recorded
 * deterministic fixture and every live llm trial.
 */
final readonly class ContractChecker {

  /**
   * Constructs a ContractChecker.
   *
   * @param array<string, string> $aliases
   *   Repo command aliases: canonical name keyed to its identifying pattern.
   */
  public function __construct(
    protected array $aliases = [],
  ) {}

  /**
   * Checks a transcript against a normalised contract.
   *
   * @param \AlexSkrypnyk\SkillTest\Contract\Transcript $transcript
   *   The parsed transcript.
   * @param array<string, mixed> $contract
   *   The normalised contract (tools, commands, skills), as produced by
   *   {@see \AlexSkrypnyk\SkillTest\Config\EffectiveConfig}, with repo guards
   *   already folded into the forbidden commands.
   *
   * @return list<\AlexSkrypnyk\SkillTest\Contract\CheckResult>
   *   One result per assertion, in tools-commands-skills order.
   */
  public function check(Transcript $transcript, array $contract): array {
    $tools = Data::toArray(Data::get($contract, 'tools'));
    $commands = Data::toArray(Data::get($contract, 'commands'));
    $skills = Data::toArray(Data::get($contract, 'skills'));

    $results = [];

    $tool_names = $transcript->toolNames();
    foreach (Data::toStringList(Data::get($tools, 'required')) as $name) {
      $results[] = $this->membership('contract.tools.required', 'tool', $name, $tool_names, TRUE);
    }
    foreach (Data::toStringList(Data::get($tools, 'forbidden')) as $name) {
      $results[] = $this->membership('contract.tools.forbidden', 'tool', $name, $tool_names, FALSE);
    }

    $normalised = Aliases::normaliseAll($transcript->bashCommands(), $this->aliases);
    foreach (Data::toStringMap(Data::get($commands, 'required')) as $label => $pattern) {
      $results[] = $this->command('contract.commands.required', (string) $label, $pattern, $normalised, TRUE);
    }
    foreach (Data::toStringMap(Data::get($commands, 'forbidden')) as $label => $pattern) {
      $results[] = $this->command('contract.commands.forbidden', (string) $label, $pattern, $normalised, FALSE);
    }

    $skill_names = $transcript->skillInvocations();
    foreach (Data::toStringList(Data::get($skills, 'required')) as $name) {
      $results[] = $this->membership('contract.skills.required', 'skill', $name, $skill_names, TRUE);
    }
    foreach (Data::toStringList(Data::get($skills, 'forbidden')) as $name) {
      $results[] = $this->membership('contract.skills.forbidden', 'skill', $name, $skill_names, FALSE);
    }

    return $results;
  }

  /**
   * Asserts a named tool or skill is present (required) or absent (forbidden).
   *
   * @param string $id
   *   The stable check id.
   * @param string $noun
   *   The singular noun naming the entry (`tool` or `skill`).
   * @param string $name
   *   The tool or skill name.
   * @param string[] $haystack
   *   The names actually seen in the transcript.
   * @param bool $required
   *   TRUE to require presence, FALSE to forbid it.
   *
   * @return \AlexSkrypnyk\SkillTest\Contract\CheckResult
   *   The assertion result.
   */
  protected function membership(string $id, string $noun, string $name, array $haystack, bool $required): CheckResult {
    $present = in_array($name, $haystack, TRUE);

    if ($required) {
      return $present
        ? CheckResult::pass($id, $name, $name, sprintf("%s '%s' was used.", $noun, $name))
        : CheckResult::fail($id, $name, '', sprintf("required %s '%s' was never used.", $noun, $name));
    }

    return $present
      ? CheckResult::fail($id, $name, $name, sprintf("forbidden %s '%s' was used.", $noun, $name))
      : CheckResult::pass($id, $name, '', sprintf("forbidden %s '%s' was not used.", $noun, $name));
  }

  /**
   * Asserts a command pattern matches (required) or never matches (forbidden).
   *
   * @param string $id
   *   The stable check id.
   * @param string $label
   *   The human label the contract gave the behaviour.
   * @param string $pattern
   *   The pattern position (a regex or a `pack:<name>` reference).
   * @param string[] $commands
   *   The alias-normalised commands, in execution order.
   * @param bool $required
   *   TRUE to require a match, FALSE to forbid one.
   *
   * @return \AlexSkrypnyk\SkillTest\Contract\CheckResult
   *   The assertion result.
   */
  protected function command(string $id, string $label, string $pattern, array $commands, bool $required): CheckResult {
    $evidence = Matcher::firstMatch($commands, $pattern);

    if ($required) {
      return $evidence !== NULL
        ? CheckResult::pass($id, $label, $evidence, sprintf("'%s' matched: %s", $label, $evidence))
        : CheckResult::fail($id, $label, '', sprintf("required behaviour '%s' matched no command (pattern: %s).", $label, $pattern));
    }

    return $evidence !== NULL
      ? CheckResult::fail($id, $label, $evidence, sprintf("forbidden behaviour '%s' matched: %s (pattern: %s).", $label, $evidence, $pattern))
      : CheckResult::pass($id, $label, '', sprintf("forbidden behaviour '%s' matched no command.", $label));
  }

}
