# Command-line reference

skilltest is a single binary with a subcommand per job, and this page is the complete map: what each command does, every flag it takes, and the exit codes CI can rely on. A strict split runs through the whole surface: the bare command is the free, deterministic gate, and anything that spends tokens sits behind a subcommand you invoke explicitly.

## Invocation model

skilltest runs from a repository root; `--dir <path>` points it at one from anywhere else. It loads the repo config (`skilltest.yml`), discovers skills (see [configuration](config.md)), and applies the requested command to each selected skill.

`skilltest` with no subcommand runs `run`, the deterministic suite. On `run`, `llm`, and `matrix`, `--skill <glob>` narrows the selection - it's repeatable and glob-friendly, and the default is every discovered skill. `record` and `grade` operate on a single skill, so their `--skill` takes an exact name, not a glob.

The token-spending commands are `llm`, `matrix`, and `record`, plus the opt-in `grade --judge` and `init --ai`. They need an authenticated `claude` (or credentials passed to the Docker container) and never run implicitly; everything else runs without a model and without tokens.

## Output contract

Default output is terse and human: a status line per check group per skill, a summary block, and every failure expanded with its check id, message, and evidence. Diagnostics go to stderr; stdout is reserved for results.

`--json`, on the commands that produce a results document (`run`, `llm`, `matrix`, `version`, `validate`, `grade`), writes the machine-readable document to stdout and nothing else; the schema lives in [reporting](reporting.md). `coverage`, `security`, `structure`, and `tokens` reach the same end through `--format json`.

`-q` / `--quiet` is Symfony's global verbosity flag, and `run` and `llm` treat it as failures-only mode: a green run prints nothing, and a red run names exactly what failed.

`--reporter junit:<path>` writes an additional JUnit XML file, and `--format github-comment` renders stdout as a GitHub PR comment block instead of the human report; both are covered in [reporting](reporting.md).

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Everything selected passed |
| `1` | 1 or more checks, trials, or gates failed |
| `2` | Configuration error: invalid schema, unresolvable reference, missing file, no skills found |

Exit codes are a documented API: CI scripts rely on them, and changing them is a breaking change. 3 commands deliberately read results rather than gate on them: `matrix` and `report` exit `0` whatever the results say, and `compare` exits `0` whenever every file loads. A configuration error exits `2` everywhere.

## skilltest version

Prints the tool version, the supported config and results schema versions, and build info (the PHP version and runtime).

| Flag | Description |
|---|---|
| `--json` | Output as JSON |

## skilltest validate

Schema-validates the repo `skilltest.yml` and every discovered `eval.yaml`, then checks coherence: required and forbidden sets are disjoint, referenced fixtures and hook scripts exist, pattern packs, security packs, and model aliases resolve, patterns compile, and every exclusion carries a reason. Any error exits `2`; a discovered skill with no `eval.yaml` is a warning here, because the coverage gate owns that failure. It's free of network and tokens, which makes it a good editor save-hook.

| Flag | Description |
|---|---|
| `--dir <path>` | Repository root (default: current directory) |
| `--show-config` | Print the effective merged configuration per skill |
| `--models <list>` | Override the model list (comma-separated) shown by `--show-config` |
| `--json` | Output as JSON |

## skilltest coverage

Renders the skill-to-eval coverage grid - which skills have an `eval.yaml`, which have a transcript fixture, and how many llm tasks each declares - and enforces the coverage gate: a discovered skill with no `eval.yaml` that isn't excluded exits `1`. Reach for it when you want the full grid rather than the gate's failure lines.

| Flag | Description |
|---|---|
| `--dir <path>` | Repository root (default: current directory) |
| `--format <name>` | Output format: `text`, `markdown`, or `json` (default: `text`) |

## skilltest security

Runs the `security` group on its own: a static supply-chain scan of every file each skill ships, with the always-on baseline pack ([deterministic checks](checks-deterministic.md)). Findings are always errors - configuration can't downgrade them - and any finding exits `1`. Useful when you want the scan's own report, markdown table included, without the rest of the suite.

| Flag | Description |
|---|---|
| `--dir <path>` | Repository root (default: current directory) |
| `--format <name>` | Output format: `text`, `markdown`, or `json` (default: `text`) |

## skilltest init

Scaffolds a `validate`-passing `eval.yaml` next to a skill's `SKILL.md`, pre-filled from the manifest. It takes the skill directory as its only argument (default: the current directory). The plain mode needs no credentials; `--ai` drafts tasks, command patterns, and a rubric from the skill body with an authenticated `claude`, flags low-confidence guesses for review, and falls back to the plain template when the model is unavailable. An existing `eval.yaml` is never overwritten without `--force`: the command prints a diff of what it would have written and exits `1`. A directory without a `SKILL.md` exits `2`.

