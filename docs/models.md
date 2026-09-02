# Multi-model testing

What is the smallest model this skill actually works on? A skill that needs Opus costs an order of magnitude more per run than one that holds up on Haiku, and nothing in the skill's prose tells you which you have. `skilltest matrix` makes that an empirical, repeatable measurement: it runs a skill's llm tasks across an ordered ladder of models and reports the minimal model that supports it - and since a full matrix is skills x tasks x trials x models real agent runs, it also prices itself with `--estimate` before you commit. The trials themselves - workspaces, grading, the judge - belong to [the llm suite](checks-llm.md); this page covers the ladder, the matrix, and the verdict.

## Aliases and the ladder

The `models:` block in `skilltest.yml` declares your model aliases and an ordered ladder, weakest first:

```yaml
models:
  aliases:
    haiku: claude-haiku-4-5
    sonnet: claude-sonnet-5
    opus: claude-opus-4-8
  ladder: [haiku, sonnet, opus]
  default: sonnet
  judge: haiku
```

There are no built-in aliases and no built-in ladder: the map is entirely yours, and skilltest ships no opinion about which model ids exist. An alias maps a stable name to a model id, so `eval.yaml` files keep saying `haiku` while bumping the fleet to a new generation is a 1-line change here. The ladder's order is your cost ordering, weakest first - configuration, not hardcoded knowledge, so private deployments and future models slot in without a tool release.

- `ladder`, `default`, and `judge` must name defined aliases; an undefined name in any of them is a validation error.
- Model tokens from `--models` or a skill's `llm.models` pass through the alias map: a defined alias resolves to its id, and any other token is handed to `claude --model` verbatim - so a raw model id works anywhere an alias does.
- Reports label rows by alias and record the resolved id beside it.

A skill's model list resolves in this order: `--models` (comma-separated aliases or ids, or the word `ladder`), else the skill's `llm.models` (the word `ladder`, a list, or a comma string), else the repo ladder, else `models.default` alone. A skill with llm tasks and no resolvable model is a configuration error. The `models:` block and the rest of `skilltest.yml` are covered in [configuration](config.md).

The judge model is deliberately separate: `--judge-model`, else `models.judge`, else the ladder's weakest entry, else `models.default`. It stays pinned across the whole matrix, so scores are comparable between rows - the variable under test is the execution model, never the grader. [The llm suite](checks-llm.md) covers the judge itself.

## Running the matrix

`skilltest matrix` runs every selected skill's llm tasks across its resolved models, weakest first, with 3 trials per task per model by default (`llm.trials` in `eval.yaml` or `--trials` override it). A model's whole row - every task - completes before the climb moves up. There are 2 cost modes:

- **Full matrix** (default): every model runs, producing the complete grid. This is the mode for reports and regression tracking, and it also catches the inverse surprise - a skill that passes on Haiku but trips on Opus because the stronger model takes liberties.
- **`--stop-at-pass`**: climb from the weakest model and stop, per skill, at the first model that passes every task. The stronger rows are never paid for; you get the minimal-model answer and nothing else.

Like every live run, the matrix needs the `claude` binary and credentials, and spends real tokens on every trial; the preflight and the per-trial timeout are described in [the llm suite](checks-llm.md). It differs from `skilltest llm` in 2 ways: the matrix is a report, not a gate - it exits `0` whatever the verdicts say (`2` on a configuration error) - and it runs on the host only; `--env docker` is rejected. There's also no trial cache here: matrix trials always run live.

## The verdict

A model supports a skill when every task's pass rate on that model - passing trials divided by trials, compared at full precision - is at least the threshold (default 0.8). The minimal model is the first supporting model in ladder order. A skill no model supports gets a null verdict rather than a pretend answer. Per skill, the report prints a grid and the verdict:

```
run-broker-workflow
  model   trials  contract  judge  pass rate  verdict
  haiku   3       2/3       1/3    0.33       fail
  sonnet  3       3/3       3/3    1.00       pass
  opus    3       3/3       3/3    1.00       pass

  minimal model: sonnet (threshold 0.80, 3 trials)

  haiku failure modes: contract: contract.commands.required (2x); judge: Runs the workflow steps in the declared order. (2x)
  minimal sonnet $0.0812/run vs default opus $0.2100/run (saves $0.1288/run)
```

What that output encodes:

