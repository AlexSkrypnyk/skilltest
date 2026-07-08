# skilltest - product requirements

`skilltest` is a standalone test runner for agent skills. It ships as a single executable (PHAR, Docker image, later a static binary), reads one declarative `eval.yaml` per skill, and gives every skill repository two things: a token-free **deterministic** suite strict enough to gate CI, and an **llm** suite that runs the skill against real models to measure how well - and on how small a model - it actually works.

These PRDs specify the product extracted from the [harness repository](https://github.com/AlexSkrypnyk/harness) into this project (`alexskrypnyk/skilltest`, binary `skilltest`). They are the contract for the extraction; the harness implementation under its `tests/src` is reference material at most and may be refactored or rewritten entirely.

## Why it exists

Skill files are prose, and prose is never an enforcement boundary. A skill that "should" call a broker binary, "should not" push to git, and "must not" exfiltrate credentials needs those properties checked by machinery, not trusted to the model. At the same time, a skill that passes every structural check can still fail in practice - on a weaker model, on an ambiguous prompt, on a task it was never triggered for. One tool should answer both questions cheaply: *is this skill safe and conformant* (every commit, zero tokens), and *does it actually work, and on what* (opt-in, spends tokens).

## Design principles

1. **Deterministic first.** Everything that can be checked without a model is checked without a model. The deterministic suite is the CI gate: no network, no tokens, no flakes.
2. **Grade the world, not the words.** Behaviour is judged from observable effects - tool calls in a transcript, files left behind, hook exit codes - never from the agent's self-report.
3. **The skill is the unit.** Configuration, execution, and reporting are all per-skill. A repo-wide run is just the sum of its skills, and a skill without an eval is a failure, not a gap.
4. **Pre-baked over hand-rolled.** Common checks ship as a named catalog and bundled packs. A useful `eval.yaml` should be possible with zero regular expressions written by the author.
5. **Declared once, asserted everywhere.** A skill's behavioural contract is declared once and asserted identically against a recorded transcript (deterministic) and a live run (llm).
6. **Smallest sufficient output.** Human output is terse and scannable; `--json` is complete and stable; exit codes are documented and load-bearing.

## The two suites

Numbered layers are replaced by two named suites, split along the only boundary an outsider needs to understand: whether a model is involved.

| Suite | Groups | Model? | Network? | Where it runs |
|---|---|---|---|---|
| `deterministic` | `structure`, `security`, `hooks`, `transcript` | No | No | Every CI run, every commit - the gate |
| `llm` | `live`, `judge`, `matrix` | Yes | Yes | Opt-in: locally, nightly, pre-release |

Group names are plain words that state what is checked: `structure` (the skill files are well-formed and internally consistent), `security` (no danger patterns in anything the skill ships), `hooks` (enforcement hooks block what they must block), `transcript` (a recorded canonical run satisfies the skill's contract), `live` (a fresh run satisfies the same contract), `judge` (an LLM judge scores the run against a rubric of binary criteria), `matrix` (the live suite repeated across models and trials).

Naming alternatives considered: `static`/`replay`/`live` as a triad, and `gate`/`eval` as a pair. `deterministic`/`llm` wins because it names the property users actually care about in CI - can this flake and does it cost money - rather than the mechanism.

## Feature sources

Features assessed from skillgrade and adopted as skilltest requirements:

| skillgrade feature | skilltest requirement | PRD | Priority |
|---|---|---|---|
| Per-skill `eval.yaml`, zero project setup | Same model; config schema | `config.md` | P1 |
| Docker and local execution providers | `docker` and `host` environments | `environments.md` | P1 |
| Trials per task | `trials` in the llm suite | `checks-llm.md` | P1 |
| Deterministic grader scripts (JSON verdict contract) | Custom check scripts, same contract | `checks-deterministic.md` | P1 |
| LLM rubric grader | `judge` group (binary criteria + abstention) | `checks-llm.md` | P1 |
| CI threshold mode + exit codes | Exit-code contract, thresholds | `cli.md`, `reporting.md` | P1 |
| `init` scaffolding (AI-assisted with key, template without) | `skilltest init` | `cli.md` | P2 |
| pass@k / pass^k statistics | Result statistics | `reporting.md` | P2 |
| Secret redaction in persisted logs | Redaction in stored results | `reporting.md` | P1 |
| File references in YAML string values | Same | `config.md` | P2 |
| Browser results viewer | Self-contained HTML report (no server) | `reporting.md` | P2 |
| With/without-skill baseline (Normalized Gain) | `--baseline` mode in llm suite | `checks-llm.md` | P2 |
| Skill-trigger / discovery testing | Discovery tasks (prompt does not name the skill) | `checks-llm.md` | P2 |
| Multi-agent executors (Gemini, Codex, OpenCode, ACP) | Out of scope for v1; adapter seam reserved | `README.md` non-goals | P3 |

Features assessed from waza and adopted as skilltest requirements (full assessment in the harness repository's `.artifacts/skill-eval-comparison.md`):

| waza feature | skilltest requirement | PRD | Priority |
|---|---|---|---|
| Record a live run into a reusable task/fixture (`new task from-prompt`) | `skilltest record` | `cli.md`, `checks-llm.md` | P1 |
| Multi-model runs, `compare`, `--recommend` | `matrix` group + minimal-model report + `skilltest compare` | `models.md` | P1 |
| Skill-to-eval coverage grid + `--strict` | `skilltest coverage` + built-in coverage gate | `cli.md` | P1 |
| `expect_tools`/`reject_tools` contract vocabulary with arg regexes | Contract schema shape | `config.md` | P1 |
| Lifecycle hooks (before/after run and task, template vars) | Environment lifecycle hooks | `environments.md` | P1 |
| Git worktree materialisation into task workspaces | `repos:` workspace resources | `environments.md` | P1 |
| Schema-versioned `eval.yaml`/`results.json` + migrate policy | Versioned schemas, `skilltest migrate` | `config.md`, `reporting.md` | P1 |
| Stable exit codes (0 pass / 1 fail / 2 config) | Exit-code contract | `cli.md` | P1 |
| Offline re-grading of saved results (`grade --results`) | `skilltest grade` | `cli.md`, `reporting.md` | P2 |
| Baseline regression gate, golden tasks, delta policies | `skilltest gate` | `reporting.md` | P2 |
| Static readiness checks (spec compliance, advisory quality) | `structure` pre-baked checks | `checks-deterministic.md` | P1 |
| Token budgets (`tokens count/compare/profile`) with CI thresholds | `structure.token-budget` check + `skilltest tokens` | `checks-deterministic.md`, `cli.md` | P2 |
| Spec coverage (`spec verify`: SKILL.md promises mapped to tasks) | `skilltest coverage --spec` | `cli.md` | P2 |
| LLM-generated eval suites (`suggest`, confidence + rationale, merge-safe) | `skilltest init --ai` | `cli.md` | P2 |
| Skill content quality judge (`quality`: clarity, triggers, scope) | `judge` applied to the skill file itself | `checks-llm.md` | P2 |
| MCP mock servers from fixtures (hermetic external services) | `mcp-mocks:` in llm tasks | `checks-llm.md`, `environments.md` | P2 |
| Responder (LLM plays the user; abstention outcome) | `responder:` for interactive skills | `checks-llm.md` | P2 |
| Result caching with content-based invalidation | `--cache` on llm runs | `cli.md` | P2 |
| JUnit, github-comment reporters, plain-language `--interpret` | Reporters | `reporting.md` | P2 |
| Session NDJSON event logs, `--keep-workspace` debugging | Run artifacts | `reporting.md`, `environments.md` | P2 |
| Self-update with checksummed installers | `skilltest self-update` | `distribution.md` | P2 |
| Snapshot + `replay --bisect` determinism debugging | Deferred | - | P3 |
| Adversarial packs (prompt-injection, scope-bypass) | Deferred future suite | - | P3 |
| OTel tracing, auto-filed GitHub issues, cloud results storage | Deferred | - | P3 |

## Documents

| PRD | Contents |
|---|---|
| [`cli.md`](cli.md) | Commands, flags, exit codes, output contract |
| [`config.md`](config.md) | `eval.yaml` and `skilltest.yml` schemas, discovery, versioning |
| [`checks-deterministic.md`](checks-deterministic.md) | The deterministic suite: groups, pre-baked check catalog, packs, custom checks |
| [`checks-llm.md`](checks-llm.md) | The llm suite: live runs, judge, trials, recording, discovery tasks, baseline |
| [`models.md`](models.md) | Multi-model matrix and the minimal-model report |
| [`environments.md`](environments.md) | `host` and `docker` execution environments, lifecycle hooks |
| [`reporting.md`](reporting.md) | Results schema, reporters, statistics, the regression gate |
| [`distribution.md`](distribution.md) | PHAR, Docker image, static binary, CI recipes |
| [`migration.md`](migration.md) | Moving the harness repository from `tests/` to skilltest |

## Vocabulary

- **Suite** - the top-level split: `deterministic` or `llm`.
- **Group** - a named family of checks inside a suite (`structure`, `security`, `hooks`, `transcript`, `live`, `judge`, `matrix`).
- **Check** - one assertion with a stable id, either pre-baked (from the catalog) or custom (a script).
- **Pack** - a named bundle of pre-baked checks enabled as one line.
- **Contract** - the skill's declared behavioural spec: tools, commands, and skills it must and must not use, and the by-products it must and must not leave.
- **Transcript** - the JSONL tool-call record of a headless agent run, recorded (fixture) or live.
- **Trial** - one live run of a skill task; the llm suite aggregates over trials.
- **Ladder** - the ordered list of models (weakest first) used by the matrix to find the minimal viable model.
- **Verdict** - a judge's structured response: per-criterion pass/fail plus an explicit `unknown` abstention.

## Non-goals (v1)

- Driving agents other than Claude Code. The runner keeps a thin adapter seam so Gemini CLI, Codex, or ACP agents can be added later, but v1 ships Claude Code only - it is the only runtime whose Skill tool, plugin namespacing, and hook system the deterministic suite understands.
- Orchestrating fixes. skilltest reports; it never edits skills.
- Hosting a results server. Reports are files (terminal, JSON, HTML); long-lived dashboards are out of scope.
- Adversarial fault-injection packs, token-optimization suggestions, and cloud results storage: assessed, valuable, deferred (P3).

## Priorities

`P1` is the extraction MVP: everything the current suite already proves plus docker/host environments, pre-baked packs, multi-model matrix, and the minimal-model report. `P2` is fast-follow DX and reporting. `P3` is recorded intent, not commitment.
