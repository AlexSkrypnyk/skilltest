# The deterministic suite

The deterministic suite is the CI gate: every check in it runs with no model, no network, and no tokens, so the same input produces the same verdict on every machine, every time. It has 4 groups - `structure`, `security`, `hooks`, and `transcript` - plus a coverage gate, and most of what you'll want to assert about a skill is already in the pre-baked catalog on this page, on by default or enabled with a line of YAML. This page lists every check id that exists, what each one asserts, and what makes it fail.

## Check anatomy

Every check has a stable id of the form `<group>.<name>`. Ids are an API: reports print them, `--check` selects them, and suppressions reference them.

A check produces 1 of 4 outcomes:

- **pass** - the skill satisfies the check.
- **fail** - the check found a violation. The result carries the offending file, line, and evidence, plus a message with the fix direction, so you can act on the report without re-running anything.
- **warn** - advice. A warning is listed like a failure but never fails the run. The `structure.token-budget` warn threshold and the whole `structure.advisory` check use it.
- **suppressed** - the skill switched the check off in its `eval.yaml` with a written reason, and the report shows the check as deliberately suppressed rather than silently absent.

`skilltest run` exits 0 when every check passes, 1 when any check fails, and 2 on a configuration error (malformed or incoherent YAML, an impossible selection, no skills found) before anything runs.

### Selecting groups and checks

```bash
skilltest run                                  # the whole suite plus the coverage gate
skilltest run --group security                 # 1 group only
skilltest run --check structure.token-budget   # 1 check only
skilltest run --list                           # show what would run, without running
```

The id's prefix decides which group owns it, so `--check` alone narrows the run to the 1 group that can produce it. An id with an unknown prefix, or one that contradicts an explicit `--group`, is rejected before the run; an id that matches nothing in the run is an error too, with a hint to verify it against `--list`. Narrowing with either flag switches the coverage gate off. The `structure` and `security` groups also run standalone as `skilltest structure` and `skilltest security` - the [CLI reference](cli.md) covers both.

### Suppressing a check

A skill can switch off a `structure` check in its `eval.yaml`, keyed by check id, with the reason as the value:

```yaml
structure:
  suppress:
    structure.token-budget: 'Reference-heavy skill; the budget is raised deliberately.'
```

The reason isn't decoration: an entry with an empty reason has no effect and the check runs normally. Suppression exists only for the `structure` group. Security findings can't be suppressed or downgraded at all, hook cases and contract assertions are removed by editing the config that declares them, and every suppression appears in the report with its reason.

## Group: `structure`

The structure group proves a skill's files are well-formed, internally consistent, and honest about what they reference. It runs against the `SKILL.md` and skill directory of every discovered skill, whether or not the skill ships an `eval.yaml`. 2 checks are conditional: `structure.contract-coherent` is skipped when there's no `eval.yaml` to judge, and `structure.command-refs-resolve` runs only when the repo configures `commands.resolve`. Everything else is always on.

| Check id | Fails when |
|---|---|
| `structure.frontmatter` | `SKILL.md` doesn't open with a `---` YAML block, the block doesn't parse as a mapping, or `name:` or `description:` is missing or empty |
| `structure.name-matches-dir` | The frontmatter `name:` differs from the skill directory's basename |
| `structure.description-length` | The trimmed description is shorter than `min` (default 16) or longer than `max` (default 1024) characters |
| `structure.allowed-tools-declared` | The frontmatter declares `allowed-tools` but the value is neither a non-empty string nor a list of scalars; a skill that declares no restriction passes |
| `structure.no-unrestricted-bash` | Any declared `allowed-tools` entry grants wildcard Bash: `Bash(*)` or `Bash(:*)` |
| `structure.no-pre-model-exec` | The body contains a `` !`...` `` dynamic-context execution |
| `structure.files-exist` | A relative file path referenced in the body doesn't exist in the skill directory, or steps outside it with `..` |
| `structure.command-refs-resolve` | A `<binary> <subcommand>` reference in any shipped file names a subcommand the binary doesn't actually have |
| `structure.token-budget` | `SKILL.md` exceeds `limit` tokens (default 5000); at or above `warn-at` (default 4000) it warns without failing |
| `structure.contract-coherent` | The skill's own `eval.yaml` has coherence errors |
| `structure.advisory` | Never - it's warn-only quality advice |