- **Failure modes, not just rates.** For each failing model, the report tallies the failed contract checks by check id and the blocking judge criteria by their rubric text, with counts, most frequent first (ties break alphabetically, so the ranking is stable between runs). "Stops calling the broker after 2 steps (2x)" is actionable; "0.33" alone is not.
- **Contract and judge stay separate.** The contract column counts trials whose non-judge checks all passed; the judge column counts trials the judge scored without blocking, and shows a dash when the skill declares no rubric. A model that obeys the contract but writes output the judge rejects is a different problem from one that goes off-contract.
- **The verdict is per task, not the aggregate.** The pass rate cell folds all tasks' trials together for reading, but pass or fail comes from every task meeting its own threshold - so a single weak task fails a model whose aggregate rate looks fine.
- **Stability is labeled.** The verdict line always states the threshold and the trial count, and a 1-trial verdict is explicitly labeled an estimate. `--trials 10` exists for when the answer matters.
- **The economic punchline.** Each skill ends with the minimal model's measured per-run cost against the repo default's, from the same run - the number that justifies the whole exercise. When no default is configured, or the default didn't run in this matrix, the line says so instead of inventing a figure.

## The repo grid

When more than 1 skill ran, the report adds the repo-level grid - the answer to "which of my skills are Haiku-safe":

```
all skills
  skill                haiku  sonnet  opus  minimal
  run-broker-workflow  0.33   1.00    1.00  sonnet
  init-broker-project  1.00   1.00    1.00  haiku
  resolve-reviews      0.00   0.67    1.00  opus
```

The columns are the models that actually ran, in ladder order. A skill that stopped early under `--stop-at-pass`, or ran a narrower model list, shows a dash in the columns it never ran instead of forcing a run it never made; a dash in the minimal column means no model supported the skill. Every report ends with the spend, summed from what each trial's agent run reported:

```
cost per model: haiku $0.1204, sonnet $0.2436, opus $0.6300. total $0.9940.
```

## Estimating before running

`--estimate` prints the plan and exits without running anything - no agent, no credentials, no tokens:

```
matrix plan (nothing runs with --estimate):
  skill                tasks  models  trials  total
  run-broker-workflow  2      3       3       18
  total trials: 18
  rough cost: ~$0.90 (nominal $0.05/trial; actual cost is measured per run)
```

The $0.05 per trial is a deliberately round planning figure, not a quote - no pre-run token count exists. The real number is the measured total the report prints after an actual run. With `--json`, the estimate emits `{"estimate": true, "skills": [...], "total_trials": N, "rough_cost_usd": X}` instead.

## Output formats

- The terminal table is the default; `--format markdown` emits the same content as headings and pipe tables, ready to paste into a PR or a doc.
- `--json` emits the full machine-readable results document - per-trial data included - and nothing else; the schema is documented in [reporting](reporting.md).
- `--output <file>` and `--output-dir <dir>` persist the document, the latter with every transcript and mock log, redacted by default.
- A self-contained HTML report comes from the separate `report` command: persist the run's `results.json`, then run `skilltest report results.json --html matrix.html`. See [reporting](reporting.md).
- `--interpret` appends a plain-language reading: the weakest task and a concrete next step.

## Tracking verdicts over time

A matrix run produces an ordinary `results.json`, so the general machinery applies:

- `skilltest compare old.json new.json` lines runs up and shows the per-task, per-model, and aggregate deltas - "the rewrite made it Haiku-safe" as numbers. It diagnoses; it never gates.
- `skilltest gate --current new.json --baseline old.json` turns the verdict into policy: among its findings, a skill whose minimal model climbed the ladder since the baseline fails the gate. A change that silently pushes a skill from Haiku to Sonnet is a cost regression someone chose, not an accident nobody saw.

Both read saved documents and spend no tokens; [reporting](reporting.md) documents them fully.

## `skilltest matrix` reference

| Flag | Effect |
|---|---|
| `--dir <path>` | Repository root (default: current directory) |
| `--skill <glob>` | Select skills by name; repeatable |
| `--task <glob>` | Select tasks by name; repeatable |
| `--models <list>` | Override the ladder: aliases or ids, comma-separated |
| `--trials <n>` | Trials per model per task (default 3) |
| `--threshold <0..1>` | Override the pass-rate threshold |
| `--env <host>` | Execution environment; docker is not supported here |
| `--parallel <n>` | Concurrent trials (default 1) |
| `--judge-model <m>` | Override the judge model; it never follows the ladder |
| `--stop-at-pass` | Stop climbing at the first supporting model per skill |
| `--estimate` | Print the plan and its rough cost, then exit without running |
| `--format <text\|markdown>` | Human output format (default `text`) |
| `--json` | Emit the results document on stdout and nothing else |
| `--output <file>` | Persist the results document to this file |
| `--output-dir <dir>` | Persist the document plus transcripts to a timestamped subdirectory |
| `--interpret` | Append a plain-language reading of the result |

Next: [the llm suite](checks-llm.md) for how each trial runs and is graded, and [reporting](reporting.md) for the results document these runs produce.
