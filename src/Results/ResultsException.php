<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Results;

/**
 * A results file could not be read as a valid, current-schema document.
 *
 * Raised for a missing file, unparseable JSON, a non-object payload, or a
 * different-major schema version. Every command that consumes a `results.json`
 * catches this and reports it as a configuration error (exit 2), so a broken
 * baseline is never mistaken for a passing gate.
 */
final class ResultsException extends \RuntimeException {}
