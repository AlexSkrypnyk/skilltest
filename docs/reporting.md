# Results and reporting

Every skilltest run lands in one place: a versioned results document. The terminal summary, `--json`, the JUnit reporter, the PR comment format, the HTML report, `compare`, and `gate` all read that same document, so a number you see in one output matches every other output. This page documents the document itself, the reporters that render it, how runs are persisted to disk, and the 4 commands that consume a saved run: `report`, `compare`, `grade`, and `gate`.

## The results document

A run's machine-readable outcome is a JSON object, written as `results.json` when persisted. Its formal contract is the JSON Schema committed at `schema/results.schema.json`. The schema enforces the required invariants - `version`, `tool`, `run`, `skills`, and `totals` at the top level, plus `check` and `pass` on every check row - and permits unknown keys everywhere, so a same-major minor schema bump never breaks a reader.

The current schema version is `"1"`. The `version` field carries MAJOR or MAJOR.MINOR form (`"1"`, `"1.2"`), and a missing version reads as the current one. A document declaring a different major is rejected by every consuming command with exit code 2 and a pointer to `skilltest migrate` - a stale baseline is never silently misread.

Here's the full shape. A deterministic `run` fills `deterministic`, `hooks`, and `coverage`; a live `llm` or `matrix` run fills `llm` per skill and writes empty `hooks` and `coverage` blocks.

```json
{
  "version": "1",
  "tool": {"name": "skilltest", "version": "1.2.0"},
  "run": {
    "id": "st-20260902-143502",
    "started": "2026-09-02T14:35:02+00:00",
    "duration_ms": 84213,
    "command": "matrix",
    "environment": "host"
  },
  "skills": [
    {
      "skill": "run-broker-workflow",
      "path": "skills/run-broker-workflow",
      "deterministic": {
        "structure": [
          {
            "check": "structure.frontmatter",
            "skill": "run-broker-workflow",
            "status": "pass",
            "message": "",
            "file": "skills/run-broker-workflow/SKILL.md",
            "line": 0,
            "evidence": "",
            "reason": "",
            "pass": true
          }
        ],
        "security": [],
        "transcript": [
          {
            "check": "contract.commands.required",
            "label": "broker drives the workflow",
            "pass": true,
            "evidence": "php bin/broker workflow start --terse",
            "message": ""
          }
        ]
      },
      "llm": {
        "tasks": [
          {
            "task": "invoked",
            "models": [
              {
                "model": "claude-haiku-4-5",
                "alias": "haiku",
                "trials": [
                  {
                    "trial": 1,
                    "pass": false,
                    "cached": false,
                    "contract": [
                      {
                        "check": "contract.commands.required",
                        "label": "broker drives the workflow",
                        "pass": false,
                        "evidence": "",
                        "message": "required command was not run"
                      }
                    ],
                    "judge": [
                      {"criterion": 1, "pass": true, "unknown": false},
                      {"criterion": 2, "pass": false, "unknown": false}
                    ],
                    "unknowns": 0,
                    "judge_model": "claude-sonnet-4-5",
                    "duration_ms": 41230,
                    "turns": 6,
                    "tokens": {"in": 18023, "out": 2210},
                    "cost_usd": 0.0179,
                    "transcript": "artifacts/run-broker-workflow__invoked__haiku__t1.jsonl",
                    "mocks": ["artifacts/run-broker-workflow__invoked__haiku__t1__mock-github.jsonl"],
                    "responder": {"outcome": "completed", "followups": 2}
                  }
                ],
                "pass_rate": 0.33
              }
            ]
          }
        ],
        "verdict": {"minimal_model": "sonnet", "threshold": 0.8, "trials": 3}
      }
    }
  ],
  "hooks": [
    {
      "check": "hooks.reject-force-push",
      "label": "blocks a force push",
      "pass": true,
      "evidence": "",
      "message": ""
    }
  ],
  "coverage": {"violations": []},
  "totals": {
    "checks": 6,
    "failures": 1,
    "trials": 3,
    "tokens": {"in": 54069, "out": 6630},
    "cost_usd": 0.0537
  }
}
```

