<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Judge;

/**
 * A judge failure: an unparseable verdict or a broken judge invocation.
 *
 * Thrown when the judge cannot produce a usable verdict - the model returned
 * something that is not the required JSON, or the judge process itself failed.
 * It is a distinct result kind from a criterion that legitimately fails: the
 * caller renders it as a judge failure that fails the trial, never a silent
 * pass and never a skill regression.
 */
final class JudgeException extends \RuntimeException {}
