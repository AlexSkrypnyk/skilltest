# skilltest configuration

Two files, two audiences. `skilltest.yml` at the repository root carries everything that is true for the whole repo: where skills live, hook enforcement cases, global guard patterns, model aliases, environment defaults. `eval.yaml` next to each skill carries that one skill's contract and tasks. A skill author should be able to write a useful `eval.yaml` without reading the repo file, and mostly without writing a single regular expression.

## Schema versioning

Both files (and `results.json`) carry `version: "1"` in `MAJOR.MINOR` form; a missing value means the current version. Readers accept same-major minor differences with warnings for unknown fields and reject different majors with a pointer to `skilltest migrate`. Schema changes ship with a changelog entry and, for majors, a migration.

## Per-skill `eval.yaml`

Lives next to the skill (`skills/<name>/eval.yaml` by default; the location pattern is configurable repo-wide). Annotated reference:

```yaml
version: "1"

skill: run-harness-workflow          # optional; defaults to the directory name

# The behavioural contract: declared once, asserted against BOTH the recorded
# transcript (deterministic suite) and every live run (llm suite).
contract:
  tools:
    allowed: [Bash, Skill]           # live runs are restricted to exactly these
    required: [Skill]                # must appear in the transcript
    forbidden: []                    # must never appear
  commands:                          # label: pattern; the label names the behaviour, the pattern proves it
    required:
      harness drives the workflow: '\bharness\s+workflow\s+(start|next|status)\b'
    forbidden:
      raw git mutations: pack:git-mutations      # pre-baked pattern pack, no regex needed
      raw gh mutations: pack:gh-mutations
  skills:                            # Skill-tool invocations (sub-skills)
    required: [harness:build-generic]
    forbidden: []

security:
  packs: [baseline]                  # the baseline pack runs even when this block is omitted
  forbidden-tokens: []               # extra skill-specific strings that must not appear in shipped files

deterministic:
  transcript: fixtures/transcript.jsonl   # recorded canonical run; created and refreshed by `skilltest record`

llm:
  tasks:
    - name: invoked
      prompt: /harness:run-harness-workflow
    - name: discovery                # P2: the prompt does not name the skill; it must trigger anyway
      prompt: "Continue the current feature ticket end to end."
      discovery: true
  max-turns: 8
  trials: 3
  threshold: 0.8                     # minimum pass rate per model
  models: ladder                     # the repo ladder, or an explicit list of aliases/ids
  judge:
    rubric:                          # binary criteria; each is judged pass/fail, never a holistic score
      - Asks the harness for the next step rather than deciding the order itself.
      - Invokes the configured skill for each judgement step and the binary for deterministic steps.
      - Lets the harness own branch, board, and PR state instead of issuing raw git or gh.
  checks: []                         # optional custom check scripts, run against each live transcript
```

Design rules encoded here:

- **The contract is the centrepiece and is mode-independent.** `transcript` (deterministic) and `live` (llm) assert the identical contract; there is no way to declare a behaviour that is only checked in one mode by accident.
- **`label: pattern` maps document intent.** The label is the human explanation, the pattern is the evidence; failure messages print both.
- **`pack:` references remove regex authoring.** Any pattern position accepts `pack:<name>` to pull a pre-baked pattern set (catalog in `checks-deterministic.md`).
- **Judge criteria are binary.** Each rubric line is an independent yes/no; the judge may abstain per criterion (`unknown`), and abstentions are reported, not silently passed.

## Repo-level `skilltest.yml`

```yaml
version: "1"

paths:
  skills: skills                     # where skill directories live (repeatable)
  eval-file: eval.yaml               # per-skill config filename
  exclude: []                        # skill names exempt from the coverage gate (each requires a reason)

aliases:                             # command normalisation applied before contract matching
  harness: '(?:php\s+)?(?:\S*/)?bin/harness'   # `php bin/harness x`, `./bin/harness x` and `harness x` all match `harness x`

commands:
  resolve:
    binary: bin/harness              # optional; when set, `harness <sub>` references in skill files must
    list-args: [list, --json]        # resolve against the binary's real command list (structure group)

guards:                              # appended to every skill's contract.commands.forbidden
  broker bypass: pack:gh-mutations

hooks:                              # enforcement hooks and their crafted cases (deterministic `hooks` group)
  - script: hooks/reject-gh-pr-create.php
    cases:
      - tool: Bash
        input: { command: 'gh pr create --title x' }
        expect: block
      - tool: Bash
        input: { command: 'gh pr view 1' }
        expect: allow
  - script: hooks/reject-gh-project-mutate.php
    cases:
      - tool: Bash
        input: { command: 'gh project item-edit 1' }
        expect: block

models:
  aliases:
    haiku: claude-haiku-4-5
    sonnet: claude-sonnet-5
    opus: claude-opus-4-8
  ladder: [haiku, sonnet, opus]      # ordered weakest to strongest; drives `skilltest matrix`
  default: sonnet
  judge: haiku                       # judging is cheap-model work by default

llm:
  environment: host                  # or docker (see environments.md)
  docker:
    image: ghcr.io/alexskrypnyk/skilltest-agent:latest
    setup: ''                        # extra image-build commands
  lifecycle:                         # external-state setup/teardown around llm work (see environments.md)
    before-run: []
    before-task:
      - command: php playground/reset.php
        error-on-fail: true
    after-task: []
    after-run: []

report:
  redact: true                       # secrets (env values) are redacted from persisted results
```

## Precedence and overrides

CLI flags override `eval.yaml`, which overrides `skilltest.yml`, which overrides built-in defaults. Every effective value is visible via `skilltest validate --show-config` so "what actually applied" is never a mystery.

## Discovery

A skill is any directory under a configured skills path containing a `SKILL.md`. Discovery is recursive one level deep (matching the plugin convention). Every discovered skill must have an `eval.yaml` or be listed under `paths.exclude` with a reason; otherwise the deterministic run fails (the coverage gate). This is a product behaviour, not something consumers wire up.

## String ergonomics

- **File references (P2):** any long string value (`prompt`, rubric entries, custom check `run` bodies) may be a relative file path; when the path exists, the file contents are used. Keeps `eval.yaml` short and rubrics reviewable as prose files.
- **Template variables (P2):** `inputs:` at either config level defines key-value pairs available as `{{ vars.key }}` in prompts, lifecycle commands, and fixture paths.

## Environment variables

| Variable | Meaning |
|---|---|
| `SKILLTEST_CONFIG` | Path to `skilltest.yml` when not at the repo root |
| `SKILLTEST_NO_UPDATE_CHECK` | Disable the release-check ping (which is off in CI anyway) |
| `ANTHROPIC_API_KEY` / `CLAUDE_CODE_OAUTH_TOKEN` | Credentials for llm runs; passed through to `claude` (host) or the container (docker) |

skilltest itself reads no other secrets, and the deterministic suite reads none at all.