Field by field:

| Block | Fields |
|---|---|
| `tool` | `name`, `version` - what wrote the document |
| `run` | `id` (`st-` plus a `Ymd-His` timestamp), `started` (ISO 8601), `duration_ms`, `command` (`run`, `llm`, or `matrix`), `environment` (`host` or `docker`) |
| `skills[]` | `skill`, `path`, then `deterministic` and/or `llm` |
| `skills[].deterministic` | `structure`, `security`, `transcript` - each an array of check rows for that skill |
| `skills[].llm` | `tasks` and the skill-level `verdict` |
| `llm.tasks[]` | `task` (the task name) and `models` in ladder order |
| `models[]` | `model` (the resolved id), `alias` (the configured name), `trials`, and `pass_rate` (the fraction of trials that passed, rounded to 2 decimal places) |
| `trials[]` | `trial` (1-based), `pass`, `cached` (replayed from the trial cache), `contract` (check rows graded against the live transcript), `judge` (per-criterion rows: `criterion`, `pass`, `unknown`), `unknowns`, `judge_model` (the pinned judge model id, `null` when the skill declares no rubric), `duration_ms`, `turns`, `tokens.in`/`tokens.out`, `cost_usd`, `transcript` (relative artifact path), `mocks` (relative paths of MCP mock call logs), and `responder` for interactive tasks only (`outcome`: `completed`, `abstained`, `cap-exhausted`, or `error`; `followups`: replies sent) |
| `llm.verdict` | `minimal_model` (the weakest ladder model that passed every task, `null` when none did), `threshold`, `trials` |
| `hooks[]` | Repo-level hook case rows - hooks run once per repo, so they sit beside `skills`, not inside one |
| `coverage.violations[]` | One failing row per skill missing an `eval.yaml` without an exclusion, under the check id `coverage.eval-exists` |
| `totals` | `checks`, `failures`, `trials`, `tokens.in`/`tokens.out`, `cost_usd` |

Every check row carries at least `check` (the stable id) and `pass`; failure detail travels alongside as `label`, `evidence`, and `message`, so a red report is debuggable without re-running. Some producers add group-specific keys the schema deliberately tolerates: structure rows add `skill`, `status`, `file`, `line`, and `reason`; security rows add `file`, `line`, and `description`; coverage rows add `skill`, `path`, `eval`, `transcript`, `tasks`, `excluded`, and `reason`.

2 more properties of the document are worth knowing:

- **Transcripts are artifacts, referenced not embedded.** Each trial's transcript and mock logs are separate files written beside the results file; the JSON links to them by path relative to the run directory. Nothing is inlined.
- **`totals.checks` counts different units per command.** For `run` it's every deterministic check, hook case, and coverage violation. For `llm` and `matrix` it's the number of task-on-model verdicts (with `trials`, `tokens`, and `cost_usd` summed across trials). A deterministic run records 0 trials, 0 tokens, and $0.

## What the numbers mean

- A model's `pass_rate` is the fraction of its trials that passed: 2 passing trials out of 3 is 0.67. There are no retries to soften a flaky run.
- A model meets the bar when its pass rate reaches the skill's threshold (0.8 by default, configurable per skill - see [configuration](config.md)). The gate and `grade` recompute this verdict from the raw trials, never from the rounded stored rate.
- A task passes only when it ran on at least one model and every model met the threshold.
- The `minimal_model` verdict is the weakest model, in ladder order, that passed every one of the skill's tasks - the answer the [matrix](models.md) exists to find.
- The aggregate pass rate shown by `report` and `compare` comes from `totals`: `(checks - failures) / checks`. The gate instead recomputes its aggregate rate across every individual check row and llm trial in the document, so its regression math is unaffected by how a producer counted its totals.

## Terminal output

The default output is a human summary, structured the same way across commands:

