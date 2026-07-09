<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Init;

use AlexSkrypnyk\SkillTest\Config\Pcre;
use Symfony\Component\Yaml\Yaml;

/**
 * Renders an `eval.yaml` from a skill manifest and an optional AI draft.
 *
 * The output is valid by construction: `validate` passes on it with no manual
 * edits. The document is hand-assembled rather than dumped so it can carry the
 * guiding comments, TODO markers, and inline confidence flags that make a
 * scaffold worth reading; every interpolated scalar still round-trips through
 * the YAML dumper so a skill name or drafted pattern cannot break the file.
 * The deterministic `transcript` and the token-spending `llm` blocks stay
 * commented until the author opts in, which is also what keeps template mode
 * clear of the fixture-exists and non-empty-rubric coherence rules; an AI draft
 * only activates the `llm` block when it supplies both a task and a rubric, and
 * every drafted command pattern is compile-checked before it is emitted.
 */
final readonly class EvalScaffold {

  /**
   * The always-scaffolded forbidden guards, keyed by label.
   */
  public const array FORBIDDEN_GUARDS = [
    'raw git mutations' => 'pack:git-mutations',
    'raw gh mutations' => 'pack:gh-mutations',
  ];

  /**
   * Renders the scaffolded `eval.yaml` contents.
   *
   * @param string $skill
   *   The resolved skill name.
   * @param \AlexSkrypnyk\SkillTest\Init\SkillManifest $manifest
   *   The parsed skill manifest.
   * @param \AlexSkrypnyk\SkillTest\Init\AiDraft|null $draft
   *   The AI draft, or NULL for a deterministic template.
   *
   * @return string
   *   The `eval.yaml` contents, ending in a newline.
   */
  public static function render(string $skill, SkillManifest $manifest, ?AiDraft $draft): string {
    $commands = $draft instanceof AiDraft ? self::validCommands($draft) : [];

    $lines = [
      'version: "1"',
      '',
      '# Scaffolded by `skilltest init`. Review every value before relying on it:',
      '# the template encodes safe defaults, not the real behaviour of this skill.',
      'skill: ' . self::scalar($skill),
      '',
    ];

    $lines = [...$lines, ...self::contract($manifest, $commands), ''];
    $lines = [...$lines, ...self::security(), ''];
    $lines = [...$lines, ...self::deterministic($skill), ''];

    if ($draft instanceof AiDraft && $draft->tasks !== [] && $draft->rubric !== []) {
      $lines = [...$lines, ...self::activeLlm($draft)];
    }
    else {
      $lines = [...$lines, ...self::commentedLlm($skill)];
    }

    return implode("\n", $lines) . "\n";
  }

  /**
   * Renders the contract block.
   *
   * @param \AlexSkrypnyk\SkillTest\Init\SkillManifest $manifest
   *   The parsed manifest, for the allowed-tools list.
   * @param array<int, array{label: string, pattern: string, low: bool}> $commands
   *   The validated required command patterns.
   *
   * @return string[]
   *   The contract lines.
   */
  protected static function contract(SkillManifest $manifest, array $commands): array {
    $lines = [
      '# The behavioural contract: asserted against the recorded transcript',
      '# (deterministic suite) and every live run (llm suite).',
      'contract:',
      '  tools:',
      '    # Live runs are restricted to exactly these tools, pre-filled from the',
      '    # SKILL.md allowed-tools frontmatter.',
      '    allowed: ' . self::inlineList($manifest->allowedTools),
      '    # Tools a run must / must never use; fill in as the contract firms up.',
      '    required: []',
      '    forbidden: []',
      '  commands:',
      '    # label: pattern - the label names the behaviour, the pattern proves it.',
    ];

    if ($commands === []) {
      $lines[] = '    required: {}';
    }
    else {
      $lines[] = '    required:';

      foreach ($commands as $command) {
        $lines[] = '      ' . self::scalar($command['label']) . ': ' . self::scalar($command['pattern']) . self::note($command['low']);
      }
    }

    $lines[] = '    # Pre-baked packs guard destructive commands without hand-written regex.';
    $lines[] = '    forbidden:';

    foreach (self::FORBIDDEN_GUARDS as $label => $pattern) {
      $lines[] = '      ' . self::scalar($label) . ': ' . $pattern;
    }

    $lines[] = '  skills:';
    $lines[] = '    required: []';
    $lines[] = '    forbidden: []';

    return $lines;
  }

  /**
   * Renders the security block.
   *
   * @return string[]
   *   The security lines.
   */
  protected static function security(): array {
    return [
      'security:',
      '  # The baseline pack runs even when this block is omitted.',
      '  packs: [baseline]',
      '  # Extra strings that must never appear in shipped files.',
      '  forbidden-tokens: []',
    ];
  }

  /**
   * Renders the commented deterministic block.
   *
   * @param string $skill
   *   The resolved skill name.
   *
   * @return string[]
   *   The commented deterministic lines.
   */
  protected static function deterministic(string $skill): array {
    return [
      '# A recorded transcript enables the deterministic transcript group.',
      '# TODO: record one with `skilltest record --skill ' . $skill . '`, then uncomment:',
      '# deterministic:',
      '#   transcript: fixtures/transcript.jsonl',
    ];
  }

  /**
   * Renders the commented llm template.
   *
   * @param string $skill
   *   The resolved skill name.
   *
   * @return string[]
   *   The commented llm lines.
   */
  protected static function commentedLlm(string $skill): array {
    return [
      '# The llm suite spends tokens; it stays commented out until you opt in.',
      '# TODO: describe at least one task and one binary rubric criterion, then',
      '# uncomment and run `skilltest llm --skill ' . $skill . '`.',
      '# llm:',
      '#   tasks:',
      '#     - name: invoked',
      '#       prompt: /' . $skill,
      '#   max-turns: 8',
      '#   trials: 3',
      '#   threshold: 0.8',
      '#   models: ladder',
      '#   judge:',
      '#     rubric:',
      '#       - The skill produces the outcome it promises.',
    ];
  }

  /**
   * Renders an active llm block from a draft.
   *
   * @param \AlexSkrypnyk\SkillTest\Init\AiDraft $draft
   *   The AI draft with at least one task and one rubric criterion.
   *
   * @return string[]
   *   The llm lines.
   */
  protected static function activeLlm(AiDraft $draft): array {
    $lines = [
      '# Drafted by `skilltest init --ai`; review every entry, especially any',
      '# flagged low confidence.',
      'llm:',
      '  tasks:',
    ];

    foreach ($draft->tasks as $task) {
      $lines[] = '    - name: ' . self::scalar($task['name']) . self::note($task['low']);
      $lines[] = '      prompt: ' . self::scalar($task['prompt']);
    }

    $lines[] = '  max-turns: 8';
    $lines[] = '  trials: 3';
    $lines[] = '  threshold: 0.8';
    $lines[] = '  models: ladder';
    $lines[] = '  judge:';
    $lines[] = '    rubric:';

    foreach ($draft->rubric as $criterion) {
      $lines[] = '      - ' . self::scalar($criterion['text']) . self::note($criterion['low']);
    }

    return $lines;
  }

  /**
   * Filters drafted commands to those safe to emit.
   *
   * A drafted pattern that does not compile, collides with a scaffolded guard
   * label, or repeats an already-kept label is dropped so the output stays
   * valid and free of duplicate mapping keys.
   *
   * @param \AlexSkrypnyk\SkillTest\Init\AiDraft $draft
   *   The AI draft.
   *
   * @return array<int, array{label: string, pattern: string, low: bool}>
   *   The emittable command patterns.
   */
  protected static function validCommands(AiDraft $draft): array {
    $out = [];
    $seen = [];

    foreach ($draft->commands as $command) {
      $label = $command['label'];
      if (isset(self::FORBIDDEN_GUARDS[$label])) {
        continue;
      }
      if (isset($seen[$label])) {
        continue;
      }

      if (!Pcre::compiles($command['pattern'])) {
        continue;
      }

      $seen[$label] = TRUE;
      $out[] = $command;
    }

    return $out;
  }

  /**
   * Renders an inline confidence note for a low-confidence entry.
   *
   * @param bool $low
   *   Whether the entry is low confidence.
   *
   * @return string
   *   The trailing comment, or an empty string.
   */
  protected static function note(bool $low): string {
    return $low ? '  # review: low confidence' : '';
  }

  /**
   * Renders a scalar as a safe inline YAML value.
   *
   * @param string $value
   *   The value.
   *
   * @return string
   *   The value, quoted only when YAML requires it.
   */
  protected static function scalar(string $value): string {
    return rtrim(Yaml::dump($value), "\n");
  }

  /**
   * Renders a list as a flow sequence.
   *
   * @param string[] $items
   *   The list items.
   *
   * @return string
   *   The flow sequence, e.g. `[Bash, Skill]` or `[]`.
   */
  protected static function inlineList(array $items): string {
    if ($items === []) {
      return '[]';
    }

    return rtrim(Yaml::dump(array_values($items), 0), "\n");
  }

}
