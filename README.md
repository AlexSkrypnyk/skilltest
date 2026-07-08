<p align="center">
  <a href="" rel="noopener">
  <img width=200px height=200px src="https://placehold.jp/000000/ffffff/200x200.png?text=skilltest&css=%7B%22border-radius%22%3A%22%20100px%22%7D" alt="skilltest logo"></a>
</p>

<h1 align="center">Few lines describing your project</h1>

<div align="center">

[![GitHub Issues](https://img.shields.io/github/issues/alexskrypnyk/skilltest.svg)](https://github.com/alexskrypnyk/skilltest/issues)
[![GitHub Pull Requests](https://img.shields.io/github/issues-pr/alexskrypnyk/skilltest.svg)](https://github.com/alexskrypnyk/skilltest/pulls)
[![Test PHP](https://github.com/alexskrypnyk/skilltest/actions/workflows/test-php.yml/badge.svg)](https://github.com/alexskrypnyk/skilltest/actions/workflows/test-php.yml)
[![codecov](https://codecov.io/gh/alexskrypnyk/skilltest/graph/badge.svg)](https://codecov.io/gh/alexskrypnyk/skilltest)
![GitHub release (latest by date)](https://img.shields.io/github/v/release/alexskrypnyk/skilltest)
![LICENSE](https://img.shields.io/github/license/alexskrypnyk/skilltest)
![Renovate](https://img.shields.io/badge/renovate-enabled-green?logo=renovatebot)

</div>

---

skilltest - project brief
This document is a self-contained explainer for what is being built, why, and where everything lives. It is written to be handed to another agent (or human) with no other context.

What we are building
skilltest (https://github.com/AlexSkrypnyk/skilltest) is a standalone test runner for AI agent skills - the SKILL.md-based skills used by Claude Code and compatible runtimes. It ships as a single executable (a PHP PHAR, a Docker image, later a static binary), reads one declarative eval.yaml per skill plus one repo-level skilltest.yml, and gives any skill repository two test suites:

deterministic - the CI gate. Zero tokens, zero network, zero flakes; runs on every push. Four check groups: structure (skill files are well-formed, references resolve, budgets respected), security (static supply-chain scan of every shipped file for danger patterns like curl | bash and credential reads), hooks (the repo's real PreToolUse enforcement hooks are executed against crafted inputs and must block/allow correctly), transcript (a recorded canonical run of the skill, stored as a JSONL fixture, must satisfy the skill's declared behavioural contract).
llm - the live suite. Opt-in, spends tokens: runs the skill headlessly through Claude Code (claude -p --output-format stream-json), asserts the same contract against the live transcript, adds an LLM judge scoring binary rubric criteria (with explicit abstention), aggregates over N trials, and runs across a model ladder to answer the headline question: what is the minimal (cheapest) model this skill works on? (skilltest matrix prints a per-model grid and a "minimal model: sonnet" verdict per skill.)
The core design ideas: the behavioural contract (required/forbidden tools, commands, and sub-skill invocations, expressed as label: pattern maps) is declared once and asserted identically against recorded fixtures and live runs; pre-baked checks and pattern packs (e.g. pack:gh-mutations) make a useful eval writable with zero hand-rolled regexes; skilltest record turns a live run into the committed fixture the free gate then holds forever; execution environments are host (fast, developer machines and ephemeral CI) and docker (isolated container per trial); lifecycle hooks (before/after run/task) handle external state like shared test beds.

Why it exists
It is the extraction of the skill-evaluation suite that currently lives inside the harness repository (a Claude Code plugin + PHP broker binary, AlexSkrypnyk/harness) at tests/ - a layered PHPUnit setup proving that plugin's skills conform and its enforcement hooks enforce. That suite's ideas are sound ("prose is never an enforcement boundary", "grade the world, not the words") but it is PHPUnit-shaped, repo-coupled, and unusable by other skill repos.

Before deciding to build, two existing tools were deeply assessed (comparison report: .artifacts/skill-eval-comparison.md in the harness repo):

skillgrade (mgechev, TypeScript): a small agent-agnostic live benchmark (trials, weighted graders, pass@k, Docker/local providers). Great DX (per-skill eval.yaml, one CLI), but it covers only the live slice - no static validation, no hook testing, no supply-chain scan, no token-free replay.
waza (Microsoft, Go): a much larger skill-engineering CLI (static checks, offline re-grading, regression gate, MCP mocks, adversarial packs, token budgets, dashboards). Closest in spirit, but its execution engine is hard-wired to the GitHub Copilot SDK - it cannot drive Claude Code, which is the runtime whose Skill tool, plugin namespacing, and PreToolUse hooks these skills depend on.
Decision: build our own, keeping our deterministic-gate core (which neither tool has), adopting skillgrade's delivery model and waza's best features (record-from-live, baseline regression gate, coverage grid, model comparison, MCP mocks, responder for interactive skills, lifecycle hooks, schema versioning, JUnit/PR-comment reporters, self-update). Naming: the numbered "layers 0-4" of the old suite were replaced with the deterministic vs llm split because that is the boundary users care about in CI (can it flake, does it cost money).

Decisions already made
Package/binary name: skilltest (alexskrypnyk/skilltest), PHP >= 8.3, Symfony Console, PHAR built with box.
Start from scratch - the old tests/src code is NOT seeded into the new repo. The ~250 lines of hard-won logic (danger-scan regexes, JSONL tool-use walk, bin/harness alias normalisation, judge verdict parsing, hook stdin protocol) are carried as spec inside the GitHub issues instead, to avoid anchoring the new architecture to the old PHPUnit shape.
Claude Code is the only agent runtime in v1 (a thin adapter seam is reserved for others later).
Priorities: P1 = milestone v1 (issues #1-#17), P2 = milestone v1.x (issues #18-#26), P3 deferred (adversarial packs, OTel, snapshots/bisect, cloud storage).
Where everything lives
PRDs (the authoritative spec, 10 documents): harness repo, tests/prd/ - README.md (vision, naming, feature-source traceability tables), cli.md, config.md (both YAML schemas with annotated examples), checks-deterministic.md (check catalog + packs), checks-llm.md, models.md (matrix + minimal-model report), environments.md, reporting.md (results schema, gate), distribution.md, migration.md (how the harness repo itself migrates and deletes its tests/). Issue #1 imports these into the skilltest repo as prd/.
Backlog: 26 issues in https://github.com/AlexSkrypnyk/skilltest/issues, labels p1/p2, milestones v1/v1.x, dependency-ordered via "Depends on: #N" lines. Build order for v1: #1 bootstrap, #2 config, #3 discovery/coverage, #4 contract engine, #5-#7 deterministic groups, #8 run command, #9 results schema, #10 live runner, #11 judge, #12 record, #13 matrix, #14 host env + lifecycle hooks, #15 docker env, #16 distribution, #17 dogfood against the harness repo.
skilltest repo state: freshly scaffolded from the maintainer's template (composer.json with symfony/console, box.json, phpcs/phpstan/rector, phpunit, skilltest bin stub, namespace AlexSkrypnyk\App pending rename). No product code yet.
Comparison report: harness repo, .artifacts/skill-eval-comparison.md (three-way: old suite vs skillgrade vs waza; both tools cloned under .artifacts/tmp/ there).
First consumer: the harness repo itself - seven shipped skills, two enforcement hooks, a bin/harness binary for command-reference resolution, and a live GitHub test bed for llm trials. Its migration (authoring skilltest.yml + seven eval.yaml files, then deleting tests/) is specced in tests/prd/migration.md and gated on skilltest reaching P1.
What "done" looks like
A skill repo adds skilltest.yml, one eval.yaml per skill, and recorded fixtures; CI runs skilltest (via the tool Docker image, no PHP, no secrets) as a hard gate on every push; a scheduled job runs skilltest matrix with an API key and skilltest gate against a committed baseline; and the maintainer can answer "is this skill safe, does it behave, does it actually work, and on how small a model" from one tool's reports.

## Features

- Your first feature as a list item
- Your second feature as a list item
- Your third feature as a list item

## Installation


    composer require alexskrypnyk/skilltest




## Usage


    vendor/bin/skilltest





### CLI options

| Name        | Default value | Description                        |
|-------------|---------------|------------------------------------|
| `arg1`      |               | Description of the first argument. |
| `--option1` | `default1`    | Option with a default value.       |
| `--option2` | None          | Option wihtout a value.            |


## Maintenance


    composer install
    composer lint
    composer test




## Updating

To pull the latest infrastructure from the template into this project, ask
Claude Code to "update scaffold" - see [`AGENTS.md`](AGENTS.md) for details.

---
_This repository was created using the [Scaffold](https://getscaffold.dev/) project template_
