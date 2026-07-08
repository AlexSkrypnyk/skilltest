<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Security;

use AlexSkrypnyk\SkillTest\Config\Data;
use AlexSkrypnyk\SkillTest\Config\LoadedConfig;
use AlexSkrypnyk\SkillTest\Config\LoadedSkill;

/**
 * The deterministic `security` group: a static supply-chain scan of skill files.
 *
 * Every regular file a skill ships - not just `SKILL.md`, but bundled scripts,
 * references, and fixtures - is scanned line by line for danger patterns before
 * any model reads it. The `baseline` pack is unconditional: it is not gated on
 * configuration, so nothing a skill declares can disable it or downgrade a
 * finding to a warning. The skill's own `eval.yaml` is the one file excluded -
 * it is the skilltest sidecar config, not shipped content, and it is where
 * forbidden tokens are declared, so scanning it would self-trigger.
 */
final readonly class SecurityScanner {

  /**
   * The check id for a skill-declared forbidden token.
   */
  public const string FORBIDDEN_TOKEN_CHECK = 'security.forbidden-tokens';

  /**
   * The always-on baseline pack as [check id, delimited regex, description].
   *
   * @var list<array{0: string, 1: string, 2: string}>
   */
  public const array BASELINE_PATTERNS = [
    [
      'security.curl-pipe-shell',
      '/\bcurl\b[^\n|]*\|\s*(?:bash|sh|zsh)\b/i',
      'pipes a remote download into a shell (curl | bash)',
    ],
    [
      'security.credential-read',
      '#\b(?:cat|less|head|tail|printenv)\b[^\n]*(?:\.env\b|\.aws/credentials|\.ssh/id_rsa|\.npmrc|\.netrc)#i',
      'reads a credential or secret file',
    ],
    [
      'security.credential-encode',
      '/\bbase64\b[^\n]*(?:\.env|id_rsa|credentials)/i',
      'base64-encodes an env or secret file',
    ],
    [
      'security.pre-model-exec-net',
      '/!`[^`]*\bcurl\b[^`]*`/i',
      'pre-model dynamic command (!`...`) runs curl',
    ],
    [
      'security.pre-model-exec-secrets',
      '/!`[^`]*(?:\.env|printenv|\bsecrets?\b|id_rsa|credentials)[^`]*`/i',
      'pre-model dynamic command (!`...`) reads env or secrets',
    ],
    [
      'security.destructive-delete',
      '#\brm\s+-[rf]{1,2}\s+/(?:\s|$)#i',
      'destructive recursive delete at the filesystem root',
    ],
  ];

  /**
   * The repository root, used to render findings as root-relative paths.
   */
  protected string $root;

  /**
   * Constructs a SecurityScanner.
   *
   * @param string $root
   *   The repository root.
   */
  public function __construct(string $root) {
    $this->root = rtrim($root, '/');
  }

  /**
   * Scans every loaded skill and returns the findings across all of them.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedConfig $loaded_config
   *   The loaded configuration.
   *
   * @return \AlexSkrypnyk\SkillTest\Security\SecurityFinding[]
   *   The findings, in skill-then-file-then-line order.
   */
  public function scan(LoadedConfig $loaded_config): array {
    $findings = [];

    foreach ($loaded_config->skills as $skill) {
      foreach ($this->scanSkill($skill) as $finding) {
        $findings[] = $finding;
      }
    }

    return $findings;
  }

  /**
   * Scans one skill's shipped files for baseline patterns and forbidden tokens.
   *
   * @param \AlexSkrypnyk\SkillTest\Config\LoadedSkill $loaded_skill
   *   The loaded skill.
   *
   * @return \AlexSkrypnyk\SkillTest\Security\SecurityFinding[]
   *   The findings for this skill.
   */
  protected function scanSkill(LoadedSkill $loaded_skill): array {
    $dir = dirname($loaded_skill->file);
    $tokens = Data::toStringList(Data::get($loaded_skill->effective->security, 'forbidden-tokens'));
    $findings = [];

    foreach ($this->files($dir) as $absolute) {
      if ($absolute === $loaded_skill->file) {
        continue;
      }

      foreach ($this->scanFile($absolute, $this->relativePath($absolute), $tokens) as $finding) {
        $findings[] = $finding;
      }
    }

    return $findings;
  }

  /**
   * Scans one file line by line for every baseline pattern and forbidden token.
   *
   * @param string $absolute
   *   The absolute file path to read.
   * @param string $relative
   *   The file path relative to the repository root, for the finding.
   * @param string[] $tokens
   *   The skill-declared forbidden tokens.
   *
   * @return \AlexSkrypnyk\SkillTest\Security\SecurityFinding[]
   *   The findings for this file.
   */
  protected function scanFile(string $absolute, string $relative, array $tokens): array {
    $contents = @file_get_contents($absolute);

    // @codeCoverageIgnoreStart
    if ($contents === FALSE) {
      return [];
    }
    // @codeCoverageIgnoreEnd
    $findings = [];

    foreach (explode("\n", $contents) as $index => $line) {
      $number = $index + 1;
      $evidence = trim($line);

      foreach (self::BASELINE_PATTERNS as [$check, $pattern, $description]) {
        if (preg_match($pattern, $line) === 1) {
          $findings[] = new SecurityFinding($check, $relative, $number, $evidence, $description);
        }
      }

      foreach ($tokens as $token) {
        if ($token !== '' && str_contains($line, $token)) {
          $findings[] = new SecurityFinding(self::FORBIDDEN_TOKEN_CHECK, $relative, $number, $evidence, sprintf("forbidden token '%s' appears in a shipped file", $token));
        }
      }
    }

    return $findings;
  }

  /**
   * Returns every regular file under a directory, recursively, sorted.
   *
   * @param string $dir
   *   The absolute directory to walk.
   *
   * @return string[]
   *   The absolute file paths, sorted for deterministic reporting.
   */
  protected function files(string $dir): array {
    $found = $this->collect($dir);
    sort($found);

    return $found;
  }

  /**
   * Collects regular files under a directory, recursively and unsorted.
   *
   * @param string $dir
   *   The absolute directory to walk.
   *
   * @return string[]
   *   The absolute file paths, in traversal order.
   */
  protected function collect(string $dir): array {
    $entries = @scandir($dir);

    // @codeCoverageIgnoreStart
    if ($entries === FALSE) {
      return [];
    }
    // @codeCoverageIgnoreEnd
    $files = [];

    foreach ($entries as $entry) {
      if ($entry === '.') {
        continue;
      }
      if ($entry === '..') {
        continue;
      }

      $path = $dir . '/' . $entry;

      if (is_dir($path)) {
        foreach ($this->collect($path) as $nested) {
          $files[] = $nested;
        }

        continue;
      }

      $files[] = $path;
    }

    return $files;
  }

  /**
   * Renders an absolute path relative to the repository root.
   *
   * @param string $absolute
   *   The absolute path.
   *
   * @return string
   *   The root-relative path.
   */
  protected function relativePath(string $absolute): string {
    $prefix = $this->root . '/';

    if (str_starts_with($absolute, $prefix)) {
      return substr($absolute, strlen($prefix));
    }

    // @codeCoverageIgnoreStart
    return $absolute;
    // @codeCoverageIgnoreEnd
  }

}