- `run` prints one status line per skill per group (`my-skill structure PASS (6 check(s))`), expands each failure with its check id, message, and evidence, then closes with a totals line: `12 check(s) across 3 skill(s): 11 passed, 1 failed, 0 suppressed.` Under `--quiet` only the failure lines print, so a green run is silent.
- `llm` prints one verdict line per task-on-model (`my-skill invoked haiku FAIL (pass_rate 0.33, 1/3 trials)`), expands each failed trial's failing checks, then a totals line with verdicts, trials, tokens, and cost. Fully cached verdicts are tagged `(cached)`.
- `matrix` prints a per-skill grid (model, trials, contract, judge, pass rate, verdict), the minimal-model line, failure modes for failing models, a cost comparison against the default model, a repo-wide grid when more than one skill ran, and cost totals. `--format markdown` renders the same content as pipe tables for pasting into a PR or doc; a 1-trial verdict is labeled an estimate.

`--interpret` (on `run`, `llm`, `matrix`, and `report`) appends one plain-language paragraph: what failed, ranked by what to fix first (security, then structure, transcript, hooks, coverage, and llm last), and a concrete next step. It's templated from the document and spends no tokens. On a green run it confirms the result and names the price when tokens were spent.

## Reporters

| Reporter | Commands | What it produces |
|---|---|---|
| `--json` | `run`, `llm`, `matrix`, `grade` | The results document on stdout as a single JSON line, and nothing else |
| `--output <file>` | `run`, `llm`, `matrix` | The results document persisted to one file, pretty-printed |
| `--output-dir <dir>` | `run`, `llm`, `matrix` | A timestamped run directory: `results.json` plus artifacts |
| `--reporter junit:<path>` | `run` (repeatable) | JUnit XML, so any CI renders skilltest natively |
| `--format github-comment` | `run` | A markdown block for PR comments on stdout |
| `--session-log` with `--session-dir <dir>` | `run` | An ordered NDJSON event stream |
| `skilltest report <file> --html <out>` | `report` | A single self-contained HTML page |

`--json` and `--format github-comment` are mutually exclusive - stdout carries exactly one format. Under `--json`, a configuration error is also machine-readable: `{"ok": false, "skills": [], "errors": [...]}` on stdout with exit code 2.

### JUnit XML

`--reporter junit:<path>` writes one `<testsuite>` per skill, plus a `hooks` suite and a `coverage` suite when those produced rows (empty suites are omitted). Every deterministic check and every llm trial becomes a `<testcase>`: check cases are named by their check id with the group in `classname` (`my-skill.structure`), trial cases are named `<task>.<alias>.trial-<n>` under `<skill>.llm` with the trial duration in seconds. A failed case carries a `<failure>` element whose message and body hold the check id, label, evidence, and message, so the CI failure view is debuggable without the original run. The exact format is described by the XSD committed at `schema/junit.xsd`.

### GitHub PR comments

`--format github-comment` renders a `### skilltest results` block: a status line naming the run, a metric summary table, a `#### Failures` list when anything failed, and a `#### Matrix` section per skill that carried llm results, with the minimal-model verdict and the task-by-model grid. The body is capped at GitHub's 65536-character comment limit; when it would overflow, it's truncated with a note saying so, so the comment always posts.

### Session log

`--session-log --session-dir <dir>` writes `<dir>/<run-id>.ndjson`, one event per line: `run.started`, then a `check.finished` per deterministic check, a `task.started` and `trial.finished` per llm task and trial, a `hook.executed` per hook case, a `grading.finished` for the coverage gate, and `run.finished`. Every event carries a monotonic `seq` - the authoritative ordering - and a `ts`. The deterministic suite doesn't time each check individually, so intermediate events carry the run's start timestamp; only the boundary events are stamped with the true start and end.

## Persisting runs to disk

`--output <file>` writes the results document to that one file, creating missing parent directories. This is the shape you commit as a `gate` baseline.

