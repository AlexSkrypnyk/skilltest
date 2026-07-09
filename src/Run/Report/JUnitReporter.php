<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Run\Report;

use AlexSkrypnyk\SkillTest\Config\Data;

/**
 * Renders a results document as JUnit XML so any CI renders skilltest natively.
 *
 * One `<testsuite>` per skill carries a `<testcase>` for every deterministic
 * check (structure, security, transcript) and every llm trial, with the group
 * in the test case's `classname`; repo-level hooks and coverage violations
 * become their own suites. A failed check adds a `<failure>` whose message and
 * body carry the check id, label, and evidence, so a CI failure view is
 * debuggable without the original run. The document drives every number - the
 * reporter computes no verdicts of its own.
 */
final class JUnitReporter {

  /**
   * The deterministic groups rendered, in the order they appear per skill.
   */
  protected const array GROUPS = ['structure', 'security', 'transcript'];

  /**
   * Renders the results document as a JUnit XML string.
   *
   * @param array<mixed> $document
   *   The results document, as produced by RunReport::toResults().
   *
   * @return string
   *   The JUnit XML, with an XML declaration and a trailing newline.
   */
  public function render(array $document): string {
    $dom = new \DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = TRUE;

    $suites = $dom->createElement('testsuites');
    $suites->setAttribute('name', $this->safe(Data::toStringOrNull(Data::get($document, 'tool', 'name')) ?? 'skilltest'));

    $tests = 0;
    $failures = 0;

    foreach (Data::toArrayList(Data::get($document, 'skills')) as $skill) {
      $name = Data::toStringOrNull(Data::get($skill, 'skill')) ?? '';
      [$suite_tests, $suite_failures] = $this->emitSuite($dom, $suites, $name, $this->skillCases($dom, $skill, $name));
      $tests += $suite_tests;
      $failures += $suite_failures;
    }

    [$hook_tests, $hook_failures] = $this->emitSuite($dom, $suites, 'hooks', $this->checkCases($dom, Data::toArrayList(Data::get($document, 'hooks')), 'hooks'));
    $tests += $hook_tests;
    $failures += $hook_failures;

    [$coverage_tests, $coverage_failures] = $this->emitSuite($dom, $suites, 'coverage', $this->checkCases($dom, Data::toArrayList(Data::get($document, 'coverage', 'violations')), 'coverage'));
    $tests += $coverage_tests;
    $failures += $coverage_failures;

    $suites->setAttribute('tests', (string) $tests);
    $suites->setAttribute('failures', (string) $failures);
    $suites->setAttribute('time', $this->seconds(Data::toIntOrNull(Data::get($document, 'run', 'duration_ms')) ?? 0));

    $dom->appendChild($suites);

    return (string) $dom->saveXML();
  }

  /**
   * Builds every test case for one skill: its deterministic checks and trials.
   *
   * @param \DOMDocument $dom
   *   The document the elements are created in.
   * @param array<mixed> $skill
   *   One skill entry from the results document.
   * @param string $name
   *   The skill name, used as the class-name prefix.
   *
   * @return array<int, array{0: \DOMElement, 1: bool}>
   *   The test case elements paired with whether each failed.
   */
  protected function skillCases(\DOMDocument $dom, array $skill, string $name): array {
    $cases = [];

    foreach (self::GROUPS as $group) {
      foreach ($this->checkCases($dom, Data::toArrayList(Data::get($skill, 'deterministic', $group)), $name . '.' . $group) as $case) {
        $cases[] = $case;
      }
    }

    foreach ($this->trialCases($dom, $skill, $name) as $case) {
      $cases[] = $case;
    }

    return $cases;
  }

  /**
   * Builds a test case for each check row under one class name.
   *
   * @param \DOMDocument $dom
   *   The document the elements are created in.
   * @param array<int, array<mixed>> $checks
   *   The check rows: each carries a check id, a pass verdict, and detail.
   * @param string $classname
   *   The `classname` attribute for every case.
   *
   * @return array<int, array{0: \DOMElement, 1: bool}>
   *   The test case elements paired with whether each failed.
   */
  protected function checkCases(\DOMDocument $dom, array $checks, string $classname): array {
    $cases = [];

    foreach ($checks as $check) {
      $id = Data::toStringOrNull(Data::get($check, 'check')) ?? '';
      $pass = Data::toBoolOrNull(Data::get($check, 'pass')) ?? FALSE;
      $label = Data::toStringOrNull(Data::get($check, 'label')) ?? '';
      $evidence = Data::toStringOrNull(Data::get($check, 'evidence')) ?? '';
      $message = Data::toStringOrNull(Data::get($check, 'message')) ?? '';

      $cases[] = $this->case($dom, $id, $classname, $pass, $label, $evidence, $message, NULL);
    }

    return $cases;
  }

