<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Results;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Config\EffectiveConfig;
use AlexSkrypnyk\SkillTest\Config\SchemaVersion;

/**
 * A read model over a decoded `results.json`, shared by grade and gate.
 *
 * The one place the saved document's arithmetic lives, so re-grading and the
 * regression gate read a run the same way instead of re-walking the nested
 * arrays each time. It answers the questions a comparison asks: the schema
 * version (so a foreign-major document is rejected, never misread), the
 * aggregate pass rate over every deterministic check and every llm trial, each
 * task's per-model verdict recomputed from the raw trials against the skill's
 * threshold, and each skill's minimal-model verdict and ladder order. It is a
 * pure reader - it never mutates the document it wraps.
 */
final readonly class ResultsDocument {

  /**
   * The deterministic check groups that contribute pass/fail units.
   */
  protected const array DETERMINISTIC_GROUPS = ['structure', 'security', 'transcript'];

  /**
   * Constructs a ResultsDocument.
   *
   * @param array<mixed> $data
   *   The decoded results document.
   */
  public function __construct(
    public array $data,
  ) {}

  /**
   * Parses a results document from a JSON string.
   *
   * @param string $json
   *   The document JSON.
   *
   * @return self
   *   The parsed document.
   *
   * @throws \AlexSkrypnyk\SkillTest\Results\ResultsException
   *   When the payload is not a decodable JSON object.
   */
  public static function fromString(string $json): self {
    $decoded = json_decode($json, TRUE);

    // A results document is a keyed object; a JSON list decodes to an array too
    // but would grade as an empty document, so it is rejected here rather than
    // silently skewing the pass rate.
    if (!is_array($decoded) || array_is_list($decoded)) {
      throw new ResultsException('the results file is not a JSON object.');
    }

    return new self($decoded);
  }

  /**
   * Parses a results document from a file.
   *
   * @param string $path
   *   The document path.
   *
   * @return self
   *   The parsed document.
   *
   * @throws \AlexSkrypnyk\SkillTest\Results\ResultsException
   *   When the file is missing, unreadable, or not a JSON object.
   */
  public static function fromFile(string $path): self {
    if (!is_file($path)) {
      throw new ResultsException(sprintf('results file not found: %s.', $path));
    }

    $contents = file_get_contents($path);

    // @codeCoverageIgnoreStart
    if ($contents === FALSE) {
      throw new ResultsException(sprintf('results file could not be read: %s.', $path));
    }
    // @codeCoverageIgnoreEnd
    return self::fromString($contents);
  }

  /**
   * The document's declared schema version, or the empty string when absent.
   *
   * @return string
   *   The version string.
   */
  public function version(): string {
    return Data::toStringOrNull(Data::get($this->data, 'version')) ?? '';
  }

  /**
   * Whether the document's schema major is the one this tool reads.
   *
   * @return bool
   *   TRUE when the major matches (a missing version means the current one); a
   *   malformed version is not the current major.
   */
  public function isCurrentMajor(): bool {
    try {
      return SchemaVersion::parse($this->version())->isCurrentMajor();
    }
    catch (\InvalidArgumentException) {
      return FALSE;
    }
  }

  /**
   * The aggregate pass rate over every graded unit in the document.
   *
   * A unit is one check (structure, security, transcript, hook, or
   * coverage violation) or one llm trial. The rate is recomputed from the
   * document itself rather than read from `totals`, so it is a true pass rate
   * across checks and trials. An empty document has nothing failing, so it
   * rates a perfect 1.0.
   *
   * @return float
   *   The pass rate in the range 0..1.
   */
  public function passRate(): float {
    $passed = 0;
    $total = 0;

    foreach ($this->skillEntries() as $skill_entry) {
      foreach (self::DETERMINISTIC_GROUPS as $group) {
        foreach (Data::toArrayList(Data::get($skill_entry, 'deterministic', $group)) as $row) {
          $total++;
          $passed += (bool) Data::get($row, 'pass') ? 1 : 0;
        }
      }

      foreach ($this->trialsOf($skill_entry) as $trial) {
        $total++;
        $passed += (bool) Data::get($trial, 'pass') ? 1 : 0;
      }
    }

    foreach (Data::toArrayList(Data::get($this->data, 'hooks')) as $row) {
      $total++;
      $passed += (bool) Data::get($row, 'pass') ? 1 : 0;
    }

    foreach (Data::toArrayList(Data::get($this->data, 'coverage', 'violations')) as $row) {
      $total++;
      $passed += (bool) Data::get($row, 'pass') ? 1 : 0;
    }

    return $total === 0 ? 1.0 : $passed / $total;
  }

  /**
   * Every task in the document, keyed by its skill-and-name identity.
   *
   * @return array<string, \AlexSkrypnyk\SkillTest\Results\TaskView>
   *   The task views keyed by {@see TaskView::key}.
   */
  public function tasks(): array {
    $views = [];

    foreach ($this->skillEntries() as $skill_entry) {
      $skill = $this->skillName($skill_entry);
      $threshold = Data::toFloatOrNull(Data::get($skill_entry, 'llm', 'verdict', 'threshold')) ?? EffectiveConfig::DEFAULT_THRESHOLD;

      foreach (Data::toArrayList(Data::get($skill_entry, 'llm', 'tasks')) as $task_entry) {
        $task = Data::toStringOrNull(Data::get($task_entry, 'task')) ?? '';
        $model_passed = [];

        foreach (Data::toArrayList(Data::get($task_entry, 'models')) as $model_entry) {
          $model_passed[self::modelAlias($model_entry)] = self::modelPasses($model_entry, $threshold);
        }

        $views[TaskView::key($skill, $task)] = new TaskView($skill, $task, $model_passed);
      }
    }

    return $views;
  }

  /**
   * Each skill's minimal-model verdict, keyed by skill name.
   *
   * Only skills with an llm verdict are included; the value is the minimal
   * model alias, or NULL when no model supported the skill.
   *
   * @return array<string, string|null>
   *   The minimal model alias (or NULL) keyed by skill name.
   */
  public function skillMinimalModels(): array {
    $out = [];

    foreach ($this->skillEntries() as $skill_entry) {
      $verdict = Data::get($skill_entry, 'llm', 'verdict');

      if (is_array($verdict) && array_key_exists('minimal_model', $verdict)) {
        $out[$this->skillName($skill_entry)] = Data::toStringOrNull($verdict['minimal_model']);
      }
    }

    return $out;
  }

  /**
   * Each skill's model ladder in order, keyed by skill name.
   *
   * The ladder is the model order the first task ran, weakest first - the order
   * a minimal-model climb is measured along.
   *
   * @return array<string, list<string>>
   *   The ordered model aliases keyed by skill name.
   */
  public function skillLadders(): array {
    $out = [];

    foreach ($this->skillEntries() as $skill_entry) {
      $tasks = Data::toArrayList(Data::get($skill_entry, 'llm', 'tasks'));

      if ($tasks === []) {
        continue;
      }

      $aliases = [];

      foreach (Data::toArrayList(Data::get($tasks[0], 'models')) as $model_entry) {
        $aliases[] = self::modelAlias($model_entry);
      }

      $out[$this->skillName($skill_entry)] = $aliases;
    }

    return $out;
  }

  /**
   * The skill entries in the document.
   *
   * @return array<int, array<mixed>>
   *   The skill entries.
   */
  public function skillEntries(): array {
    return Data::toArrayList(Data::get($this->data, 'skills'));
  }

  /**
   * Whether a model met the threshold, recomputed from its raw trials.
   *
   * @param array<mixed> $model_entry
   *   The model entry.
   * @param float $threshold
   *   The pass-rate threshold.
   *
   * @return bool
   *   TRUE when the fraction of passing trials reaches the threshold.
   */
  public static function modelPasses(array $model_entry, float $threshold): bool {
    $trials = Data::toArrayList(Data::get($model_entry, 'trials'));

    if ($trials === []) {
      return 0.0 >= $threshold;
    }

    $passed = count(array_filter($trials, static fn(array $trial): bool => (bool) Data::get($trial, 'pass')));

    return $passed / count($trials) >= $threshold;
  }

  /**
   * Every trial under a skill entry, flattened across its tasks and models.
   *
   * @param array<mixed> $skill_entry
   *   The skill entry.
   *
   * @return array<int, array<mixed>>
   *   The trial entries.
   */
  protected function trialsOf(array $skill_entry): array {
    $trials = [];

    foreach (Data::toArrayList(Data::get($skill_entry, 'llm', 'tasks')) as $task_entry) {
      foreach (Data::toArrayList(Data::get($task_entry, 'models')) as $model_entry) {
        foreach (Data::toArrayList(Data::get($model_entry, 'trials')) as $trial) {
          $trials[] = $trial;
        }
      }
    }

    return $trials;
  }

  /**
   * A skill entry's name.
   *
   * @param array<mixed> $skill_entry
   *   The skill entry.
   *
   * @return string
   *   The skill name, or the empty string when absent.
   */
  protected function skillName(array $skill_entry): string {
    return Data::toStringOrNull(Data::get($skill_entry, 'skill')) ?? '';
  }

  /**
   * A model entry's alias, falling back to its full id.
   *
   * @param array<mixed> $model_entry
   *   The model entry.
   *
   * @return string
   *   The model alias, or the model id when no alias is recorded.
   */
  public static function modelAlias(array $model_entry): string {
    return Data::toStringOrNull(Data::get($model_entry, 'alias'))
      ?? Data::toStringOrNull(Data::get($model_entry, 'model'))
      ?? '';
  }

}