| Flag | Description |
|---|---|
| `--ai` | Draft tasks, patterns, and a rubric from the skill body with an authenticated `claude` |
| `--force` | Overwrite an existing `eval.yaml` |

## skilltest structure

Runs the `structure` group on its own: pre-baked, default-on checks that each skill's files are well-formed, internally consistent, and honest about what they reference ([deterministic checks](checks-deterministic.md)). A failing check exits `1`; warnings are listed but never affect the exit code.

| Flag | Description |
|---|---|
| `--dir <path>` | Repository root (default: current directory) |
| `--format <name>` | Output format: `text`, `markdown`, or `json` (default: `text`) |

## skilltest tokens

Token accounting so skill files stay small on purpose. `tokens count <paths>` reports per-file counts for the markdown files under each path; `tokens compare [ref]` diffs every discovered skill's markdown files against a git ref (default: `origin/main`, falling back to `main`) so CI can gate on skill bloat. Counts are estimated by default; a tiktoken-format vocabulary via `--vocab` switches to exact byte-pair encoding. The same counter backs the `structure.token-budget` check. Growth beyond `--threshold` or, under `--strict`, a file over its absolute token limit exits `1`; a bad action, path, ref, or vocabulary exits `2`.

| Flag | Description |
|---|---|
| `--dir <path>` | Repository root (default: current directory) |
| `--format <name>` | Output format: `table` or `json` (default: `table`) |
| `--sort <order>` | Sort order for `count`: `path` or `tokens` (default: `path`) |
| `--vocab <file>` | Tiktoken-format vocabulary file for exact BPE counts (default: estimation) |
| `--threshold <pct>` | Fail `compare` when an existing file grows more than this percentage |
| `--strict` | Fail `compare` when any file exceeds its absolute token limit |

## skilltest run

The default command and the CI gate: `skilltest` alone means `skilltest run`, and this is the command CI runs on every push. It runs the deterministic suite - `structure`, `security`, and `transcript` per selected skill, `hooks` once at repo level - plus the coverage gate, under which a discovered skill without an `eval.yaml` fails the run unless it's excluded in config. No model and no tokens anywhere in the path, and results touch disk only when a persistence flag, a file reporter, or the session log asks for a file.

`run` makes no network call unless you ask for one. Pass `--update-check`, or set `SKILLTEST_UPDATE_CHECK` to any non-empty value, and it checks for a newer skilltest release after the run - at most once a day, cached under `.skilltest/cache/` - and prints a notice to stderr. Without either, nothing leaves the machine.

| Flag | Description |
|---|---|
| `--dir <path>` | Repository root (default: current directory) |
| `--skill <glob>` | Select skills by name glob (repeatable) |
| `--group <name>` | Run a single group: `structure`, `security`, `hooks`, or `transcript` |
| `--check <id>` | Run a single check id |
| `--list` | List the selected skills and the checks that would run, without running |
| `--json` | Emit the machine-readable results document on stdout and nothing else |
| `--format github-comment` | Render stdout as a GitHub PR comment block instead of the human report |
| `--reporter junit:<path>` | Write an additional JUnit XML file (repeatable) |
| `--session-log` | Write an ordered NDJSON event stream for the run (requires `--session-dir`) |
| `--session-dir <dir>` | Directory the `--session-log` stream is written to |
| `--output <file>` | Persist the results document to this file |
| `--output-dir <dir>` | Persist the results document, with artifacts, to a timestamped subdirectory of this directory |
| `--interpret` | Append a plain-language reading of the result: the top failure and a concrete next step |
| `--update-check` | Check once a day for a newer skilltest release; the only network call this command makes |

## skilltest llm

The live suite: for each selected skill and task, it runs the skill headlessly through Claude Code `trials` times per model, asserts the same contract the deterministic suite asserts against every live transcript, and, when the skill declares a judge, has the judge model score each trial against its rubric ([llm checks](checks-llm.md)). The gate fails - exit `1` - when any model's pass rate drops below the task threshold. It needs an authenticated `claude` on the host, or credentials passed to the container for `--env docker` ([environments](environments.md)); a missing binary or credential, or an unreachable Docker daemon, exits `2` before any trial runs. Each trial gets a 300-second timeout; the `SKILLTEST_TRIAL_TIMEOUT` environment variable (seconds) overrides it.

