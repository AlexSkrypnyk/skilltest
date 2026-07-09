<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Live;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Exception\ConfigException;

/**
 * One task's responder configuration, resolved from its `responder` block.
 *
 * A task is interactive precisely when it declares a `responder`; this parses
 * that block into the three facts the conversation loop needs - the persona the
 * responder plays, the follow-up cap, and the resolved model id - and returns
 * NULL for a plain single-prompt task so the two shapes never mix. The model
 * defaults to the judge model (itself the ladder's weakest, never the execution
 * model), so a persona costs a cheap model unless the task pins its own. The
 * same coherence `validate` reports is enforced here so a malformed block is a
 * configuration error rather than a confusing run.
 */
final readonly class ResponderConfig {

  /**
   * Constructs a ResponderConfig.
   *
   * @param string $instructions
   *   The persona and target configuration the responder plays.
   * @param int $maxFollowups
   *   The maximum number of follow-up replies before the run is capped.
   * @param string $model
   *   The resolved responder model id.
   */
  public function __construct(
    public string $instructions,
    public int $maxFollowups,
    public string $model,
  ) {}

  /**
   * Resolves a task's responder configuration, or NULL when it declares none.
   *
   * @param array<mixed> $task
   *   The raw task declaration.
   * @param string $config_file
   *   The declaring `eval.yaml`, for error context.
   * @param string|null $judge_model
   *   The resolved judge model alias, the responder model's default.
   * @param array<string, string> $model_aliases
   *   The repo model aliases, for resolving the model token to an id.
   *
   * @return self|null
   *   The parsed configuration, or NULL for a plain single-prompt task.
   *
   * @throws \AlexSkrypnyk\SkillTest\Exception\ConfigException
   *   When the responder block is malformed or resolves to no model.
   */
  public static function fromTask(array $task, string $config_file, ?string $judge_model, array $model_aliases): ?self {
    if (!array_key_exists('responder', $task)) {
      return NULL;
    }

    $responder = Data::get($task, 'responder');

    if (!is_array($responder)) {
      throw new ConfigException("a task 'responder' must be a mapping.", $config_file, 'llm.tasks.responder');
    }

    $instructions = Data::toStringOrNull(Data::get($responder, 'instructions'));

    if ($instructions === NULL || trim($instructions) === '') {
      throw new ConfigException("a task 'responder' requires non-empty 'instructions'.", $config_file, 'llm.tasks.responder.instructions');
    }

    $max_followups = Data::toIntOrNull(Data::get($responder, 'max-followups'));

    if ($max_followups === NULL || $max_followups < 1) {
      throw new ConfigException("a task 'responder' requires 'max-followups' to be an integer of at least 1.", $config_file, 'llm.tasks.responder.max-followups');
    }

    $token = Data::toStringOrNull(Data::get($responder, 'model')) ?? $judge_model;

    if ($token === NULL || $token === '') {
      throw new ConfigException("a task 'responder' has no model; set responder.model, models.judge, a ladder, or models.default.", $config_file, 'llm.tasks.responder.model');
    }

    return new self($instructions, $max_followups, $model_aliases[$token] ?? $token);
  }

}
