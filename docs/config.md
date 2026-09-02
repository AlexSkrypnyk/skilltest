# Configuration

Everything skilltest does is driven by 2 YAML files. `skilltest.yml` at the repository root carries what's true for the whole repo: where skills live, command aliases and guards, enforcement hooks, model aliases, the execution environment. `eval.yaml` next to each skill carries that one skill's contract and tasks. A skill author can write a useful `eval.yaml` without opening the repo file, and mostly without writing a regular expression.

Both files are optional. skilltest runs without a `skilltest.yml` at all - every value has a built-in default - and a skill without an `eval.yaml` is still discovered and scanned by the structure and security groups (it fails the coverage gate unless it's excluded, more on that below). What isn't optional is shape: each file must parse as YAML and have a mapping at the top level, or loading stops with an error.

## Schema versioning

Both config files, and the `results.json` document, carry a `version` in MAJOR or MAJOR.MINOR form. The current schema is major 1, so `version: "1"` is what you write today. A missing `version` means the current version.

The value must be a quoted string. An unquoted YAML number like `1.10` parses to the float `1.1` and silently loses the minor, so skilltest rejects a bare number with `version must be a quoted string, e.g. "1" or "1.2".`

Version handling follows 2 rules:

- Same major, different minor: the file loads. Keys this reader doesn't know produce a warning per key (`unknown key (ignored).` with a dotted pointer like `llm.budget`), never a failure, so a file written for a newer minor still runs.
- Different major: loading stops with `unsupported schema major N; run 'skilltest migrate' to upgrade.`

## Per-skill `eval.yaml`

Lives next to the skill's `SKILL.md` (`skills/<name>/eval.yaml` by default; the filename is configurable repo-wide via `paths.eval-file`). `skilltest init` scaffolds one that passes `validate` out of the box. Annotated reference:

```yaml
version: "1"

skill: run-broker-workflow           # optional; defaults to the directory name

# The behavioral contract: declared once, asserted against BOTH the recorded
# transcript (deterministic suite) and every live run (llm suite).
contract:
  tools:
    allowed: [Bash, Skill]           # live runs are restricted to exactly these
    required: [Skill]                # must appear in the transcript
    forbidden: []                    # must never appear
  # label: pattern - the label names the behavior, the pattern proves it.
  commands:
    required:
      broker drives the workflow: '\bbroker\s+workflow\s+(start|next|status)\b'
    forbidden:
      # A pre-baked pattern pack; no regex to write.
      raw git mutations: pack:git-mutations
      raw gh mutations: pack:gh-mutations
  # Skill-tool invocations (sub-skills).
  skills:
    required: [broker:build-generic]
    forbidden: []

security:
  # The baseline pack runs even when this block is omitted.
  packs: [baseline]
  # Extra skill-specific strings that must not appear in shipped files.
  forbidden-tokens: []

# Per-check tuning for the deterministic structure group.
structure:
  suppress:
    structure.token-budget: 'reference tables push the count; split is planned'
  params:
    structure.description-length: { min: 16, max: 1024 }
    structure.token-budget: { limit: 5000, warn-at: 4000 }

deterministic:
  # Recorded canonical run; created and refreshed by `skilltest record`.
  transcript: fixtures/transcript.jsonl

llm:
  tasks:
    - name: invoked
      prompt: /broker:run-broker-workflow
  max-turns: 8
  trials: 3
  threshold: 0.8                     # minimum pass rate per model
  models: ladder                     # the repo ladder, or a list of aliases/ids
  judge:
    # Binary criteria; each is judged pass/fail, never a holistic score.
    rubric:
      - Asks the broker for the next step rather than deciding the order itself.
      - Lets the broker own branch, board, and PR state instead of raw git or gh.
    unknown: fail                    # what an abstention does: fail or ignore
  # Custom check scripts, run against each transcript.
  checks: []
```

Design rules encoded here:

- **The contract is mode-independent.** The deterministic transcript group and the llm suite assert the identical contract, so there's no way to declare a behavior that is only checked in one mode by accident.
- **`label: pattern` documents intent.** The label is the human explanation, the pattern is the evidence; failure messages print both. Patterns are delimiter-less PCRE regexes, validated to compile before anything runs.
- **`pack:` references remove regex authoring.** Any command pattern position accepts `pack:<name>` to pull a pre-baked pattern set: `git-mutations`, `gh-mutations`, `gh-readonly`, `package-installs`, `network-fetch`, `system-temp` (catalog in [checks-deterministic](checks-deterministic.md)). An unknown pack name is a validation error.
- **Judge criteria are binary.** Each rubric line is an independent yes/no; the judge may abstain per criterion, and `judge.unknown` decides whether an abstention fails the trial (`fail`, the default) or is reported without blocking (`ignore`).

Key reference:

| Key | Type | Default | Notes |
|---|---|---|---|
| `version` | quoted string | current version | `"1"` or `"1.2"` |
| `skill` | string | directory name | display and selection name |
| `contract.tools.allowed` | list | `[]` | empty means unrestricted; a non-empty list is enforced on live runs |
| `contract.tools.required` | list | `[]` | each must appear in the transcript |
| `contract.tools.forbidden` | list | `[]` | none may appear; overlap with required is a validation error |
| `contract.commands.required` | map label to pattern | `{}` | pattern or `pack:<name>` |
| `contract.commands.forbidden` | map label to pattern | `{}` | repo `guards` are appended here; a repo guard wins a label collision |
| `contract.skills.required` | list | `[]` | Skill-tool invocations |
| `contract.skills.forbidden` | list | `[]` | overlap with required is a validation error |
| `security.packs` | list | `[baseline]` | `baseline` is the only pack and is always added when missing |
| `security.forbidden-tokens` | list | `[]` | literal strings scanned in every shipped file except `eval.yaml` itself |
| `structure.suppress` | map check id to reason | `{}` | a non-empty reason skips the check and reports it as suppressed |
| `structure.params` | map check id to params | `{}` | see below |
| `deterministic.transcript` | path | none | relative to the skill directory; must exist |
| `llm.tasks` | list of tasks | `[]` | see the task shape below |
| `llm.max-turns` | int | none | live-run turn cap; unset means no cap |
| `llm.trials` | int | 1 | trials per task per model (`matrix` uses 3 when nothing sets it) |
| `llm.threshold` | float 0..1 | 0.8 | minimum pass rate per model |
| `llm.models` | `ladder`, comma string, or list | repo ladder, else `models.default` | aliases or raw model ids |
| `llm.judge.rubric` | list of strings | `[]` | must be non-empty whenever `llm.judge` is present |
| `llm.judge.unknown` | `fail` or `ignore` | `fail` | any other value is a validation error |
| `llm.checks` | list of `{name, run}` | `[]` | see custom checks below |
| `inputs` | mapping | none | accepted without warning, but nothing reads it; the `inputs` that does something lives on each task |

`structure.params` is read for 2 checks: `structure.description-length` takes `min` (default 16) and `max` (default 1024) characters, and `structure.token-budget` takes `limit` (default 5000), `warn-at` (default 4000), and an optional `vocab` file path. `structure.suppress` accepts any structure check id; the full catalog is in [checks-deterministic](checks-deterministic.md).

### Task shape

Each entry under `llm.tasks` needs a `name` and a `prompt`; everything else is optional. The name is what `--task` globs select and what reports print.

```yaml
llm:
  tasks:
    - name: continue-ticket
      prompt: 'Continue the current feature ticket end to end.'
      fixture: fixtures/playground      # copied into the trial workspace
      inputs:
        repos:
          - { source: ., commit: HEAD, dest: repo }
        workdir: repo                   # where the agent starts
        ticket: T-102                   # any other scalar becomes {{ vars.ticket }}
      responder:
        instructions: 'Play a busy maintainer; answer questions tersely.'
        max-followups: 3
      mcp-mocks:
        - server: tracker
          tools:
            - name: get_ticket
              responses:
                - match: { id: T-102 }
                  response: { status: ready }
```

| Key | Type | Notes |
|---|---|---|
| `name` | string, required | task id in selection and reports |
| `prompt` | string, required | the opening prompt, passed to the agent verbatim |
| `fixture` | path | file or directory copied into the fresh workspace; relative to the skill directory |
| `inputs.repos` | list | each needs `source` (relative to the repo root, or absolute) and `dest` (a safe relative path); `commit` defaults to `HEAD`; materialized as detached git worktrees |
| `inputs.workdir` | relative path | the agent's start directory inside the workspace; defaults to the workspace root |
| `inputs.<anything else>` | scalar | becomes a `{{ vars.<key> }}` template variable in lifecycle hook commands |
| `responder` | mapping | makes the task interactive; requires non-empty `instructions` and `max-followups` of at least 1; `model` defaults to the judge model |
| `mcp-mocks` | list | mock MCP servers for the trial, detailed in [checks-llm](checks-llm.md) |

An `mcp-mocks` entry needs a `server` name and at least 1 tool; each tool needs a `name` (an optional `description` helps the agent pick it) and at least 1 response. Each response declares exactly one matcher - `match` (exact), `match-regex`, or `match-schema`, each a mapping - and exactly one body: an inline `response` (a string is sent verbatim, anything else is JSON-encoded) or a `response-file` path relative to the skill directory. When `contract.tools.allowed` is non-empty, the mocked tools are added to the allowed list automatically as `mcp__<server>__<tool>`.

### Custom checks

Each `llm.checks` entry is `{name, run}`. The `run` command executes from the repository root with the transcript path as `$1` and the skill directory as `$2`; its exit code decides pass or fail, and an optional JSON object on stdout (`{"pass": bool, "message": "...", "evidence": "..."}`) enriches or overrides the verdict. Despite living under `llm`, these checks run against the recorded transcript in the deterministic suite too, so the escape hatch is asserted in both modes like the rest of the contract.

## Repo-level `skilltest.yml`

```yaml
version: "1"

paths:
  skills: skills                     # one path or a list of paths
  eval-file: eval.yaml               # per-skill config filename
  # Skills exempt from the coverage gate; each entry needs a reason.
  exclude:
    - skill: sandbox
      reason: throwaway experiments, never shipped

# Command normalization applied before contract matching. This one makes
# `php bin/broker x`, `./bin/broker x` and `broker x` all match `broker x`.
aliases:
  broker: '(?:php\s+)?(?:\S*/)?bin/broker'

commands:
  # Optional; when set, `broker <sub>` references in skill files must resolve
  # against the binary's real command list (structure group).
  resolve:
    binary: bin/broker
    list-args: [list, --json]

# Appended to every skill's contract.commands.forbidden.
guards:
  broker bypass: pack:gh-mutations

# Enforcement hooks and their crafted cases (deterministic `hooks` group).
hooks:
  - script: hooks/reject-gh-pr-create.php
    cases:
      - tool: Bash
        input: { command: 'gh pr create --title x' }
        expect: block
      - tool: Bash
        input: { command: 'gh pr view 1' }
        expect: allow

models:
  aliases:
    haiku: claude-haiku-4-5
    sonnet: claude-sonnet-5
    opus: claude-opus-4-8
  # Ordered weakest to strongest; drives `skilltest matrix`.
  ladder: [haiku, sonnet, opus]
  default: sonnet
  judge: haiku                       # judging is cheap-model work by default

llm:
  environment: host                  # or docker (see environments.md)
  docker:
    image: ghcr.io/alexskrypnyk/skilltest-agent:latest
    setup: ''                        # extra Dockerfile instructions
    cpus: 2                          # per-container CPU cap; omit for no limit
    memory-mb: 2048                  # per-container memory cap; omit for no limit
  # External-state setup/teardown around llm work (see environments.md).
  lifecycle:
    before-run: []
    before-task:
      - command: php playground/reset.php {{ workspace }}
        error-on-fail: true
    after-task: []
    after-run: []

report:
  redact: true                       # scrub env credentials from persisted artifacts
```

Key reference:

| Key | Type | Default | Notes |
|---|---|---|---|
| `version` | quoted string | current version | same rules as `eval.yaml` |
| `paths.skills` | string or list | `skills` | directories that contain skill directories |
| `paths.eval-file` | string | `eval.yaml` | the per-skill config filename |
| `paths.exclude` | list of `{skill, reason}` | `[]` | a missing skill name or reason is a validation error |
| `aliases` | map name to pattern | `{}` | delimiter-less regexes; every match in a command is rewritten to the canonical name before contract matching |
| `commands.resolve.binary` | string | none | the repo CLI, relative to the root |
| `commands.resolve.list-args` | list | `[]` | arguments that make the binary print its command list (JSON or text) |
| `guards` | map label to pattern | `{}` | pattern or `pack:<name>`; merged into every skill's forbidden commands |
| `hooks` | list | `[]` | each needs a `script` (relative to the root, must exist and be executable) and `cases` |
| `models.aliases` | map alias to model id | `{}` | the ids are opaque strings handed to the agent |
| `models.ladder` | list of aliases | `[]` | weakest first; every entry must be a defined alias |
| `models.default` | alias | none | must be a defined alias |
| `models.judge` | alias | none | must be a defined alias; the judge never follows `--models` |
| `llm.environment` | `host` or `docker` | `host` | anything else is a validation error |
| `llm.docker.image` | string | `ghcr.io/alexskrypnyk/skilltest-agent:latest` | base image the run image is built from |
| `llm.docker.setup` | string | `''` | Dockerfile instructions appended after the base image |
| `llm.docker.cpus` | positive number | none | per-container CPU limit |
| `llm.docker.memory-mb` | positive int | none | per-container memory limit in MB |
| `llm.lifecycle.<phase>` | list of hooks | `[]` | phases: `before-run`, `before-task`, `after-task`, `after-run` |
| `report.redact` | bool | `true` | disabling prints a loud warning on every persisting run |
| `inputs` | mapping | none | accepted without warning, but nothing reads it |

Each hook case under `hooks[].cases` names a `tool` (required), an `input` mapping (the tool input, defaults to empty), and an `expect` of `block` or `allow`. The runner feeds the case to the real script as a PreToolUse JSON payload on stdin; exit code 2 means the hook blocked, 0 means it allowed, and the case fails when the decision doesn't match `expect`.

Each lifecycle hook takes a `command` (required), and optionally `working-directory` (relative to the root; defaults to the root), `exit-codes` (an int or list, default `[0]`), `error-on-fail` (default `false`; when true, a failing `before-*` hook aborts the run), and `on-host` (default `false`; keeps the hook on the host when trials run in docker). Teardown phases only ever warn, so a failed cleanup can't mask a trial's verdict. The 4 phases and when they fire are covered in [environments](environments.md).

## Precedence and overrides

CLI flags override `eval.yaml`, which overrides `skilltest.yml`, which overrides built-in defaults. `skilltest validate --show-config` prints the effective merged configuration per skill, so "what actually applied" is never a mystery.

Most keys live at exactly one level - the contract, security, structure, and task blocks only exist in `eval.yaml`; paths, aliases, guards, hooks, models, and the report block only exist in `skilltest.yml` - so the interesting chains are the handful of values that resolve across levels:

| Value | Resolution order |
|---|---|
| models | `--models` > `llm.models` (eval) > `models.ladder` > `models.default` |
| threshold | `--threshold` > `llm.threshold` (eval) > 0.8 |
| trials | `--trials` > `llm.trials` (eval) > 1 (`matrix` inserts 3 below the eval value) |
| environment | `--env` > `llm.environment` (repo) > `host` |
| judge model | `--judge-model` > `models.judge` > first ladder entry > `models.default` |

2 asymmetries worth knowing: the execution environment and the judge model can't be set per skill, only repo-wide or per invocation. The judge defaulting to the ladder's weakest model (never the execution model) is deliberate - judging stays on a cheap, pinned model that doesn't follow `--models` upward.

## Discovery

A skill is any directory containing a `SKILL.md`. For each configured skills path, skilltest looks at its immediate children (`skills/<name>/SKILL.md`) and, for children that aren't skills themselves, one level deeper (`skills/<group>/<name>/SKILL.md`), matching the plugin convention of one optional grouping level. Deeper nesting isn't scanned. Results are sorted by path, so every report over them is deterministic.

Every discovered skill must have an `eval.yaml` or be listed under `paths.exclude` with a reason; otherwise the run fails the coverage gate. This is built-in behavior, not something you wire up. `skilltest coverage` renders the grid - covered, excluded, uncovered - and exits 1 on any uncovered skill. Skills without an `eval.yaml` are still scanned by the structure and security groups, so a skill can't dodge scrutiny by shipping no config.

The `--skill` and `--task` selection flags match names against shell-style globs: `*` matches any run of characters, `?` matches one, everything else is literal.

## Template variables and file references

Lifecycle hook commands accept `{{ ... }}` template variables, substituted per call: `{{ skill }}`, `{{ task }}`, `{{ trial }}`, `{{ model }}`, `{{ workspace }}`, and `{{ vars.<key> }}` for every scalar a task declares under `inputs`. An unknown variable substitutes to an empty string rather than reaching the shell as a literal brace expression. This substitution applies to lifecycle hook commands only - prompts, rubric entries, and check commands are used verbatim.

2 positions read their content from files: a task's `fixture` (a file or directory copied into the workspace) and an mcp-mock's `response-file`. Both resolve relative to the skill directory. Prompt and rubric strings are never treated as file paths; keep long prose inline or use YAML block scalars.

## Environment variables

| Variable | Meaning |
|---|---|
| `SKILLTEST_CONFIG` | Path to the repo config when it's not `<root>/skilltest.yml`. Pointing it at a missing file is an error, so a typo can't silently fall through to defaults |
| `SKILLTEST_AGENT` | The agent binary or command prefix for live runs; default is the first `claude` on `PATH` |
| `SKILLTEST_DOCKER` | The docker CLI path for the docker environment; default is the first `docker` on `PATH` |
| `SKILLTEST_TRIAL_TIMEOUT` | Per-trial wall-clock budget in seconds; default 300 |
| `SKILLTEST_NO_UPDATE_CHECK` | Any non-empty value disables the once-a-day release check |
| `CI` | Any non-empty value also disables the release check |
| `ANTHROPIC_API_KEY` / `CLAUDE_CODE_OAUTH_TOKEN` | Credentials for llm runs, forwarded to the agent (host) or the container (docker); an authenticated Claude Code home (`~/.claude`) satisfies the preflight too |

skilltest never handles credentials itself - it only checks that one of the credential signals is present before spending tokens, then forwards the host environment to the agent. The deterministic suite needs no credentials at all.

With `report.redact` on (the default), the value of every credential-shaped environment variable - a name containing a delimited `KEY`, `TOKEN`, `SECRET`, `PASSWORD`, `PASSPHRASE`, or `CREDENTIALS`, with a value of at least 4 characters - is replaced with `[REDACTED]` wherever it appears in persisted results, transcripts, and session logs. Details in [reporting](reporting.md).

## Where to next

The contract and pattern packs this file references are specified in [checks-deterministic](checks-deterministic.md); tasks, the judge, responders, and MCP mocks in [checks-llm](checks-llm.md); the host and docker environments plus lifecycle semantics in [environments](environments.md); the model ladder in [models](models.md). `skilltest validate` checks everything on this page, and the [CLI reference](cli.md) lists the flags that override it.