`--output-dir <dir>` writes a run directory instead: a subdirectory named with a UTC `Ymd-His` timestamp, holding `results.json` beside its artifacts. For a live run, each trial's transcript and mock call logs land under `artifacts/`, named `<skill>__<task>__<model-alias>__t<n>.jsonl` and `<skill>__<task>__<model-alias>__t<n>__mock-<server>.jsonl` (unsafe characters collapsed to hyphens):

```
runs/
└── 20260902-143502/
    ├── results.json
    └── artifacts/
        ├── run-broker-workflow__invoked__haiku__t1.jsonl
        ├── run-broker-workflow__invoked__haiku__t1__mock-github.jsonl
        └── run-broker-workflow__invoked__sonnet__t1.jsonl
```

The document references each artifact by that relative path, so the run directory is self-contained: move it anywhere and `skilltest report`, `compare`, and `grade --results` still resolve the transcripts. A deterministic `run` has no trial artifacts, so its run directory holds only `results.json`. Passing both flags writes both layouts.

## Redaction

Redaction is on by default. Before anything is persisted or published, the value of every credential-bearing environment variable is replaced with `[REDACTED]` wherever it appears verbatim - in the results document's string values, in every transcript and mock log, in JUnit files, in session logs, and in the github-comment output. A variable counts as credential-bearing when its name contains a delimited credential word - `KEY`, `TOKEN`, `SECRET`, `PASSWORD`, `PASSPHRASE`, `CREDENTIAL`, or `CREDENTIALS` (case-insensitive, delimited by underscores or the name's edges) - which catches `ANTHROPIC_API_KEY` and `CLAUDE_CODE_OAUTH_TOKEN` without matching incidental names. Values shorter than 4 characters are never treated as secrets, since replacing a 1-character value everywhere would corrupt legitimate content.

Plain `--json` on stdout is the one exception: it's a local debugging convenience, not a persisted artifact, and is not redacted.

For local debugging of the redaction itself, `report.redact: false` in `skilltest.yml` turns it off - and warns loudly on stderr whenever an external artifact is written: `WARNING redaction disabled (report.redact: false); environment secrets may be written to persisted artifacts.`

## Rendering a saved run: `skilltest report`

`skilltest report <results.json>` renders a saved document without re-running anything: a PASS/FAIL status line naming the run, the headline totals, the ordered failure list with evidence, and - when the run carried llm results - each skill's task-by-model grid, minimal-model verdict, and cost totals.

- `--html <file>` writes a single self-contained HTML page instead: run summary, a per-skill drill-down to each check's evidence (failing skills open by default), the matrix grids, and the cost table. The stylesheet is inlined and the page references no external asset - no script, no CDN, no font - so it opens straight from `file://`, renders offline, and adapts to light and dark mode. The path is reported to stderr; stdout stays clean.
- `--interpret` adds the plain-language paragraph - printed after the terminal summary, or embedded in the HTML page.

`report` is a renderer, not a gate: it exits 0 whatever the saved run said, and 2 only when the file is missing, malformed, or a foreign schema major.

## Comparing runs: `skilltest compare`

`skilltest compare <a.json> <b.json> [more...]` lines up 2 or more results files side by side. The first file is the baseline every delta is measured against; each file is labeled by its filename (a `#<position>` suffix disambiguates duplicates). 3 sections print as aligned tables:

- **aggregate** - always: `pass_rate`, `checks`, `failures`, `trials`, `tokens_in`, `tokens_out`, `cost_usd`, `duration_ms`.
- **models** - when the runs carried llm results: `pass_rate` and `cost_usd` per model alias.
- **tasks** - the per-task pass rate, keyed `skill::task::alias`.

Each row shows the value from every file and a signed baseline-to-latest delta. A metric absent from one side - a task that exists in only one run - shows a dash rather than a fabricated zero, so "new" never reads as "unchanged". `--format json` emits the same structure as one JSON line (`{"compare": true, "labels": [...], "aggregate": {...}, "models": {...}, "tasks": {...}}`, each metric carrying `values` and `delta`).

`compare` is diagnosis, not policy: it exits 0 whenever every file loaded, and 2 only on a bad argument or an unreadable or incompatible file. Deciding whether a change is acceptable is the gate's job.

