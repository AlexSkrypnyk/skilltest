# The llm suite

The [deterministic suite](checks-deterministic.md) holds a skill's contract against a recorded fixture on every push, for free. The llm suite answers the question a fixture can't: does the skill still behave when a real model drives it? `skilltest llm` runs each task headlessly through Claude Code, grades the live transcript against the same contract, adds an LLM judge for the criteria only judgment can score, and aggregates the verdict over repeated trials.

This suite spends money. Every trial is a real agent run that needs the `claude` binary and real credentials, and it burns real tokens - judge and responder calls burn more on top. It never runs implicitly: it's a separate command, and in CI it belongs in an explicit opt-in step (nightly, pre-release), never the per-push gate. `skilltest matrix` extends the same machinery across a ladder of models; that half of the story is [multi-model testing](models.md).

## Anatomy of a trial

For every selected skill, task, and model, skilltest assembles a fresh workspace, runs the agent in it once, grades the transcript, and tears the workspace down.

1. The workspace is a new directory under `.skilltest/tmp/` in your repo. The task's `fixture` is copied in, each declared repo is materialized as a detached `git worktree` checkout, and the skill under test is installed at `.claude/skills/<name>` inside the workspace so the agent discovers it.
2. The agent runs headlessly in the workspace (or the task's `workdir` subdirectory). The assembled command has this shape, with each flag appearing only when it applies:

```bash
claude -p '<prompt>' --output-format stream-json --verbose \
  --model <resolved-id> --max-turns <llm.max-turns> \
  --allowedTools '<contract tools.allowed>' \
  --mcp-config <trial-config> --strict-mcp-config
```

3. The agent's stdout is captured verbatim as the trial's transcript - the same JSONL stream the deterministic transcript group parses - and graded. The workspace is deleted whether the trial passed, failed, or threw. Pass `--keep-workspace` to preserve every workspace and print its path, when you need to inspect exactly what the agent left behind.

You declare the contract once, and it's enforced twice: deterministically from the fixture on every push, and behaviorally from live runs whenever you choose to pay for them.

### Prerequisites

skilltest looks for an agent and credentials before any trial runs, and exits `2` naming the missing half when either is absent:

- The binary: the first executable `claude` on `PATH`, or whatever the `SKILLTEST_AGENT` environment variable names (a binary or a command prefix - this is also how you wrap or stub the CLI).
- Credentials: `ANTHROPIC_API_KEY`, `CLAUDE_CODE_OAUTH_TOKEN`, or an authenticated Claude Code home (`~/.claude`). skilltest never handles the secret itself; it only forwards your environment to the agent process.

Each trial gets a wall-clock budget of 300 seconds; set `SKILLTEST_TRIAL_TIMEOUT` (in seconds) to change it. A trial that hits the budget is killed and fails with a timeout check. The same budget bounds each judge and responder call.

Trials run on the host by default. Set `llm.environment: docker` in `skilltest.yml`, or pass `--env docker`, to run each trial in a container instead - [environments](environments.md) covers both, along with the `llm.lifecycle` hooks (`before-run`, `before-task`, `after-task`, `after-run`) that bracket llm work with setup and teardown commands.

## Declaring tasks

Tasks live under `llm.tasks` in a skill's `eval.yaml`. `name` and `prompt` are required; the rest shapes the workspace:

```yaml
llm:
  max-turns: 20
  trials: 3
  threshold: 0.8
  tasks:
    - name: happy-path
      prompt: "/broker:run-broker-workflow"
      fixture: fixtures/start-state
      inputs:
        repos:
          - source: fixtures/upstream
            commit: HEAD
            dest: project
        workdir: project
        board: "Team Board"
```

- `fixture` names a file or directory, relative to the skill directory, copied into the workspace before the run.
- `inputs.repos` materializes real git checkouts: `source` (a repo path, relative to the repository root), `commit` (default `HEAD`), and `dest` (a relative path inside the workspace). Each is a detached worktree - cheap, offline, sharing the source repo's object store - and is removed with the workspace.
- `inputs.workdir` starts the agent in that subdirectory instead of the workspace root.
- Every other scalar under `inputs` becomes a `{{ vars.* }}` template variable for lifecycle hooks (here, `{{ vars.board }}`).

`dest` and `workdir` must be relative paths without `..` segments; anything else is a configuration error before a token is spent.

## Trials and pass semantics

Each task runs `trials` times per model: `llm.trials` in `eval.yaml`, overridable with `--trials`, defaulting to 1 for `skilltest llm` and 3 for `skilltest matrix`. A trial passes only when every check it was graded against passed. A task passes on a model when its pass rate - passing trials divided by total trials - is at least the threshold (`llm.threshold` or `--threshold`, default 0.8).

Flake handling is honest by design: there are no automatic retries to hide instability. A task that passes 2 of 3 trials reports a 0.67 pass rate, and whether that fails is the threshold's call. The results document rounds the rate to 2 decimals for display; the verdict compares at full precision.

`skilltest llm` exits `0` when every task-on-model verdict met its threshold, `1` when any missed, and `2` on a configuration error. A passing verdict prints as a single line; a failing one also prints each failed check of each failed trial:

```
broker happy-path sonnet FAIL (pass_rate 0.67, 2/3 trials)
  trial 2 contract.commands.required FAIL - required behaviour 'status' matched no command (pattern: broker status).
```

Which models run is shared with the matrix: `--models` wins, else the skill's `llm.models`, else the repo ladder, else `models.default`. [Multi-model testing](models.md) covers aliases, the ladder, and the minimal-model verdict.

## What grades a trial

Grading folds several sources into a single flat list of checks, and the trial passes only when all of them do:

| Check id | What it means when it fails |
|---|---|
| `contract.*` | A contract assertion (tools, commands, skills) failed against the live transcript |
| `check.<name>` | A custom check script from `llm.checks` failed; the live transcript is staged in the workspace so scripts see it exactly as they see a recorded fixture |
| `live.agent` | The agent process exited non-zero or timed out |
| `live.mcp` | A mocked MCP tool call matched no fixture |
| `live.responder` | The responder abstained or broke |
| `judge.verdict` | The judge process failed or returned an unparseable verdict |
| `judge.criteria` | The judge's verdict blocked under the abstention policy |

Contract semantics and custom check scripts are identical to the [deterministic suite](checks-deterministic.md); the llm suite reuses them against the live transcript.

## The judge

Deterministic checks can't score accuracy, voice, or reasoning. Declaring a rubric brings in the judge - a separate, pinned model that scores each trial's transcript:

```yaml
llm:
  judge:
    rubric:
      - "The final summary names every changed file."
      - "The tone matches the repository's voice guide."
    unknown: fail
```

A few design rules are the difference between a useful judge and a random number:

- **Binary criteria only.** The rubric is a list of independent yes/no statements, each judged pass or fail. There is no holistic 1-100 score, because N small judgments are dramatically lower-variance than 1 big one.
- **Structured verdicts.** The judge must return `{"criteria":[{"id":1,"pass":true,"unknown":false}],"reasoning":"...","unknown":false}`. Parsing is hardened - the first balanced JSON object is extracted from any surrounding prose or fences, and every field is clamped to its type - and the verdict must score exactly criteria 1 through N. A judge that omits, duplicates, or invents a criterion fails the trial as `judge.verdict`, never a silent pass.
- **Abstention is first-class.** The judge returns `unknown: true` for a criterion the evidence doesn't show, instead of guessing. Under `llm.judge.unknown: fail` (the default) an abstention blocks the trial; under `ignore` it doesn't block but is still recorded per criterion in the results. Either way it's never silently a pass; any other value for the key is a validation error.
- **Evidence, not vibes.** The judge sees the task prompt, the responder turns of an interactive run, every tool call with its arguments, and the run's final output - all pulled from the same transcript the contract engine grades.
- **Cheap and pinned.** The judge model resolves from `--judge-model`, else `models.judge`, else the ladder's weakest entry, else `models.default` - never the execution model, so scores stay comparable when the execution model changes. A skill that declares a rubric with no resolvable judge model is a configuration error.

A broken run - a non-zero agent exit, a responder abstention or error - is already a failing trial, so no judge tokens are spent on it. When a verdict blocks, the trial gains a `judge.criteria` failure naming the tally: `the judge passed 1 of 3 criteria (1 unknown).`

Judging costs a model call per judged trial, on top of the trial itself. To re-score saved runs after tightening a contract, `skilltest grade --results <file>` replays grading without an agent and spends nothing; adding `--judge` re-runs the rubric too, which spends tokens again.

## The responder: interactive tasks

A skill that asks follow-up questions can't be tested with a single prompt. Declaring a `responder` on a task makes the trial interactive: a model plays the user, answering from a persona brief you write:

```yaml
- name: interactive-setup
  prompt: "Set up the board worker for this repo."
  responder:
    instructions: |
      You are the repo owner. The board is "Team Board", the label is
      "worker", auto-merge is off. Answer the skill's questions consistently
      with this; abstain if you genuinely cannot infer an answer.
    max-followups: 6
```

`instructions` (non-empty) and `max-followups` (an integer of at least 1) are required. `model` is optional and defaults to the judge model, so a persona costs a cheap model unless the task pins its own.

After each agent turn the responder makes a move: reply (the answer goes back to the agent, which resumes the same session), stop (the agent finished), or abstain (the brief can't answer). How the conversation ended is recorded per trial:

| Outcome | Meaning | Effect on the trial |
|---|---|---|
| `completed` | The responder stopped because the agent finished | The final state is graded normally |
| `cap-exhausted` | `max-followups` replies were sent and the agent kept asking | The final state is graded normally |
| `abstained` | The persona brief was too vague to answer | Fails on `live.responder`; the judge is skipped |
| `error` | The responder process broke or returned an unusable move | Fails on `live.responder`; the judge is skipped |

Every reply is recorded into the transcript as a user turn, so the grader and the judge see the whole dialogue; the contract engine ignores the injected turns. Each task carries its own responder, so a skill can be tested against several personas and configurations. Interactive trials in a batch run one at a time - the conversation loop is stateful - so `--parallel` shortens them less than it shortens single-prompt tasks. Each responder move is an extra model call.

## Hermetic external services: MCP mocks

For skills that call MCP tools, a task can declare `mcp-mocks`. skilltest launches each mock as a local stdio MCP server that answers from fixtures, so live runs need no real service, no service credentials, and no network:

```yaml
- name: file-an-issue
  prompt: "Open a GitHub issue titled 'Bug'."
  mcp-mocks:
    - server: github
      tools:
        - name: create_issue
          responses:
            - match: {title: "Bug", repo: "acme/widget"}
              response: "Issue created."
            - match-regex: {title: "^Feature: "}
              response-file: fixtures/feature.json
            - match-schema: {type: object, required: [title]}
              response: {ok: true}
```

Each response declares exactly 1 matcher:

| Matcher | Accepts a call when |
|---|---|
| `match` | The whole argument object deep-equals the mapping: type-strict, key order ignored |
| `match-regex` | Every named field's stringified value matches its pattern - the same regex dialect the contract engine uses |
| `match-schema` | The arguments validate against the JSON Schema |

The first response whose matcher accepts the call wins. Its `response` (a string verbatim, any structure as JSON) or `response-file` (read relative to the skill directory) is returned as the tool result.

A miss is visible twice over. An unmatched or unknown call returns an MCP error result naming the tool and the closest declared fixture, so the model sees what went wrong - and it also fails the trial deterministically under `live.mcp`, regardless of how the agent reacted. A mock never silently returns empty success. Every call is appended to a per-server JSONL log (tool, arguments, whether it matched, which fixture answered), and those logs land in the run artifacts beside the transcript.

The wiring stays contained. Mocked tools are advertised as `mcp__<server>__<tool>` and appended to a restricted contract's allowed tools (an unrestricted contract stays unrestricted), and the agent is pointed at the trial's own MCP config with `--strict-mcp-config`, so nothing from your host MCP configuration leaks in. Each server is skilltest itself, re-launched in a hidden `mcp-serve` mode - no extra dependency. `skilltest record` wires the same mocks, so a recorded fixture of an MCP-calling skill is hermetic too.

Structural problems - a server without tools, a tool without responses, 2 matchers on a response, both `response` and `response-file`, a missing response file - fail at parse time with a pointer, before any token is spent.

## Recording fixtures

`skilltest record` is the bridge between the suites: it runs a single live trial and writes the transcript as the skill's deterministic fixture.

```bash
skilltest record --skill broker
```

The workflow is deliberate: change a skill, record, review the fixture diff like any code change, commit. The deterministic gate then holds that behavior on every push for free.

- `--task` picks a task by name (default: the first declared one); `--model` picks the model as an alias or id (default: `models.default`, else the skill's first resolved model).
- The transcript is written to the skill's `deterministic.transcript` path, or `fixtures/transcript.jsonl` when none is set - record then prints a note reminding you to set the key so the deterministic run consumes the file.
- An existing fixture is never overwritten without `--force`.
- The transcript is redacted before writing (`report.redact`, default true), so environment secrets never land in a committed file.
- The written file is immediately graded against the skill's contract and custom checks, exactly as the deterministic gate grades it - so "passes record" means "passes the transcript gate". A recording whose contract fails is still written for inspection but exits `1`, catching a fixture that would poison the gate now instead of on the next push.

Record honors the configured `llm.environment`, spends tokens like any live run, and exits `2` before running when the agent or credentials are missing.

## Caching trials

A live trial is expensive and non-deterministic, and re-running an unchanged task buys no new signal, so `skilltest llm --cache` reuses graded results:

- The cache key is a SHA-256 digest of everything that could change the verdict: the skill name, the full task declaration, the resolved model id, the skilltest version, a content hash of every file the skill ships (its `fixtures/` directory excluded, since fixtures are digested per task), and the task's fixture content, repos, and workdir. Change any of them and the entry misses and the trial runs live.
- Entries live under `.skilltest/cache/` in your repo. A hit replays the stored graded trials without launching an agent; replayed trials carry `cached: true` in the results, and their verdict line gains a `(cached)` marker.
- `--no-cache` ignores and skips writing the cache, and always wins over `--cache`. The default is no caching at all: every trial runs live.
- `skilltest cache clear` deletes every entry when you want a from-scratch run regardless of content.

The cache stores whole graded trials only. Re-scoring a saved run against an edited contract is `skilltest grade --results`, which is token-free unless you pass `--judge`. `skilltest matrix` has no cache: matrix trials always run live.

## Cost and CI

The bill multiplies fast: tasks x trials x models, plus a judge call per judged trial and a responder call per interactive turn. Before a big run, `skilltest matrix --estimate` prints the multiplication and a rough price without spending anything - see [multi-model testing](models.md).

What was actually spent is captured, not guessed: each trial records its token counts, turn count, duration, and the cost the agent itself reported, and the results document totals them. Those totals cover the agent runs; judge and responder calls spend additional tokens the totals don't include.

In CI, run the deterministic suite on every push and the llm suite as a separate opt-in step - a nightly schedule or a pre-release job - with agent credentials injected there and nowhere else.

## Output

Results go to stdout, diagnostics to stderr. `--json` replaces the human report with the machine-readable results document and nothing else. `--output <file>` persists the document; `--output-dir <dir>` writes it to a timestamped subdirectory together with every trial transcript (`artifacts/<skill>__<task>__<model>__t<N>.jsonl`) and mock log (`...__t<N>__mock-<server>.jsonl`). Persisted artifacts are redacted unless `report.redact: false`, which prints a loud warning. `--interpret` appends a plain-language reading of the result: the top failure and a concrete next step. The document schema, redaction, and the `report`, `compare`, and `gate` commands are covered in [reporting](reporting.md).

## `skilltest llm` reference

| Flag | Effect |
|---|---|
| `--dir <path>` | Repository root (default: current directory) |
| `--skill <glob>` | Select skills by name; repeatable |
| `--task <glob>` | Select tasks by name; repeatable |
| `--models <list>` | Override models: aliases or ids, comma-separated, or the word `ladder` |
| `--trials <n>` | Override the trial count per model |
| `--threshold <0..1>` | Override the pass-rate threshold |
| `--env <host\|docker>` | Execution environment |
| `--parallel <n>` | Concurrent trials (default 1) |
| `--judge-model <m>` | Override the judge model; it never follows `--models` |
| `--format <name>` | Output format: `text` or `json` (default: `text`) |
| `--json` | Shorthand for `--format=json` |
| `--output <file>` | Persist the results document to this file |
| `--output-dir <dir>` | Persist the document plus transcripts to a timestamped subdirectory |
| `--keep-workspace` | Preserve trial workspaces and print their paths |
| `--cache` / `--no-cache` | Reuse cached trial results / force live runs |
| `--interpret` | Append a plain-language reading of the result |

Next: [multi-model testing](models.md) - the model ladder, `skilltest matrix`, and the minimal-model verdict these trials feed.