  /**
   * Builds a test case for every llm trial the skill recorded.
   *
   * @param \DOMDocument $dom
   *   The document the elements are created in.
   * @param array<mixed> $skill
   *   One skill entry from the results document.
   * @param string $name
   *   The skill name, used as the class-name prefix.
   *
   * @return array<int, array{0: \DOMElement, 1: bool}>
   *   The trial test case elements paired with whether each failed.
   */
  protected function trialCases(\DOMDocument $dom, array $skill, string $name): array {
    $cases = [];

    foreach (Data::toArrayList(Data::get($skill, 'llm', 'tasks')) as $task) {
      $task_name = Data::toStringOrNull(Data::get($task, 'task')) ?? '';

      foreach (Data::toArrayList(Data::get($task, 'models')) as $model) {
        $model_id = Data::toStringOrNull(Data::get($model, 'model')) ?? '';
        $alias = Data::toStringOrNull(Data::get($model, 'alias')) ?? $model_id;

        foreach (Data::toArrayList(Data::get($model, 'trials')) as $trial) {
          $number = Data::toIntOrNull(Data::get($trial, 'trial')) ?? 0;
          $pass = Data::toBoolOrNull(Data::get($trial, 'pass')) ?? FALSE;
          $seconds = $this->seconds(Data::toIntOrNull(Data::get($trial, 'duration_ms')) ?? 0);
          $id = sprintf('%s.%s.trial-%d', $task_name, $alias, $number);

          $cases[] = $this->case($dom, $id, $name . '.llm', $pass, $task_name, $model_id, TrialSummary::line($trial), $seconds);
        }
      }
    }

    return $cases;
  }

  /**
   * Builds one `<testcase>`, adding a `<failure>` when the check did not pass.
   *
   * @param \DOMDocument $dom
   *   The document the elements are created in.
   * @param string $id
   *   The test case name (the check id).
   * @param string $classname
   *   The `classname` attribute.
   * @param bool $pass
   *   Whether the check passed.
   * @param string $label
   *   The human label carried into the failure body.
   * @param string $evidence
   *   The matched or missing evidence carried into the failure body.
   * @param string $message
   *   The failure message and fix direction.
   * @param string|null $seconds
   *   The case duration in seconds, or NULL to omit the `time` attribute.
   *
   * @return array{0: \DOMElement, 1: bool}
   *   The test case element and whether it failed.
   */
  protected function case(\DOMDocument $dom, string $id, string $classname, bool $pass, string $label, string $evidence, string $message, ?string $seconds): array {
    $case = $dom->createElement('testcase');
    $case->setAttribute('name', $this->safe($id));
    $case->setAttribute('classname', $this->safe($classname));

    if ($seconds !== NULL) {
      $case->setAttribute('time', $seconds);
    }

    if ($pass) {
      return [$case, FALSE];
    }

    $failure = $dom->createElement('failure');
    $failure->setAttribute('message', $this->safe($message !== '' ? $message : ($label !== '' ? $label : $id)));
    $failure->appendChild($dom->createTextNode($this->safe($this->body($id, $label, $evidence, $message))));
    $case->appendChild($failure);

    return [$case, TRUE];
  }

  /**
   * Builds the multi-line failure body: check id, label, evidence, and message.
   *
   * @param string $id
   *   The check id.
   * @param string $label
   *   The human label, when present.
   * @param string $evidence
   *   The matched or missing evidence, when present.
   * @param string $message
   *   The failure message, when present.
   *
   * @return string
   *   The joined failure body.
   */
  protected function body(string $id, string $label, string $evidence, string $message): string {
    $lines = ['check: ' . $id];

    if ($label !== '') {
      $lines[] = 'label: ' . $label;
    }

    if ($evidence !== '') {
      $lines[] = 'evidence: ' . $evidence;
    }

    if ($message !== '') {
      $lines[] = 'message: ' . $message;
    }

    return implode("\n", $lines);
  }

  /**
   * Appends a non-empty `<testsuite>`, returning its test and failure counts.
   *
   * @param \DOMDocument $dom
   *   The document the suite is created in.
   * @param \DOMElement $suites
   *   The root `<testsuites>` element to append to.
   * @param string $name
   *   The suite name.
   * @param array<int, array{0: \DOMElement, 1: bool}> $cases
   *   The test case elements paired with whether each failed.
   *
   * @return array{0: int, 1: int}
   *   The suite's test count and failure count.
   */
  protected function emitSuite(\DOMDocument $dom, \DOMElement $suites, string $name, array $cases): array {
    if ($cases === []) {
      return [0, 0];
    }

    $suite = $dom->createElement('testsuite');
    $suite->setAttribute('name', $this->safe($name));
    $suite->setAttribute('tests', (string) count($cases));

    $failures = 0;

    foreach ($cases as [$case, $failed]) {
      $suite->appendChild($case);
      $failures += $failed ? 1 : 0;
    }

    $suite->setAttribute('failures', (string) $failures);
    $suite->setAttribute('errors', '0');
    $suites->appendChild($suite);

    return [count($cases), $failures];
  }

  /**
   * Formats a millisecond duration as a fixed-precision second count.
   *
   * @param int $milliseconds
   *   The duration in milliseconds.
   *
   * @return string
   *   The duration in seconds, to three decimal places.
   */
  protected function seconds(int $milliseconds): string {
    return number_format($milliseconds / 1000, 3, '.', '');
  }

  /**
   * Strips characters an XML 1.0 document may not contain.
   *
   * Evidence lifted from a transcript can carry a control byte or an invalid
   * UTF-8 sequence that would make the document malformed; the encoding is
   * repaired first so a single bad byte cannot make the Unicode-aware filter
   * drop the whole message, then the XML-illegal characters are removed.
   *
   * @param string $text
   *   The text to sanitise.
   *
   * @return string
   *   The well-formed text.
   */
  protected function safe(string $text): string {
    $utf8 = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $utf8);

    return is_string($clean) ? $clean : '';
  }

}
