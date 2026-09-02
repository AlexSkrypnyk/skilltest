# skilltest documentation

skilltest is a test runner for agent skills - the `SKILL.md`-based skills that Claude Code and compatible runtimes load. It reads one declarative `eval.yaml` per skill and gives a skill repository two things: a deterministic suite that costs nothing to run and is strict enough to gate CI, and an llm suite that runs the skill against real models to measure how well it actually works, and on how small a model.

These pages describe what the tool does today. If you're new here, read [the command-line reference](cli.md) first to see the shape of the tool, then [configuration](config.md) to write your first `eval.yaml`.

## Why it works this way

Skill files are prose, and prose is never an enforcement boundary. A skill that "should" call a particular binary, "should not" push to git, and "must not" leak credentials needs those properties checked by machinery rather than trusted to a model. At the same time a skill can pass every structural check and still fail in practice: on a weaker model, on an ambiguous prompt, on a task nobody thought to trigger it with.

So skilltest answers 2 questions separately. Is this skill safe and well-formed, checkable on every commit for zero tokens? And does it actually work, and on what model, checkable when you opt in and spend money.

Four ideas shape the rest:

1. **Deterministic first.** Anything checkable without a model is checked without one. The deterministic suite is the CI gate.
2. **Grade the effects, not the words.** Behavior is judged from observable evidence - tool calls in a transcript, files left behind, hook exit codes - never from what the agent says it did.
3. **The skill is the unit.** Configuration, execution and reporting are all per-skill. A repo-wide run is the sum of its skills, and a discovered skill with no `eval.yaml` is a failure rather than a gap.
4. **Declared once, asserted everywhere.** A skill's behavioral contract is written once and asserted identically against a recorded transcript and against a live run.

## The two suites

The split runs along the only boundary that changes how you use the tool: whether a model is involved.

| Suite | Groups | Model? | Network? | Where it runs |
|---|---|---|---|---|
| `deterministic` | `structure`, `security`, `hooks`, `transcript` | No | No | Every CI run, every commit - the gate |
| `llm` | `live`, `judge`, `matrix` | Yes | Yes | Opt-in: locally, nightly, before a release |

Each group name says what it checks. `structure` means the skill files are well-formed and internally consistent. `security` means nothing the skill ships matches a danger pattern. `hooks` means enforcement hooks block what they must block. `transcript` means a recorded canonical run satisfies the skill's contract. `live` means a fresh run satisfies that same contract. `judge` means a model scores the run against a rubric of binary criteria. `matrix` means the live suite repeated across models and trials.

## The pages

| Page | What it covers |
|---|---|
| [Command-line reference](cli.md) | Every command, every flag, the output contract and the exit codes |
| [Configuration](config.md) | The `eval.yaml` and `skilltest.yml` schemas, discovery, precedence, environment variables |
| [The deterministic suite](checks-deterministic.md) | The full check catalog by group, the bundled packs, and the custom-check contract |
| [The llm suite](checks-llm.md) | Live runs, tasks, trials, the judge, the responder, MCP mocks and recording |
| [Models and the matrix](models.md) | Model aliases, the ladder, the matrix report and the minimal-model verdict |
| [Environments](environments.md) | The `host` and `docker` environments, trial workspaces and lifecycle hooks |
| [Results and reporting](reporting.md) | The `results.json` schema, reporters, statistics and the regression gate |
| [Distribution](distribution.md) | The PHAR, the install script, the Docker images and self-update |

## What skilltest doesn't do

It runs Claude Code and nothing else. There's no adapter for other agent runtimes.

It doesn't lint or format your skill's prose. `structure` checks that the files are well-formed and internally consistent; whether the writing is any good is a question for a human, or for the `judge` group pointed at the skill file.

It doesn't manage credentials. The llm suite needs an authenticated `claude` on the host, or credentials forwarded into the container, and it tells you plainly when it can't find them.

## Contributing

Local setup, the lint and test commands, building the PHAR and building the Docker images all live in [CONTRIBUTING.md](../CONTRIBUTING.md).
