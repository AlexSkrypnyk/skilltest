<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Results;

/**
 * A results file could not be read as a valid, current-schema document.
 *
 * Raised for a missing file, unparseable JSON, a non-object payload, or a
 * different-major schema version.
 */
final class ResultsException extends \RuntimeException {}
