<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Traits;

use AlexSkrypnyk\SkillTest\Config\Data;

/**
 * Trait ResultsDocumentTrait.
 *
 * Builds results documents for the reporter unit tests, so each reporter test
 * shapes its input the same way instead of re-declaring skill, check, and llm
 * builders, and reads back the first skill's llm facts so grade and gate tests
 * assert them the typed way rather than digging through nested mixed arrays.
 * Keys mirror the committed results schema.
 */
trait ResultsDocumentTrait {

  /**
   * Decodes a JSON string into an array, or an empty array when it is not one.
   *
   * @param string $json
   *   The JSON to decode.
   *
   * @return array<mixed>
   *   The decoded array, or an empty array.
   */
  protected function decodeArray(string $json): array {
    $decoded = json_decode($json, TRUE);

    return is_array($decoded) ? $decoded : [];
  }

  /**
   * The first skill's first task's first model's first trial pass verdict.
   *
   * @param array<mixed> $document
   *   The results document.
   *
   * @return bool
   *   The stored pass verdict.
   */
  protected function documentTrialPass(array $document): bool {
    return (bool) Data::get($this->documentTrials($document)[0] ?? [], 'pass');
  }

  /**
   * The first skill's first task's first model's pass rate.
   *
   * @param array<mixed> $document
   *   The results document.
   *
   * @return float|null
   *   The pass rate, or NULL when absent.
   */
  protected function documentPassRate(array $document): ?float {
    return Data::toFloatOrNull(Data::get($this->documentModels($document)[0] ?? [], 'pass_rate'));
  }

  /**
   * The first skill's minimal-model verdict.
   *
   * @param array<mixed> $document
   *   The results document.
   *
   * @return string|null
   *   The minimal model alias, or NULL.
   */
  protected function documentMinimalModel(array $document): ?string {
    $skills = Data::toArrayList(Data::get($document, 'skills'));

    return Data::toStringOrNull(Data::get($skills[0] ?? [], 'llm', 'verdict', 'minimal_model'));
  }

  /**
   * The first skill's llm block.
   *
   * @param array<mixed> $document
   *   The results document.
   *
   * @return array<mixed>
   *   The llm block.
   */
  protected function documentLlm(array $document): array {
    $skills = Data::toArrayList(Data::get($document, 'skills'));

    return Data::toArray(Data::get($skills[0] ?? [], 'llm'));
  }

  /**
   * The failure total recorded in a document.
   *
   * @param array<mixed> $document
   *   The results document.
   *
   * @return int|null
   *   The failure total, or NULL when absent.
   */
  protected function documentFailures(array $document): ?int {
    return Data::toIntOrNull(Data::get($document, 'totals', 'failures'));
  }

  /**
   * The first skill's first task's model entries.
   *
   * @param array<mixed> $document
   *   The results document.
   *
   * @return array<int, array<mixed>>
   *   The model entries.
   */
  protected function documentModels(array $document): array {
    $skills = Data::toArrayList(Data::get($document, 'skills'));
    $tasks = Data::toArrayList(Data::get($skills[0] ?? [], 'llm', 'tasks'));

    return Data::toArrayList(Data::get($tasks[0] ?? [], 'models'));
  }

  /**
   * The first skill's first task's first model's trials.
   *
   * @param array<mixed> $document
   *   The results document.
   *
   * @return array<int, array<mixed>>
   *   The trial rows.
   */
  protected function documentTrials(array $document): array {
    return Data::toArrayList(Data::get($this->documentModels($document)[0] ?? [], 'trials'));
  }

  /**
   * Builds a results document with overridable totals and run metadata.
   *
   * @param array<int, array<mixed>> $skills
   *   The skill entries.
   * @param array<int, array<mixed>> $hooks
   *   The hook check rows.
   * @param array<int, array<mixed>> $violations
   *   The coverage violations.
   * @param array<string, mixed> $totals
   *   Totals overrides merged over the zeroed defaults.
   * @param array<string, mixed> $run
   *   Run overrides merged over the defaults.
   *
   * @return array<string, mixed>
   *   The results document.
   */
  protected function document(array $skills = [], array $hooks = [], array $violations = [], array $totals = [], array $run = []): array {
    return [
      'version' => '1',
      'tool' => ['name' => 'skilltest', 'version' => 'development'],
      'run' => $run + ['id' => 'st-1', 'started' => '2026-07-09T06:11:19+00:00', 'duration_ms' => 1500, 'command' => 'run', 'environment' => 'host'],
      'skills' => $skills,
      'hooks' => $hooks,
      'coverage' => ['violations' => $violations],
      'totals' => $totals + ['checks' => 0, 'failures' => 0, 'trials' => 0, 'tokens' => ['in' => 0, 'out' => 0], 'cost_usd' => 0.0],
    ];
  }

