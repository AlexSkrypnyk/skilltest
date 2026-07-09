# skilltest CLI

One binary, subcommands per job, and a strict split: the bare command is always the free, deterministic CI gate; anything that spends tokens is behind an explicit subcommand.

## Invocation model

`skilltest` runs from a repository root (or `--dir <path>`). It loads the repo config (`skilltest.yml`), discovers skills (see `config.md`), and applies the requested command to each selected skill. `--skill <name>` (repeatable, glob-friendly) narrows selection; the default is every discovered skill.

## Output contract

- Default output is terse and human: one line per check group per skill, a summary block, failures expanded with the check id, the evidence, and the fix direction.
- `--json` emits the full machine-readable result (see `reporting.md` for the schema) on stdout and nothing else.
- `--quiet` prints failures only.
- Reporters (`--reporter junit:<path>`, `--format github-comment`) are additive and specified in `reporting.md`.
- Everything diagnostic goes to stderr; stdout is reserved for results.

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Everything selected passed |
| `1` | One or more checks, trials, or gates failed |
| `2` | Configuration error: invalid schema, unresolvable reference, missing file, no skills found |

Exit codes are a documented API: CI scripts may rely on them, and changing them is a breaking change.

## Commands

### `skilltest` / `skilltest run` (P1)

Runs the deterministic suite (`structure`, `security`, `hooks`, `transcript`) for every selected skill, plus the coverage gate (a discovered skill without an `eval.yaml` fails the run unless excluded in config).

| Flag | Description |
|---|---|
| `--skill <glob>` | Select skills (repeatable) |
| `--group <name>` | Run one group only (`structure`, `security`, `hooks`, `transcript`) |
| `--check <id>` | Run one check id only (debugging) |
| `--list` | List selected skills and the checks that would run, without running |
| `--output <file>` | Persist the results document to this file |
| `--output-dir <dir>` | Persist the results document to a timestamped subdirectory of this directory |

No network, no model, no tokens. This is the command CI runs on every push; it touches disk only when a persistence flag (`--output`/`--output-dir`) is given.

### `skilltest llm` (P1)

Runs the llm suite: for each selected skill, each declared task is executed live (`trials` times, on the configured model or `--models`), the shared contract is asserted against every live transcript, and the judge scores each run against the rubric. Fails when any model's pass rate drops below the task threshold.

| Flag | Description |
|---|---|
| `--skill <glob>` | Select skills (repeatable) |
| `--task <glob>` | Select tasks within skills (repeatable) |
| `--models <list>` | Override models (aliases or full ids, comma-separated); default is the skill's `models:` setting |
| `--trials <n>` | Override trial count |
| `--env <host\|docker>` | Execution environment (see `environments.md`) |
| `--parallel <n>` | Concurrent trials (default 1) |
| `--threshold <0..1>` | Override pass-rate threshold |
| `--judge-model <model>` | Override the judge model |
| `--baseline` | Also run each task without the skill installed and report the improvement delta (P2) |
| `--cache` / `--no-cache` | Reuse cached trial results keyed on task + fixtures + model + skill content (P2) |
| `--output <file>` | Write `results.json` |
| `--keep-workspace` | Preserve per-trial workspaces for debugging (P2) |

Requires an authenticated `claude` (host) or credentials passed to the container (docker). Never runs implicitly: CI pipelines must opt in with an explicit `skilltest llm` step and a secret.

### `skilltest matrix` (P1)

The multi-model answer machine: runs the llm suite for the selected skills across the model ladder (weakest first) and renders the model matrix report, ending with a minimal-model verdict per skill ("smallest model whose pass rate meets the threshold"). Details in `models.md`.

| Flag | Description |
|---|---|
| `--models <list>` | Override the ladder |
| `--trials <n>` | Trials per model per task (default 3) |
| `--stop-at-pass` | Stop climbing the ladder at the first passing model (cheaper, no full matrix) |
| `--output <file>` | Write `results.json` |