### What counts as a file reference

`structure.files-exist` reads 2 forms from the body: markdown link targets (`[text](path)`) and inline code spans (`` `path` ``). URLs, absolute paths, `~` paths, and anchors are ignored. A bare token without a slash only counts when it ends in a recognized file extension (`.md`, `.php`, `.sh`, `.yaml`, `.json`, `.png`, and the like), so prose and commands don't trip it.

### Resolving command references

When your skills document a CLI, `structure.command-refs-resolve` keeps that documentation honest. Point the repo config at the binary and the arguments that print its command list:

```yaml
commands:
  resolve:
    binary: bin/broker
    list-args: ['list', '--format=json']
```

skilltest runs the list command once (30-second budget), parses the output (a JSON array of names, a JSON array of `{name}` objects, Symfony's `{"commands": [...]}` wrapper, or a plain-text list), and keeps the first token of every command name. It then scans every shipped skill file except `eval.yaml` for `broker <subcommand>` references and fails on any subcommand the binary doesn't have. A binary that can't run, or whose output yields nothing parseable, is a configuration error (exit 2), never a silent pass - the check would otherwise pass by doing nothing.

### Tuning the parameters

Per-check parameters live in `eval.yaml` under `structure.params`, keyed by check id:

```yaml
structure:
  params:
    structure.description-length:
      min: 24
      max: 800
    structure.token-budget:
      limit: 6000
      warn-at: 5000
      vocab: vocab/o200k_base.tiktoken
```

Token counts use the same counter as the `tokens` command, so the gate and the accounting report can never disagree. Without `vocab`, the count is a documented estimation heuristic (about 4 bytes per token, reported as `estimate`); with `vocab` pointing at a tiktoken-format vocabulary file, counts are exact byte-pair encoding (reported as `bpe`). A configured vocabulary that can't be read is a configuration error, not a silent fallback.

### What contract coherence covers

`structure.contract-coherent` validates the `eval.yaml` itself: required and forbidden sets are disjoint for tools, skills, and commands (labels and patterns both); every command pattern compiles as a regex or names a known pack; every security pack exists; a declared transcript fixture exists on disk; a declared judge has a non-empty rubric and a valid `unknown` policy; interactive tasks declare well-formed responder blocks. The failing result reports the error count and the first error; `skilltest validate` lists them all.

### Advisory thresholds

`structure.advisory` emits 1 warning per tripped heuristic, and a skill that trips none gets a single pass:

- The body reads as an over-long procedure: more than 20 numbered steps.
- The description enumerates more than 8 quoted trigger phrases.
- The skill ships more than 12 reference markdown files besides `SKILL.md`.

These are advice, never gates - they flag shapes that tend to age badly, and the fix direction (move detail into reference files, prefer broader intent phrasing, consolidate references) rides along in the message.

## Group: `security`

Every regular file a skill ships - not just `SKILL.md`, but bundled scripts, references, and fixtures - is scanned line by line for danger patterns, for every discovered skill whether or not it has an `eval.yaml`. This is a static supply-chain scan, not a runtime boundary: it catches malicious or careless skill content before a model ever reads it. The 1 file excluded is the skill's own `eval.yaml`, because that's the skilltest sidecar where forbidden tokens are declared, so scanning it would self-trigger.

The `baseline` pack is always on - it's the only security pack that exists, and nothing a skill declares can disable it. Findings are always errors, never warnings: there's no suppression and no downgrade, so a skill that trips the security group doesn't ship.

| Check id | Flags a line that |
|---|---|
| `security.curl-pipe-shell` | Pipes a `curl` download into `bash`, `sh`, or `zsh` |
| `security.credential-read` | Reads a credential or secret file: `cat`, `less`, `head`, `tail`, or `printenv` touching `.env`, `.aws/credentials`, `.ssh/id_rsa`, `.npmrc`, or `.netrc` |
| `security.credential-encode` | Runs `base64` over `.env`, `id_rsa`, or `credentials` |
| `security.pre-model-exec-net` | Runs `curl` inside a pre-model `` !`...` `` command |
| `security.pre-model-exec-secrets` | Reads `.env`, `printenv`, `secret`, `id_rsa`, or `credentials` inside a pre-model `` !`...` `` command |
| `security.destructive-delete` | Recursively deletes at the filesystem root (`rm -rf /`) |
| `security.forbidden-tokens` | Contains a token the skill's `eval.yaml` declares forbidden |

Forbidden tokens are plain substrings, matched against every line of every shipped file:

```yaml
security:
  forbidden-tokens:
    - 'sk-ant-'
    - 'internal.example.com'
```

Every finding names the check, the file and line it fired on, and the offending line as evidence, so you see exactly what tripped the scan and where.

## Group: `hooks`

The hooks group proves the repository's enforcement hooks actually enforce. For each hook declared in `skilltest.yml`, skilltest executes the real hook script once per crafted case, feeding the case's tool input on stdin using the Claude Code PreToolUse protocol, and asserts the decision. This group runs once at repo level, not per skill.

```yaml
hooks:
  - script: .claude/hooks/reject-pr-create.php
    cases:
      - tool: Bash
        input: { command: 'gh pr create --title x' }
        expect: block
      - tool: Bash
        input: { command: 'gh pr view 12' }
        expect: allow
```

Each case becomes this stdin payload:

```json
{"hook_event_name": "PreToolUse", "tool_name": "Bash", "tool_input": {"command": "gh pr create --title x"}}
```

The rules:

- `expect: block` passes only when the hook exits 2, the blocking exit code. `expect: allow` passes only when it exits 0. Any other exit fails the case, and the failure message carries the input, the expected and actual codes, and the hook's stderr.
- Each case gets 10 seconds; a hook that outlives its budget is killed and reported as exit 124.
- Results render under `hooks.<script name>` - the script's filename without its extension, so the cases above report as `hooks.reject-pr-create`. Each case is its own result.
- A hook that names no script, a script that's missing or not executable, a case without a `tool`, or an `expect` other than `block`/`allow` is a configuration error that aborts the run - a hook can never silently pass by not running.

This is what makes an enforcement boundary testable without a model: the deterministic suite fails the moment a hook stops blocking what it must block. Adding an enforcement rule means adding a script and a handful of cases - no test code.

## Group: `transcript`

The transcript group asserts the skill's full contract against a recorded transcript. The transcript is a JSONL tool-call record of 1 real headless run, written by `skilltest record`; it's a fixture, reviewed and committed like any other, so contract regressions surface as deterministic CI failures and skill changes surface as reviewable fixture diffs. Declare it in `eval.yaml`:

```yaml
deterministic:
  transcript: fixtures/transcript.jsonl
```

The path resolves relative to the skill directory. A skill that declares no transcript skips this group with the note `no transcript fixture declared` - that's not a failure. A declared fixture that's missing from disk is a configuration error before anything runs.

The contract itself lives under `contract:` in `eval.yaml`, and the identical checker grades every live trial in the [llm suite](checks-llm.md), so the 2 suites can never disagree about what the contract means:

```yaml
contract:
  tools:
    required: [Bash]
    forbidden: [WebSearch]
  commands:
    required:
      starts-workflow: 'broker workflow start'
    forbidden:
      no-direct-pr: 'pack:gh-mutations'
  skills:
    forbidden: [deploy]
```

| Check id | Passes when |
|---|---|
| `contract.tools.required` | Each named tool appears in the transcript at least once |
| `contract.tools.forbidden` | The named tool never appears |
| `contract.commands.required` | The pattern matches at least 1 executed Bash command, after alias normalization |
| `contract.commands.forbidden` | No executed Bash command matches the pattern |
| `contract.skills.required` | The named sub-skill is invoked through the Skill tool at least once |
| `contract.skills.forbidden` | The named sub-skill is never invoked |

Command entries map a human-readable label to 1 pattern; the label is what the report prints. A pattern is either a delimiter-less regex (skilltest adds the delimiters) or a `pack:<name>` reference to a [pattern pack](#pattern-packs).

2 repo-level settings in `skilltest.yml` shape command matching:

- `aliases:` maps a canonical name to a pattern that identifies it; every occurrence in an executed command is rewritten to the canonical name before matching. That's how `php bin/broker x`, `./bin/broker x`, and `broker x` all satisfy a single `broker x` pattern.
- `guards:` is a label-to-pattern map appended to every skill's forbidden commands, so no `eval.yaml` can forget the repo-wide rule.

```yaml
aliases:
  broker: '(?:php\s+)?(?:\./)?bin/broker'
guards:
  no-force-push: 'git\s+push\b.*--force'
```

## Pattern packs

Any command pattern position accepts `pack:<name>` instead of a hand-written regex. A pack is a set of regexes and matches a command when any of them does. 6 packs exist:

| Pack | Matches |
|---|---|
| `git-mutations` | `git commit`, `push`, `checkout`, `switch`, `merge`, `rebase`, `tag`, and `git reset --hard` |
| `gh-mutations` | `gh pr create/merge/close/edit`, `gh issue create/edit/close`, `gh project` item and field mutations, and `gh api` with a `POST`, `PUT`, `PATCH`, or `DELETE` method |
| `gh-readonly` | `gh pr view/list/checks` and `gh issue view/list` - the read-only complement, for `required` positions |
| `package-installs` | Global `npm i/install/add`, `pip install` and `pip3 install`, `composer global require`, `brew install` |
| `network-fetch` | `curl` or `wget` fetching an `http`, `https`, or `ftp` URL |
| `system-temp` | Any `/tmp` path, `$TMPDIR`, or `${TMPDIR}` reference |

Packs are versioned with the tool, and a release note calls out pattern additions because they can newly fail an existing suite. The security group has its own pack list with exactly 1 entry, `baseline`, which is always on whether you list it or not.

## Custom checks

The escape hatch for the genuinely skill-specific residue: a check can be a script. Declare it in `eval.yaml` under `llm.checks`:

```yaml
llm:
  checks:
    - name: board-column-advanced
      run: php tests/checks/board-column.php
```

The contract, precisely:

- skilltest appends 2 shell-quoted arguments to the `run` command - the transcript path as `$1` and the skill directory as `$2` - and executes it with the repository root as the working directory, so relative script paths resolve against the repo root.
- The exit code is the verdict: 0 passes, any non-zero exit fails.
- The script may print a JSON object on stdout: `{"pass": true, "message": "...", "evidence": "..."}`. All 3 keys are optional. A boolean `pass` overrides the exit-code verdict; `message` replaces the default message; `evidence` is rendered alongside it, like any pre-baked check's evidence. Stdout that isn't a JSON object is ignored, and stderr is discarded.
- Each script gets 60 seconds of wall-clock time; a script that outlives its budget is killed and reported as exit 124.
- The result renders under `check.<name>` - the example above reports as `check.board-column-advanced`, and `--check check.board-column-advanced` selects it.
- An entry missing its `name` or `run` produces no result at all.

Custom checks run wherever contract checks run: against the recorded fixture in the deterministic suite, and against each live transcript in the llm suite.

## The coverage gate

The full suite ends with 1 more question: does every discovered skill have an `eval.yaml`? A skill without one, and without an exclusion, fails the gate under the id `coverage.eval-exists`, with the fix in the message: add an `eval.yaml`, or exclude the skill with a reason under `paths.exclude` in `skilltest.yml` (see [configuration](config.md)). The gate belongs to the full suite only - any `--group` or `--check` narrowing switches it off, and it isn't selectable by id.

## What the deterministic suite never does

It never invokes a model, never reads credentials, never touches the network, and never mutates anything outside its own report output. That property is the whole point: it's what lets the suite gate every push with zero cost and zero flakiness. Anything that needs a model - live trials, rubric judging - lives in the [llm suite](checks-llm.md) instead.

Next: record a fixture with `skilltest record`, then wire `skilltest run` into CI. The [CLI reference](cli.md) covers every flag, and [reporting](reporting.md) covers the results document the run emits.
