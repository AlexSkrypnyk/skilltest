<p align="center">
  <a href="" rel="noopener">
  <img width=200px height=200px src="https://placehold.jp/000000/ffffff/200x200.png?text=skilltest&css=%7B%22border-radius%22%3A%22%20100px%22%7D" alt="skilltest logo"></a>
</p>

<h1 align="center">Test runner for AI agent skills</h1>

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

skilltest is a standalone test runner for AI agent skills - the `SKILL.md`-based skills used by Claude Code and compatible runtimes. It ships as a single executable, reads one declarative `eval.yaml` per skill plus one repo-level `skilltest.yml`, and gives any skill repository two test suites:

- **deterministic** - the CI gate: no model, no network, no tokens, no flakes. Checks skill structure, supply-chain security, enforcement hooks, and a recorded transcript against the skill's behavioural contract on every push.
- **llm** - the live suite: opt-in, spends tokens. Runs the skill against real models, asserts the same contract on live transcripts, scores runs with an LLM judge, and answers the headline question: what is the smallest model this skill works on?

The authoritative specification is the PRD set in [`prd/README.md`](prd/README.md).

## Quickstart

From a clone:

    composer install
    ./skilltest version

## Install

skilltest is consumed as a tool, never as a project dependency - no Composer require, no vendor directory. Pick the artifact that fits the machine; all forms report the same `skilltest version`.

### Install script (recommended, requires PHP >= 8.3)

    curl -fsSL https://raw.githubusercontent.com/alexskrypnyk/skilltest/main/install.sh | bash

Downloads the latest release PHAR, verifies its SHA-256 checksum, and installs it to `/usr/local/bin/skilltest` (falling back to `~/bin` when that is not writable). Pin a release with a `.skilltest-version` file in the repository, or with `SKILLTEST_VERSION=<tag>`.

### PHAR (manual, requires PHP >= 8.3)

    curl -fsSLO https://github.com/alexskrypnyk/skilltest/releases/latest/download/skilltest.phar
    curl -fsSLO https://github.com/alexskrypnyk/skilltest/releases/latest/download/skilltest.phar.sha256
    sha256sum -c skilltest.phar.sha256
    chmod +x skilltest.phar && mv skilltest.phar /usr/local/bin/skilltest

### Docker (no PHP on the host)

    docker run --rm -v "$PWD":/work -w /work ghcr.io/alexskrypnyk/skilltest:latest

The tool image (`ghcr.io/alexskrypnyk/skilltest`) bundles the PHAR on a PHP runtime and runs the deterministic suite and every offline command. A separate agent image (`ghcr.io/alexskrypnyk/skilltest-agent`) is the base for `--env docker` llm trials.

## CI recipes

Per-push gate (free, deterministic - no model, no network, no tokens):

```yaml
jobs:
  skilltest:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: docker run --rm -v "$PWD":/work -w /work ghcr.io/alexskrypnyk/skilltest:latest
```

Scheduled llm + matrix run with a regression gate (spends tokens, nightly):

```yaml
jobs:
  skilltest-llm:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: curl -fsSL https://raw.githubusercontent.com/alexskrypnyk/skilltest/main/install.sh | bash
      - run: skilltest matrix --output results.json
        env:
          ANTHROPIC_API_KEY: ${{ secrets.ANTHROPIC_API_KEY }}
      - run: skilltest gate --baseline .skilltest/baseline.json --current results.json --format github-actions
      - uses: actions/upload-artifact@v4
        with: {name: skilltest-results, path: results.json}
```

## Documentation

| PRD | Contents |
|-----|----------|
| [`prd/README.md`](prd/README.md) | Vision, design principles, the two suites, feature traceability |
| [`prd/cli.md`](prd/cli.md) | Commands, flags, exit codes, output contract |
| [`prd/config.md`](prd/config.md) | `eval.yaml` and `skilltest.yml` schemas, discovery, versioning |
| [`prd/checks-deterministic.md`](prd/checks-deterministic.md) | The deterministic suite: groups, check catalog, packs |
| [`prd/checks-llm.md`](prd/checks-llm.md) | The llm suite: live runs, judge, trials, recording |
| [`prd/models.md`](prd/models.md) | Multi-model matrix and the minimal-model report |
| [`prd/environments.md`](prd/environments.md) | `host` and `docker` execution environments, lifecycle hooks |
| [`prd/reporting.md`](prd/reporting.md) | Results schema, reporters, statistics, the regression gate |
| [`prd/distribution.md`](prd/distribution.md) | PHAR, Docker image, static binary, CI recipes |
| [`prd/migration.md`](prd/migration.md) | Moving the harness repository from `tests/` to skilltest |

## Maintenance

    composer install
    composer lint
    composer test
    composer build

## Updating

To pull the latest infrastructure from the template into this project, ask
Claude Code to "update scaffold" - see [`AGENTS.md`](AGENTS.md) for details.

---
_This repository was created using the [Scaffold](https://getscaffold.dev/) project template_