### `skilltest record` (P1)

Runs one live trial of a skill's task and writes the transcript to the skill's fixture path (`fixtures/transcript.jsonl` by default), then immediately asserts the contract against it and reports the result. This is how deterministic `transcript` fixtures are created and refreshed: change the skill, `skilltest record`, review the diff, commit.

| Flag | Description |
|---|---|
| `--skill <name>` | Required: the skill to record |
| `--task <name>` | Task to record (default: the first) |
| `--model <model>` | Model to record with (default: the repo default) |
| `--force` | Overwrite an existing fixture |

A recorded fixture that fails its own contract is written anyway (for inspection) but the command exits `1`.

### `skilltest validate` (P1)

Schema-validates every `eval.yaml` and the repo `skilltest.yml`, then runs coherence checks: required and forbidden sets are disjoint, every referenced fixture and hook script exists, every pack name resolves, every model alias resolves, PCREs compile, and (when command resolution is configured) every `harness <sub>`-style reference in skill files resolves against the configured binary. Exit `2` on any violation. Free of network and tokens; part of the deterministic gate but also useful standalone in editor save-hooks.

### `skilltest init` (P2)

Scaffolds an `eval.yaml` for a skill directory: reads `SKILL.md`, pre-fills the skill name, allowed tools, a starter contract, the default packs, and a commented llm block. With `--ai`, uses a model (an authenticated `claude`) to draft tasks, contract patterns, and a rubric from the skill body, marked with confidence notes for human review; without credentials it falls back to the commented template. `--force` overwrites. Apply is merge-safe: existing files are never silently clobbered.

### `skilltest coverage` (P1)

Renders the skill-to-eval coverage grid: which skills have an `eval.yaml`, which have a transcript fixture, which have llm tasks, and which have nothing. `--format text|markdown|json`. With `--spec` (P2), additionally parses SKILL.md trigger promises ("use when", "do not use for") into requirement ids and maps each to covering tasks, reporting unexercised promises.

### `skilltest grade` (P2)

Re-runs grading without executing an agent: `--transcript <file.jsonl>` asserts a skill's contract against any transcript, and `--results <results.json>` re-scores a saved llm run (useful after tightening a rubric or contract). Token-free unless re-judging is requested with `--judge`.

### `skilltest gate` (P2)

Compares a current `results.json` against a committed baseline: aggregate pass-rate regression threshold, golden tasks that must always pass, and configurable policies for added/removed tasks (`allow`/`warn`/`fail`). Stable exit codes: `0` pass, `1` regression, `2` config error. Formats: `human`, `json`, `markdown`, `github-actions`.

### `skilltest compare` (P2)

Side-by-side comparison of two or more `results.json` files: per-task and per-model score deltas, pass-rate changes, duration and cost changes. This is how two branches, two skill revisions, or two models are compared outside the matrix.

### `skilltest report` (P2)

Renders saved `results.json` files: terminal summary, `--html <file>` writes a single self-contained HTML report (no server, no external assets), `--interpret` adds a plain-language reading of the numbers.

### `skilltest tokens` (P2)

Token accounting for skill files: `tokens count <paths>` reports per-file token counts, `tokens compare <ref>` diffs counts against a git ref with `--threshold <pct>` for CI gating on skill bloat. The same counter backs the `structure.token-budget` pre-baked check.

### `skilltest migrate` (P2)

Checks a `eval.yaml`, `skilltest.yml`, or `results.json` against the current schema version and rewrites it when a newer major schema requires it. Same-major minor differences are read with warnings; different majors are rejected everywhere else with a pointer to this command.

### `skilltest self-update` (P2)

Downloads and verifies the latest release (checksum-verified) and replaces the current executable after confirmation; `--yes` for scripts. Never runs implicitly and never blocks another command.

### `skilltest version` (P1)

Prints version, schema versions supported, and build info. `--json` for machines.
