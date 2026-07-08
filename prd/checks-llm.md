# The llm suite

The llm suite answers the question the deterministic suite cannot: does the skill actually work when a real model drives it? It runs skills headlessly through Claude Code, asserts the same contract the deterministic suite asserts, adds an LLM judge for the criteria only judgement can score, and aggregates over trials and models. It spends tokens, needs credentials, and can flake - so it is always explicit (`skilltest llm`, `skilltest matrix`), never part of the bare gate, and CI adopts it as a separate opt-in step (nightly, pre-release) rather than a per-push requirement.

## Group: `live`

For each task in a skill's `llm.tasks`, skilltest launches a headless agent run: `claude -p <prompt>` with `--output-format stream-json`, the contract's `tools.allowed` as the allowed-tools restriction, and `max-turns` as the turn cap, inside the configured environment (`host` or `docker`, see `environments.md`). The emitted transcript - the same JSONL shape the deterministic `transcript` group consumes - is asserted against the skill's full contract plus any custom checks. One declaration, two enforcement points: a contract regression is caught deterministically from the fixture on every push, and behaviourally from live runs whenever the llm suite runs.

Tasks:

- **Invoked tasks** name the skill (`prompt: /harness:run-harness-workflow`): they test that the skill, once triggered, behaves within contract.
- **Discovery tasks (P2)** describe the job without naming the skill (`discovery: true`): the run passes only if the skill actually triggered (its Skill invocation or skill-file read appears in the transcript) and the contract held. This is trigger-precision testing - the thing that most often degrades on smaller models.
- **Baseline mode (P2)**: with `--baseline`, each task also runs without the skill available, and the report shows the improvement delta (normalized gain: how much of the remaining headroom the skill recovers). This answers "does this skill help at all?" - a skill whose baseline passes anyway is documentation, not capability.

## Trials and pass semantics

Each task runs `trials` times per model (default 1 for `skilltest llm`, 3 for `skilltest matrix`). A trial passes when every contract check passes and every judge criterion passes. A task passes on a model when its pass rate meets `threshold` (default 0.8). Statistics beyond the pass rate (pass@k, pass^k, per-criterion pass rates) are computed and reported (P2) but never gate on their own.

Flake handling is honest by design: there are no automatic retries that hide instability. A task that passes 2 of 3 trials reports 0.67, and whether that gates is the threshold's job.

## Group: `judge`

The judge scores what deterministic checks cannot: is the output accurate, in voice, well-reasoned, appropriate? Design rules, kept from the current suite because they are the difference between a useful judge and a random number:

- **Binary criteria only.** The rubric is a list of independent yes/no statements. Each is judged pass/fail; there is no holistic 1-100 score, because N small judgements are dramatically lower-variance than one big one.
- **Abstention is first-class.** The judge may return `unknown` per criterion when the evidence does not show the answer. Abstentions surface in the report as `unknown`, are configurable to count as failures in strict mode, and are never silently treated as passes.
- **Structured verdicts.** The judge returns JSON (`criteria: [{id, pass}], reasoning, unknown`); parsing is hardened (fence stripping, clamping, fallback extraction) and a malformed verdict is a judge failure, not a skill failure.
- **The judge is itself tested.** The verdict parser and prompt builder are unit-tested against recorded verdict fixtures, so judge-harness drift is caught without spending tokens and separately from skill regressions.
- **Cheap model by default.** Judging uses the configured `models.judge` (default: the ladder's weakest model); the judge model never silently follows the execution model upward.

What the judge sees: the task prompt, the transcript (tool calls and results), and any declared output artifact. It scores evidence, not vibes.

## Skill content quality (P2)

`judge` can also be pointed at the skill file itself rather than a run: a built-in rubric scores `SKILL.md` for clarity, trigger precision (does the description say when to use it and when not to), scope coverage, and anti-patterns. Enabled per skill with `llm.quality: true` and run as part of `skilltest llm`. This is the static-quality complement to the `structure.advisory` warnings, for the judgements a regex cannot make.

## Interactive skills: the responder (P2)

Skills that ask follow-up questions cannot be tested with a single prompt. A task may declare a `responder` - a model that plays the user:

```yaml
- name: interactive-setup
  prompt: "Set up the board worker for this repo."
  responder:
    instructions: |
      You are the repo owner. The board is "Team Board", the label is "worker",
      auto-merge is off. Answer the skill's questions consistently with this;
      abstain if you genuinely cannot infer an answer.
    max-followups: 6
```

After each agent turn the responder replies, stops, or abstains; abstention fails the run with a distinct `abstained` outcome (the brief was too vague), and hitting `max-followups` stops with `cap-exhausted` and grades the final state. Each task carries its own responder, so one skill can be tested against several personas and configurations.

## Hermetic external services: MCP mocks (P2)

For skills that call MCP tools, a task may declare `mcp-mocks`: skilltest launches each mock as a local stdio MCP server serving fixture responses (exact-match, schema-match, or per-field regex-match against tool arguments), so live runs need no real service, no credentials, and no network. Unmatched calls fail loudly naming the missing fixture. This keeps llm trials reproducible for skills whose side effects would otherwise hit shared external state.

## Recording

`skilltest record` is the bridge between the suites: it runs one live trial and writes the transcript as the skill's deterministic fixture. The workflow is deliberate: change a skill, `skilltest record --skill <name>`, review the fixture diff like any code change, commit. The deterministic gate then holds that behaviour on every push for free. Fixtures are versioned artifacts, and a fixture that no longer satisfies its own contract fails `record` immediately rather than poisoning the gate later.

## Cost controls

- `--cache` (P2) reuses trial results keyed on task content, fixtures, model, and skill content hash; any change invalidates. Caching never applies to judge-only re-scoring (`skilltest grade --results` does that without an agent at all).
- `matrix --stop-at-pass` climbs the model ladder and stops at the first passing model instead of filling the whole grid.
- Per-run cost and token usage are captured per trial and totalled in the report, so the price of a suite is a number, not a surprise.