| Flag | Description |
|---|---|
| `--dir <path>` | Repository root (default: current directory) |
| `--skill <glob>` | Select skills by name glob (repeatable) |
| `--task <glob>` | Select tasks by name glob (repeatable) |
| `--models <list>` | Override the configured models (aliases or ids, comma-separated) |
| `--trials <n>` | Override the trial count per model |
| `--threshold <0..1>` | Override the pass-rate threshold |
| `--env <name>` | Execution environment: `host` or `docker` |
| `--parallel <n>` | Number of concurrent trials (default: 1) |
| `--judge-model <model>` | Override the judge model (alias or id); the judge model never follows `--models` |
| `--json` | Emit the machine-readable results document on stdout and nothing else |
| `--output <file>` | Persist the results document to this file |
| `--output-dir <dir>` | Persist the results document and transcripts to a timestamped subdirectory of this directory |
| `--keep-workspace` | Preserve each trial workspace after the run and print its path for debugging |
| `--cache` | Reuse cached trial results keyed on the task, fixtures, model, and skill content |
| `--no-cache` | Ignore and don't write cached trial results (overrides `--cache`) |
| `--interpret` | Append a plain-language reading of the result: the top failure and a concrete next step |

## skilltest matrix

The multi-model answer machine: it runs the same live suite as `llm` across the model ladder, weakest first, and renders the model matrix - each skill's per-model grid, the minimal-model verdict ("the smallest model whose pass rate meets the threshold"), per-model failure modes, and cost totals ([models](models.md)). Unlike `llm` it's a report, not a gate, so it exits `0` whatever the verdicts; only a configuration error exits `2`. It runs on the host only - `--env docker` is rejected. `--estimate` prints the plan (skills x tasks x trials x models) and a rough cost without running anything, so sizing a run needs neither credentials nor a token.

| Flag | Description |
|---|---|
| `--dir <path>` | Repository root (default: current directory) |
| `--skill <glob>` | Select skills by name glob (repeatable) |
| `--task <glob>` | Select tasks by name glob (repeatable) |
| `--models <list>` | Override the ladder (aliases or ids, comma-separated) |
| `--trials <n>` | Trials per model per task (default: 3) |
| `--threshold <0..1>` | Override the pass-rate threshold |
| `--env <name>` | Execution environment: `host` only |
| `--parallel <n>` | Number of concurrent trials (default: 1) |
| `--judge-model <model>` | Override the judge model (alias or id); the judge model never follows the ladder |
| `--stop-at-pass` | Stop climbing the ladder at the first passing model (cheaper, no full matrix) |
| `--estimate` | Print the plan and a rough cost, then exit without running |
| `--format <name>` | Human output format: `text` or `markdown` (default: `text`) |
| `--json` | Emit the machine-readable results document on stdout and nothing else |
| `--output <file>` | Persist the results document to this file |
| `--output-dir <dir>` | Persist the results document and transcripts to a timestamped subdirectory of this directory |
| `--interpret` | Append a plain-language reading of the result: the weakest task and a concrete next step |

## skilltest record