## The regression gate: `skilltest gate`

`skilltest gate --current <results.json> --baseline <results.json>` compares a fresh run against a committed baseline and applies policy. It never spends a token - both inputs are already-produced documents. 4 independent checks feed one verdict:

- **Aggregate regression** (`--max-regression <points>`, default 0): the gate fails when the overall pass rate - recomputed across every check and trial in each document - drops by more than the tolerated number of percentage points.
- **Golden tasks**: a task marked `golden: true` in its `eval.yaml` must pass in the current run, full stop. A golden task that fails or is absent fails the gate regardless of the aggregate math. Golden tasks are read from the repo config under `--dir` (default: the current directory) on a best-effort basis - if the config won't load, the gate warns and still compares the 2 files.
- **Minimal-model hold**: a skill whose minimal model climbed the ladder since the baseline (haiku yesterday, sonnet today) fails the gate. A cost regression is a decision, not an accident.
- **Task-set drift**: tasks added since the baseline and tasks removed since the baseline each get a policy via `--on-new-tasks` and `--on-removed-tasks`: `allow` (silent), `warn` (surfaced, doesn't fail - the default), or `fail`. A suite can't silently shrink its way to green.

Each finding carries a severity (`FAIL` or `WARN`) and a category (`regression`, `golden`, `minimal-model`, `new-task`, `removed-task`). The gate fails the moment any finding fails; a warnings-only run passes. Exit codes follow the tool contract: 0 pass, 1 fail, 2 configuration error (a bad flag, a missing file, a foreign schema major).

`--format` picks the output shape: `human` (a terse verdict, the rate line, and the findings), `json` (the verdict, both rates, the drop, and structured findings), `markdown` (a PR-comment block with a findings table), or `github-actions` (one `::error`/`::warning` workflow annotation per finding plus a closing `::notice` summary, surfaced inline on the workflow run).

## Offline re-grading: `skilltest grade`

Grading is a pure function of a transcript and a contract, so it can run long after the run that produced the transcript. `grade` has 2 modes, both token-free by default:

- `--transcript <file> --skill <name>` asserts the named skill's current contract and custom checks against any transcript file - the same verdict the deterministic transcript group reaches, on a file of your choosing. Exit 0 when the contract holds, 1 when it doesn't.
- `--results <file>` re-scores every trial in a saved run against the current contract: each trial's own transcript (resolved relative to the results file's directory, which is why a `--output-dir` run directory works as-is) is re-graded, per-model pass rates and each minimal-model verdict are rebuilt, and the failure total is recomputed so the document stays internally consistent. The summary names how many trials were re-scored and how many flipped each way - a tightened contract shows exactly which trials it would now fail. Runtime-only failures a transcript can't reproduce offline (a non-zero agent exit, a mock miss, a responder abstention - the `live.*` checks) are preserved from the saved verdict, so re-grading never resurrects a trial that failed for a reason the offline evidence doesn't carry.

By default the judge dimension is reused from the saved verdict. `--judge` re-runs the rubric against each trial's stored transcript instead - the one part of `grade` that spends tokens and needs an authenticated agent. `--json` emits the machine-readable result: the check rows in transcript mode, the full re-scored document in results mode. Exit codes: 0 when everything passes after re-scoring, 1 when a check or task-on-model verdict fails, 2 on configuration errors.

## Putting it together in CI

The layering these pieces are built for: the deterministic `run` gates every push for free; a scheduled `llm` or `matrix` run persists `results.json` as an artifact (and posts a summary with `--format github-comment` or a JUnit file); `gate` compares that scheduled run against the baseline committed in the repo and fails the job - or annotates the workflow - on regression. Tokens are spent nightly, not per push.

For what the deterministic checks actually assert, see [deterministic checks](checks-deterministic.md); for how trials, judges, and thresholds are configured, see the [llm suite](checks-llm.md) and [configuration](config.md); for the model ladder behind the matrix, see [models](models.md).
