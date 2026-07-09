<?php

declare(strict_types=1);

namespace AlexSkrypnyk\SkillTest\Tests\Unit\Init;

use AlexSkrypnyk\SkillTest\Init\SkillManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class SkillManifestTest.
 *
 * Unit test for parsing a SKILL.md into name, description, tools, and body.
 */
#[CoversClass(SkillManifest::class)]
final class SkillManifestTest extends TestCase {

  #[DataProvider('dataProviderFromString')]
  public function testFromString(string $contents, ?string $name, ?string $description, array $tools, string $body): void {
    $manifest = SkillManifest::fromString($contents);

    $this->assertSame($name, $manifest->name);
    $this->assertSame($description, $manifest->description);
    $this->assertSame($tools, $manifest->allowedTools);
    $this->assertSame($body, $manifest->body);
  }

  public static function dataProviderFromString(): \Iterator {
    yield 'full frontmatter with scoped and duplicate tools' => [
      "---\nname: foo\ndescription: A skill.\nallowed-tools: Bash(git:*), Skill, Bash\n---\nbody line",
      'foo',
      'A skill.',
      ['Bash', 'Skill'],
      'body line',
    ];

    yield 'allowed-tools as a yaml list' => [
      "---\nname: bar\nallowed-tools: [Read, Grep]\n---\n",
      'bar',
      NULL,
      ['Read', 'Grep'],
      '',
    ];

    yield 'no frontmatter keeps whole document as body' => [
      "just a body\nsecond line",
      NULL,
      NULL,
      [],
      "just a body\nsecond line",
    ];

    yield 'unterminated frontmatter is treated as body' => [
      "---\nname: x\nno closing delimiter",
      NULL,
      NULL,
      [],
      "---\nname: x\nno closing delimiter",
    ];

    yield 'empty frontmatter block' => [
      "---\n---\nbody",
      NULL,
      NULL,
      [],
      'body',
    ];

    yield 'crlf line endings are normalised' => [
      "---\r\nname: win\r\n---\r\nbody",
      'win',
      NULL,
      [],
      'body',
    ];

    yield 'malformed frontmatter yaml degrades to no data' => [
      "---\nname: [unclosed\n---\nbody",
      NULL,
      NULL,
      [],
      'body',
    ];

    yield 'absent allowed-tools yields no tools' => [
      "---\nname: n\n---\n",
      'n',
      NULL,
      [],
      '',
    ];

    yield 'blank allowed-tools string yields no tools' => [
      "---\nallowed-tools: '   '\n---\n",
      NULL,
      NULL,
      [],
      '',
    ];

    yield 'allowed-tools of only separators yields no tools' => [
      "---\nallowed-tools: ' , '\n---\n",
      NULL,
      NULL,
      [],
      '',
    ];
  }

}
