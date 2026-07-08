# The deterministic suite

The deterministic suite is the CI gate: every check in it runs with no model, no network, no tokens, and no flakiness, so it can block every push. It has four groups - `structure`, `security`, `hooks`, `transcript` - and its power feature is the pre-baked check catalog: the common 95% of skill testing expressed as named checks and packs, enabled by default or by one line of YAML, with hand-written patterns reserved for the genuinely skill-specific residue.

## Check anatomy

Every check has a stable id (`<group>.<name>`), parameters, a pass/fail result, and a failure message that includes the evidence (the offending line, pattern, file, or exit code) and the fix direction. Check ids are an API: reports, `--check` selection, and suppressions reference them.

Suppression is explicit and visible: a skill's `eval.yaml` may disable a named check with a required `reason:`, and disabled checks are listed in every report as suppressed, never silently absent.

## Group: `structure`

The skill's files are well-formed, internally consistent, and honest about what they reference. Runs against `SKILL.md` and the skill directory.

Pre-baked checks (default-on unless noted):

| Check id | Asserts |
|---|---|
| `structure.frontmatter` | Frontmatter parses; `name:` and `description:` present and non-empty |
| `structure.name-matches-dir` | Frontmatter `name:` equals the skill directory name |
| `structure.description-length` | Description within min/max bounds (params: `min`, `max`) |
| `structure.allowed-tools-declared` | When the runtime supports tool restriction, the declaration exists and parses |
| `structure.no-unrestricted-bash` | `allowed-tools` never grants `Bash(*)` or an equivalent wildcard |
| `structure.no-pre-model-exec` | No `` !`...` `` dynamic-context execution in the skill body |
| `structure.files-exist` | Every relative file path referenced in the skill body exists in the skill directory |
| `structure.command-refs-resolve` | Every `<binary> <subcommand>` reference resolves against the configured binary's real command list (enabled by `commands.resolve` in repo config) |
| `structure.token-budget` (P2) | `SKILL.md` token count within the configured budget (params: `limit`, `warn-at`) |
| `structure.contract-coherent` | The `eval.yaml` itself is coherent: required/forbidden sets disjoint, rubric non-empty, patterns compile, packs and fixtures resolve |
| `structure.advisory` (P2, warn-only) | Quality advisories: over-long procedural bodies, over-specific trigger phrasing, reference-module count outside the healthy range |

## Group: `security`

Every file the skill ships (not just `SKILL.md` - bundled scripts, references, fixtures) is scanned for danger patterns. This is a static supply-chain scan, not a runtime boundary; it exists to catch malicious or careless skill content before a model ever reads it.

The `baseline` pack is always on:

| Check id | Flags |
|---|---|
| `security.curl-pipe-shell` | Piping a remote download into a shell (`curl \| bash` and variants) |
| `security.credential-read` | Reading credential or secret files (`.env`, `.aws/credentials`, `.ssh/id_rsa`, `.npmrc`, `.netrc`) |
| `security.credential-encode` | Encoding or bundling secrets (`base64` over env or key files) |
| `security.pre-model-exec-net` | Pre-model dynamic commands that reach the network or read secrets |
| `security.destructive-delete` | Recursive delete at or near the filesystem root |
| `security.forbidden-tokens` | Skill-specific tokens declared in `eval.yaml` (`security.forbidden-tokens`) appear nowhere in shipped files |

Findings are always errors, never warnings; a skill that trips the security group does not ship.

## Group: `hooks`

Proves the repository's enforcement hooks actually enforce. For each hook declared in `skilltest.yml`, skilltest executes the real hook script with each crafted case's tool input on stdin (the runtime's PreToolUse protocol) and asserts the decision: `expect: block` requires the blocking exit code, `expect: allow` requires success. Cases live in config as data; adding an enforcement rule means adding a script and a handful of cases, no test code.

This group is what makes "the harness is the enforcement boundary" testable: the deterministic suite fails when a hook stops blocking what it must block, before any model is involved.

## Group: `transcript`

Asserts the skill's full contract against its recorded canonical transcript (`deterministic.transcript`). The transcript is a JSONL tool-call record produced by `skilltest record` (a real headless run); it is a fixture, reviewed and committed like any other, so contract regressions surface as deterministic CI failures and skill changes surface as reviewable fixture diffs.

Contract semantics (shared verbatim with the llm suite's `live` group):

- `contract.tools.required` - each tool appears in the transcript at least once; `forbidden` - never.
- `contract.commands.required` - each pattern matches at least one executed command, after alias normalisation (`aliases:` in repo config maps invocation forms to a canonical one, so `php bin/harness x`, `./bin/harness x`, and `harness x` all count as `harness x`); `forbidden` - no executed command matches.
- `contract.skills.required` / `forbidden` - Skill-tool invocations by name.
- Repo-level `guards:` are appended to every skill's forbidden commands, so no `eval.yaml` can forget the broker-bypass rule.

Pre-baked pattern packs usable in any pattern position (`pack:<name>`):

| Pack | Matches |
|---|---|
| `git-mutations` | `git commit`, `push`, `checkout`, `switch`, `merge`, `rebase`, `reset --hard`, `tag` |
| `gh-mutations` | `gh pr create/merge/close/edit`, `gh issue create/edit/close`, `gh project` item and field mutations, `gh api` with mutating methods |
| `gh-readonly` | The complement used in `required` positions: `gh pr view/list/checks`, `gh issue view/list` |
| `package-installs` | `npm i -g`, `pip install`, `composer global require`, `brew install` |
| `network-fetch` | `curl`/`wget` fetching remote content |
| `system-temp` | Writes to `/tmp` and `$TMPDIR` outside a sanctioned project temp dir |

Packs are versioned with the tool; a release note calls out pattern additions because they can newly fail existing suites.

## Custom checks

The escape hatch, adopted from skillgrade's grader contract: a check may be a script.

```yaml
llm:
  checks:
    - name: board-column-advanced
      run: php tests/checks/board-column.php
```

The script receives the transcript path as `$1` and the skill directory as `$2`, exits `0`/`1`, and may emit a JSON verdict on stdout (`{"pass": bool, "message": "...", "evidence": "..."}`) that the reporter renders like any pre-baked check. Custom checks run wherever contract checks run: against the recorded transcript in the deterministic suite and against each live transcript in the llm suite.

## What the deterministic suite never does

It never invokes a model, never reads credentials, never touches the network, and never mutates anything outside its own report output. That property is the whole point, and any proposed check that would break it belongs in the llm suite instead.
