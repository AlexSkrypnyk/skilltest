# Multi-model testing and the minimal-model report

The question this feature answers, verbatim: **what is the minimal model the skill can run on?** A skill that needs Opus to work costs an order of magnitude more per run than one that works on Haiku, and nothing in a skill's prose tells you which one you have. skilltest makes this an empirical, repeatable measurement with a first-class report.

## The ladder

The repo config declares model aliases and an ordered ladder, weakest first:

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

Aliases keep `eval.yaml` files stable while model ids churn; bumping the fleet to a new generation is a one-file change. The ladder's order is the product's opinion of "smaller"; it is configuration, not hardcoded knowledge, so private deployments and future models slot in without a release. A skill selects `models: ladder` (the common case) or an explicit list.

## Execution

`skilltest matrix` runs every selected skill's llm tasks across the ladder: for each model, `trials` runs per task (default 3), contract plus judge asserted per trial, pass rate per model per task. Two cost modes:

- **Full matrix** (default): every model on the ladder runs, producing the complete grid. This is the mode for reports and regression tracking - it also catches the inverse surprise (a skill that passes on Haiku but trips on Opus because a stronger model takes liberties).
- **`--stop-at-pass`**: climb from the weakest model and stop at the first that meets the threshold. Cheaper; answers only the minimal-model question.

The judge model stays pinned (`models.judge`) across the whole matrix, so scores are comparable between rows: the variable under test is the execution model, never the grader.

## The verdict

A model "supports" a skill when every task's pass rate on that model meets its threshold. The **minimal model** is the first supporting model on the ladder. The verdict is computed per skill and printed as the report's headline:

```
run-harness-workflow
  model     trials   contract   judge   pass rate   verdict
  haiku     3        2/3        1/3     0.33        fail
  sonnet    3        3/3        3/3     1.00        pass
  opus      3        3/3        3/3     1.00        pass

  minimal model: sonnet (threshold 0.8)
  haiku failure modes: skipped `workflow status` after step 2 (2x), judge: decided step order itself (2x)
```

Requirements encoded in that output:

- **Per-model failure modes, not just rates.** For each failing model, the report names the most frequent failed checks and judge criteria with counts. "Haiku fails because it stops calling the broker after two steps" is actionable; "0.33" alone is not.
- **Contract and judge are reported separately.** A model that obeys the contract but writes poor output is a different problem from one that goes off-contract; the columns keep them distinct.
- **Verdict stability.** With `trials: 3` a verdict is an estimate; the report labels the confidence (trials count is always shown) and `--trials 10` exists when the answer matters. skilltest never presents a 1-trial verdict without saying so.

## Aggregate views

Across skills, `skilltest matrix` renders the repo-level grid - the answer to "which of my skills are Haiku-safe":

```
skill                    haiku   sonnet   opus    minimal
run-harness-workflow     0.33    1.00     1.00    sonnet
init-harness-project     1.00    1.00     1.00    haiku
resolve-reviews          0.00    0.67     1.00    opus
```

Output formats: terminal table (default), `--json` (the full per-trial data, schema in `reporting.md`), `--format markdown` for PRs and docs, and the self-contained HTML report (P2) with the grid, per-model failure drill-downs, and cost totals.

## Comparison over time

Matrix results are `results.json` files like any other run, so the general machinery applies: `skilltest compare old.json new.json` shows per-model deltas after a skill edit ("the rewrite made it Haiku-safe"), and `skilltest gate --baseline` (P2) can hold a minimal-model verdict as a regression gate - a PR that silently pushes a skill from Haiku to Sonnet is a cost regression someone chose, not an accident nobody saw.

## Cost reporting

Every trial records model, token usage, duration, and computed cost; the matrix report totals them per model and overall, and estimates the full-matrix price before running (`--estimate` prints the plan - skills x tasks x trials x models - and exits). The minimal-model verdict itself carries the economic punchline: the report ends with the per-run cost difference between the minimal model and the repo default, which is the number that justifies the whole exercise.