  /**
   * Builds one skill entry.
   *
   * @param string $name
   *   The skill name.
   * @param array<int, array<mixed>> $structure
   *   The structure check rows.
   * @param array<int, array<mixed>> $security
   *   The security check rows.
   * @param array<int, array<mixed>> $transcript
   *   The transcript check rows.
   * @param array<string, mixed>|null $llm
   *   The llm block, or NULL.
   *
   * @return array<string, mixed>
   *   The skill entry.
   */
  protected function skill(string $name, array $structure = [], array $security = [], array $transcript = [], ?array $llm = NULL): array {
    $skill = [
      'skill' => $name,
      'path' => 'skills/' . $name,
      'deterministic' => ['structure' => $structure, 'security' => $security, 'transcript' => $transcript],
    ];

    if ($llm !== NULL) {
      $skill['llm'] = $llm;
    }

    return $skill;
  }

  /**
   * Builds one check row.
   *
   * @param string $id
   *   The check id.
   * @param bool $pass
   *   The pass verdict.
   * @param string $label
   *   The human label.
   * @param string $evidence
   *   The matched or missing evidence.
   * @param string $message
   *   The failure message.
   *
   * @return array<string, mixed>
   *   The check row.
   */
  protected function check(string $id, bool $pass, string $label = '', string $evidence = '', string $message = ''): array {
    return ['check' => $id, 'pass' => $pass, 'label' => $label, 'evidence' => $evidence, 'message' => $message];
  }

  /**
   * Builds an llm block from tasks and an optional verdict.
   *
   * @param array<int, array<mixed>> $tasks
   *   The task entries.
   * @param array<string, mixed>|null $verdict
   *   The minimal-model verdict, or NULL.
   *
   * @return array<string, mixed>
   *   The llm block.
   */
  protected function llm(array $tasks, ?array $verdict = NULL): array {
    $llm = ['tasks' => $tasks];

    if ($verdict !== NULL) {
      $llm['verdict'] = $verdict;
    }

    return $llm;
  }

  /**
   * Builds one llm task with a single model and its trials.
   *
   * @param string $name
   *   The task name.
   * @param string $model
   *   The model id.
   * @param string $alias
   *   The model alias.
   * @param array<int, array<mixed>> $trials
   *   The trial entries.
   * @param float|null $pass_rate
   *   The model pass rate, or NULL.
   *
   * @return array<string, mixed>
   *   The task entry.
   */
  protected function task(string $name, string $model, string $alias, array $trials, ?float $pass_rate = NULL): array {
    $entry = ['model' => $model, 'alias' => $alias, 'trials' => $trials];

    if ($pass_rate !== NULL) {
      $entry['pass_rate'] = $pass_rate;
    }

    return ['task' => $name, 'models' => [$entry]];
  }

  /**
   * Builds one task carrying several ladder models.
   *
   * @param string $name
   *   The task name.
   * @param array<int, array<mixed>> $models
   *   The model entries, in ladder order.
   *
   * @return array<string, mixed>
   *   The task entry.
   */
  protected function multiModelTask(string $name, array $models): array {
    return ['task' => $name, 'models' => $models];
  }

  /**
   * Builds one model entry with its trials.
   *
   * @param string $alias
   *   The model alias, reused (prefixed) as the model id.
   * @param array<int, array<mixed>> $trials
   *   The trial rows.
   * @param float|null $pass_rate
   *   The model pass rate, or NULL.
   *
   * @return array<string, mixed>
   *   The model entry.
   */
  protected function modelEntry(string $alias, array $trials, ?float $pass_rate = NULL): array {
    $entry = ['model' => 'claude-' . $alias, 'alias' => $alias, 'trials' => $trials];

    if ($pass_rate !== NULL) {
      $entry['pass_rate'] = $pass_rate;
    }

    return $entry;
  }

  /**
   * Builds one trial row.
   *
   * @param int $number
   *   The trial number.
   * @param bool $pass
   *   The pass verdict.
   * @param array<string, mixed> $extra
   *   Extra keys merged in (contract, judge, duration_ms).
   *
   * @return array<string, mixed>
   *   The trial row.
   */
  protected function trial(int $number, bool $pass, array $extra = []): array {
    return ['trial' => $number, 'pass' => $pass] + $extra;
  }

}
