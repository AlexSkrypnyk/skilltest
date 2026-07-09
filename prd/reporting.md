# Results and reporting

One results schema feeds everything: terminal output, JSON, JUnit, PR comments, the HTML report, `compare`, and `gate`. If a number appears in any report, it exists in `results.json`; renderers never compute their own truths.

## `results.json`

Schema-versioned (`version: "1"`, same policy as the config files, migratable with `skilltest migrate`). A JSON Schema is committed at `schema/results.schema.json`; every persisted document validates against it, and the required invariants (`version`, `tool`, `run`, per-check `check`+`pass`, `totals`) are enforced while unknown keys are permitted so a same-major minor bump is never fatal. Shape, abridged:

```json
{
  "version": "1",
  "tool": {"name": "skilltest", "version": "1.0.0"},
  "run": {"id": "st-20260708-1432", "started": "2026-07-08T14:32:00+00:00", "duration_ms": 84213, "command": "matrix", "environment": "docker"},
  "skills": [
    {
      "skill": "run-harness-workflow",
      "path": "skills/run-harness-workflow",
      "deterministic": {
        "structure": [{"check": "structure.frontmatter", "pass": true}],
        "security": [],
        "transcript": [{"check": "contract.commands.required", "label": "harness drives the workflow", "pass": true, "evidence": "php bin/harness workflow start --terse"}]
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
                  {"trial": 1, "pass": false, "contract": [], "judge": [{"criterion": 1, "pass": true}, {"criterion": 2, "pass": false}], "unknowns": 0, "duration_ms": 0, "turns": 0, "tokens": {"in": 0, "out": 0}, "cost_usd": 0.0, "transcript": "artifacts/haiku-1.jsonl"}
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
  "hooks": [{"check": "hooks.reject-gh-pr-create", "label": "blocks gh pr create", "pass": true, "evidence": "", "message": ""}],
  "coverage": {"violations": []},
  "totals": {"checks": 0, "failures": 0, "trials": 0, "tokens": {"in": 0, "out": 0}, "cost_usd": 0.0}
}
```

Rules:

- **Repo-level results live at the top, per-skill results nest under the skill.** `hooks` run once for the whole repo and the coverage gate is a repo property, so both sit beside `skills` rather than inside any one skill; `deterministic` under each skill carries only that skill's `structure`, `security`, and `transcript` groups.
- **Evidence travels with failures.** Every failed check carries the matched (or missing) evidence so a report is debuggable without re-running.
- **Transcripts are artifacts, referenced not embedded.** Each trial's transcript is written beside the results file under `--output-dir` and the JSON links to it by relative path; nothing is inlined.
- **Redaction is on by default.** Environment secret values (credential-named variables such as `ANTHROPIC_API_KEY` and `CLAUDE_CODE_OAUTH_TOKEN`) are scrubbed from every persisted artifact (results, transcripts, session logs) before writing; `report.redact: false` exists for local debugging and warns loudly on stderr.
- **Statistics (P2):** per-task pass@k and pass^k, per-criterion pass rates across trials, and baseline deltas when `--baseline` ran.

## Terminal output

Default: one status line per skill per group, failures expanded with check id, label, evidence, and fix direction, then a totals block. The matrix adds the per-model grid and the minimal-model verdict (`models.md`). `--interpret` (P2) appends a plain-language paragraph: what failed, what it means, what to do first - written for the skill author, not the tool author.

## Reporters

| Reporter | Output | Priority |
|---|---|---|
| `--json` | Full `results.json` to stdout | P1 |
| `--output <file>` / `--output-dir <dir>` | Persist results (+ artifacts) to disk; `--output-dir` timestamps per run | P1 |
| `--reporter junit:<path>` | JUnit XML: one test case per check / per trial, so any CI system renders skilltest natively | P2 |
| `--format github-comment` | Markdown block for PR comments: summary table + failures + matrix grid when present | P2 |
| `skilltest report --html <file>` | Single self-contained HTML file (inline CSS/JS, no server, no CDN): run summary, per-skill drill-down, matrix grid, cost totals | P2 |
| Session log (`--session-log`, NDJSON) | Ordered per-run event stream (task started, trial finished, hook ran) for tooling and debugging | P2 |

## `skilltest gate` (P2)

The regression gate compares a current `results.json` against a committed baseline and applies policy:

- **Aggregate regression**: fail when the overall pass rate drops more than `--max-regression <pct>`.
- **Golden tasks**: tasks marked `golden: true` in `eval.yaml` must pass in the current run, full stop; a golden failure outranks the regression math.
- **Task-set drift**: added and removed tasks each get a policy (`allow`, `warn`, `fail`), so a suite cannot silently shrink its way to green.
- **Minimal-model hold**: with matrix baselines, a skill whose minimal model climbs the ladder (Haiku yesterday, Sonnet today) trips the gate - cost regressions are decisions, not accidents.

Exit codes mirror the tool contract: `0` pass, `1` regression or golden failure, `2` config error. Formats: `human`, `json`, `markdown`, `github-actions` (inline annotations).

The intended CI shape: the deterministic suite gates every push for free; a scheduled llm/matrix run writes `results.json` as an artifact; `gate` compares it against the baseline stored in the repo and fails the scheduled run (or files a PR comment) on regression - tokens are spent nightly, not per push.

## `skilltest compare` (P2)

Two or more results files side by side: per-task, per-model, and aggregate deltas (pass rate, duration, tokens, cost), rendered as a table or JSON. `compare` is diagnosis (what changed between these runs); `gate` is policy (is that change acceptable). They share all their arithmetic.