The bridge between the 2 suites: it runs a single live trial of a skill's task, writes the transcript (redacted) to the skill's configured `deterministic.transcript` path (`fixtures/transcript.jsonl` when unset), then asserts the contract against the file it wrote - so "passes record" means "passes the deterministic transcript gate". The workflow is deliberate: change the skill, run `skilltest record --skill <name>`, review the diff, commit. A recording whose contract fails is still written for inspection, but the command exits `1`, so a fixture that would poison the gate is caught here rather than on the next push. Like `llm` it spends tokens and needs an authenticated agent, and an existing fixture is never overwritten without `--force` (that's an error, exit `2`).

| Flag | Description |
|---|---|
| `--dir <path>` | Repository root (default: current directory) |
| `--skill <name>` | Required: the skill to record (exact name, not a glob) |
| `--task <name>` | Task to record (default: the first declared task) |
| `--model <model>` | Model to record with (default: the repo default) |
| `--force` | Overwrite an existing fixture |

## skilltest mcp-serve

Internal and hidden: it serves a single MCP mock over stdio, and exists so each live trial's MCP configuration has a real server process to launch. It takes the mock server definition file skilltest writes for the trial as its only argument, and exits `2` when that file is missing or isn't a valid mock. You never run it by hand.

## skilltest migrate

Checks a single file - an `eval.yaml`, `skilltest.yml`, or `results.json`, passed as the only argument - against the current schema and rewrites it when it comes from an older major version. A file already at the current major is reported as current and left untouched; a missing or malformed file, or a file from a newer major the tool can't read, exits `2`. Every other command rejects a foreign-major file and points here, so a repo carrying a stale config has a supported path forward.

## skilltest self-update

Downloads the latest release, verifies its SHA-256 checksum against the published checksums file, and replaces the current executable after confirmation, so a corrupt or tampered download can never be installed. It runs only from an installed PHAR - a source checkout has nothing to replace and exits `2` - and it never runs implicitly. Already being up to date, or declining the prompt, exits `0`; a failed download or a checksum mismatch exits `1` and leaves the executable unchanged.

| Flag | Description |
|---|---|
| `--yes` | Skip the confirmation prompt (for scripts) |

## skilltest cache

Manages the llm result cache that `llm --cache` reads and writes under `.skilltest/cache/`. The only action is `clear`: `skilltest cache clear` removes every cached trial result so the next `--cache` run re-executes from scratch. A content change already invalidates a single task's entry, so this is the wholesale escape hatch.

| Flag | Description |
|---|---|
| `--dir <path>` | Repository root (default: current directory) |

## skilltest grade

Re-grades offline, without executing an agent - grading is a pure function of a transcript and a contract. It has 2 mutually exclusive modes: `--transcript <file>` with `--skill <name>` asserts that skill's contract against any transcript, reaching the same verdict the deterministic transcript group would; `--results <file>` re-scores every trial in a saved run against the current contract, so a tightened rule shows exactly which trials it would now fail. Both are token-free; only `--judge` spends tokens, re-running the rubric against each trial's stored transcript.

| Flag | Description |
|---|---|
| `--dir <path>` | Repository root (default: current directory) |
| `--transcript <file>` | Assert a skill's contract against this transcript file |
| `--skill <name>` | The skill whose contract to assert (required with `--transcript`) |
| `--results <file>` | Re-score this saved `results.json` against the current contract |
| `--judge` | Re-run the judge when re-scoring (spends tokens; needs an authenticated agent) |
| `--json` | Emit the machine-readable result on stdout and nothing else |

## skilltest gate

Compares a current `results.json` against a committed baseline and fails on regression - the nightly counterpart to the free push gate. It never spends a token; it reads 2 already-produced files and applies policy: the aggregate pass rate may not drop by more than `--max-regression`, a task marked `golden: true` in its `eval.yaml` must keep passing regardless of the aggregate, a skill's minimal model may not climb the ladder, and the task set may not drift beyond the allow/warn/fail policies. Golden tasks are read from the repo in `--dir` on a best-effort basis, so the command still works as a pure 2-file comparison when no repo is present.

| Flag | Description |
|---|---|
| `--current <file>` | Required: the current `results.json` to gate |
| `--baseline <file>` | Required: the committed baseline `results.json` to compare against |
| `--max-regression <pts>` | Tolerated aggregate pass-rate drop, in percentage points (default: 0) |
| `--on-new-tasks <policy>` | Policy for tasks new since the baseline: `allow`, `warn`, or `fail` (default: `warn`) |
| `--on-removed-tasks <policy>` | Policy for tasks removed since the baseline: `allow`, `warn`, or `fail` (default: `warn`) |
| `--format <name>` | Output format: `human`, `json`, `markdown`, or `github-actions` (default: `human`) |
| `--dir <path>` | Repository root, for reading golden tasks from `eval.yaml` (default: current directory) |

## skilltest compare

Puts 2 or more `results.json` files side by side, passed as arguments with the first as the baseline every delta is measured against: per-task, per-model, and aggregate deltas across pass rate, checks, failures, trials, tokens, cost, and duration. This is how 2 branches, 2 skill revisions, or 2 models are compared outside the matrix. Diagnosis, not policy: it never decides whether a change is acceptable (that's `gate`), so it exits `0` whenever every file loads, and only a bad argument or an unreadable or incompatible file exits `2`.

| Flag | Description |
|---|---|
| `--format <name>` | Output format: `table` or `json` (default: `table`) |

## skilltest report

Renders a saved `results.json`, passed as the only argument. By default it prints a terminal summary; `--html <file>` writes a single self-contained HTML report instead - no server, no external assets - with the path reported to stderr and stdout left clean. `--interpret` adds a plain-language reading of the numbers, printed after the terminal summary or embedded in the HTML page. A renderer, not a gate: it exits `0` whatever the saved run said, and only an unreadable or incompatible file exits `2`.

| Flag | Description |
|---|---|
| `--html <file>` | Write a single self-contained HTML report to this file instead of the terminal summary |
| `--interpret` | Add a plain-language reading of the numbers: the top failure and a concrete next step |

The model, trial, threshold, and environment overrides above shadow the same settings in `skilltest.yml` and `eval.yaml`; [configuration](config.md) covers the files, discovery, and precedence, and [reporting](reporting.md) covers the results document these commands read, write, and gate on.
