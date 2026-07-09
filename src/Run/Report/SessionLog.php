<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Run\Report;

use AlexSkrypnyk\SkillTest\Config\Data;

/**
 * Builds the ordered NDJSON event stream for a run, for tooling and debugging.
 *
 * The stream opens with `run.started`, then a `check.finished` per
 * deterministic check, a `task.started` and `trial.finished` per llm task and
 * trial, a `hook.executed` per hook case, a `grading.finished` for the
 * coverage gate, and closes with `run.finished`. Every event carries a
 * monotonic `seq` - the authoritative order - and a `ts`. The deterministic
 * suite runs synchronously and does not time each check, so intermediate
 * events carry the run's start timestamp and only the boundary events
 * (`run.started`, `run.finished`) are stamped with the true start and end;
 * `seq` orders them regardless. The document handed here is already redacted,
 * so no event carries a secret value.
 */
final class SessionLog {

  /**
   * The deterministic groups streamed, in per-skill order.
   */
  protected const array GROUPS = ['structure', 'security', 'transcript'];

  /**
   * Builds the ordered event list for the run.
   *
   * @param array<mixed> $document
   *   The redacted results document, as produced by RunReport::toResults().
   *
   * @return array<int, array<string, mixed>>
   *   The events, in emission order, each carrying a `seq` and a `ts`.
   */
  public function events(array $document): array {
    $started = Data::toStringOrNull(Data::get($document, 'run', 'started')) ?? '';
    $seq = 0;
    $events = [];

    $events[] = [
      'seq' => ++$seq,
      'ts' => $started,
      'event' => 'run.started',
      'run' => Data::toStringOrNull(Data::get($document, 'run', 'id')) ?? '',
      'command' => Data::toStringOrNull(Data::get($document, 'run', 'command')) ?? '',
      'environment' => Data::toStringOrNull(Data::get($document, 'run', 'environment')) ?? '',
    ];

    foreach (Data::toArrayList(Data::get($document, 'skills')) as $skill) {
      $name = Data::toStringOrNull(Data::get($skill, 'skill')) ?? '';

      foreach (self::GROUPS as $group) {
        foreach (Data::toArrayList(Data::get($skill, 'deterministic', $group)) as $check) {
          $events[] = $this->checkEvent(++$seq, $started, $name, $group, $check);
        }
      }

      foreach (Data::toArrayList(Data::get($skill, 'llm', 'tasks')) as $task) {
        $task_name = Data::toStringOrNull(Data::get($task, 'task')) ?? '';
        $events[] = ['seq' => ++$seq, 'ts' => $started, 'event' => 'task.started', 'skill' => $name, 'task' => $task_name];

        foreach (Data::toArrayList(Data::get($task, 'models')) as $model) {
          $model_id = Data::toStringOrNull(Data::get($model, 'model')) ?? '';

          foreach (Data::toArrayList(Data::get($model, 'trials')) as $trial) {
            $events[] = [
              'seq' => ++$seq,
              'ts' => $started,
              'event' => 'trial.finished',
              'skill' => $name,
              'task' => $task_name,
              'model' => $model_id,
              'trial' => Data::toIntOrNull(Data::get($trial, 'trial')) ?? 0,
              'pass' => Data::toBoolOrNull(Data::get($trial, 'pass')) ?? FALSE,
            ];
          }
        }
      }
    }

    foreach (Data::toArrayList(Data::get($document, 'hooks')) as $hook) {
      $events[] = $this->hookEvent(++$seq, $started, $hook);
    }

    $events[] = [
      'seq' => ++$seq,
      'ts' => $started,
      'event' => 'grading.finished',
      'violations' => count(Data::toArrayList(Data::get($document, 'coverage', 'violations'))),
    ];

    $events[] = [
      'seq' => ++$seq,
      'ts' => $this->end($started, Data::toIntOrNull(Data::get($document, 'run', 'duration_ms')) ?? 0),
      'event' => 'run.finished',
      'checks' => Data::toIntOrNull(Data::get($document, 'totals', 'checks')) ?? 0,
      'failures' => Data::toIntOrNull(Data::get($document, 'totals', 'failures')) ?? 0,
    ];

    return $events;
  }

  /**
   * Serialises the run's events as an NDJSON string, one event per line.
   *
   * @param array<mixed> $document
   *   The redacted results document.
   *
   * @return string
   *   The NDJSON stream, terminated with a newline.
   */
  public function ndjson(array $document): string {
    $lines = array_map(
      static fn(array $event): string => json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
      $this->events($document),
    );

    return implode("\n", $lines) . "\n";
  }

  /**
   * Builds one `check.finished` event, carrying evidence only when present.
   *
   * @param int $seq
   *   The event's monotonic sequence number.
   * @param string $ts
   *   The event timestamp.
   * @param string $skill
   *   The skill name.
   * @param string $group
   *   The deterministic group name.
   * @param array<mixed> $check
   *   The check row.
   *
   * @return array<string, mixed>
   *   The event.
   */
  protected function checkEvent(int $seq, string $ts, string $skill, string $group, array $check): array {
    $event = [
      'seq' => $seq,
      'ts' => $ts,
      'event' => 'check.finished',
      'skill' => $skill,
      'group' => $group,
      'check' => Data::toStringOrNull(Data::get($check, 'check')) ?? '',
      'pass' => Data::toBoolOrNull(Data::get($check, 'pass')) ?? FALSE,
    ];

    $evidence = Data::toStringOrNull(Data::get($check, 'evidence')) ?? '';

    if ($evidence !== '') {
      $event['evidence'] = $evidence;
    }

    return $event;
  }

  /**
   * Builds one `hook.executed` event, carrying evidence only when present.
   *
   * @param int $seq
   *   The event's monotonic sequence number.
   * @param string $ts
   *   The event timestamp.
   * @param array<mixed> $hook
   *   The hook case row.
   *
   * @return array<string, mixed>
   *   The event.
   */
  protected function hookEvent(int $seq, string $ts, array $hook): array {
    $event = [
      'seq' => $seq,
      'ts' => $ts,
      'event' => 'hook.executed',
      'check' => Data::toStringOrNull(Data::get($hook, 'check')) ?? '',
      'pass' => Data::toBoolOrNull(Data::get($hook, 'pass')) ?? FALSE,
    ];

    $evidence = Data::toStringOrNull(Data::get($hook, 'evidence')) ?? '';

    if ($evidence !== '') {
      $event['evidence'] = $evidence;
    }

    return $event;
  }

  /**
   * Computes the run's end timestamp from its start and duration.
   *
   * @param string $started
   *   The run start timestamp, in a format DateTimeImmutable can parse.
   * @param int $duration_ms
   *   The run duration in milliseconds.
   *
   * @return string
   *   The end timestamp in the same ISO-8601 form, or the start when it cannot
   *   be parsed.
   */
  protected function end(string $started, int $duration_ms): string {
    if ($started === '') {
      return $started;
    }

    try {
      $start = new \DateTimeImmutable($started);
    }
    catch (\Exception) {
      return $started;
    }

    return $start->add(new \DateInterval('PT' . intdiv($duration_ms, 1000) . 'S'))->format(DATE_ATOM);
  }

}
