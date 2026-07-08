# Distribution

skilltest is consumed as a tool, never as a project dependency: no Composer require in the consumer repo, no vendor directory, no autoload wiring. A consumer adds config files and fixtures, downloads one artifact, and runs it. Three artifact forms, one release pipeline.

## Artifacts

| Artifact | Needs on the machine | Audience | Priority |
|---|---|---|---|
| PHAR (`skilltest.phar`) | PHP >= 8.3 | Developers and CI with PHP present | P1 |
| Docker image (`ghcr.io/alexskrypnyk/skilltest`) | Docker | CI without PHP; anyone who wants zero install | P1 |
| Static binary (via `static-php-cli`: PHP embedded, single native file) | Nothing | Everyone else; the long-term default | P2 |

All three are built from the same tag by the release workflow, published on GitHub Releases with SHA-256 checksums, and report identical `skilltest version` output.

## Install

```bash
# PHAR
curl -fsSLO https://github.com/alexskrypnyk/skilltest/releases/latest/download/skilltest.phar
chmod +x skilltest.phar && mv skilltest.phar /usr/local/bin/skilltest

# Docker (tool-in-docker: mount the repo, run the same commands)
docker run --rm -v "$PWD":/work -w /work ghcr.io/alexskrypnyk/skilltest:latest

# install script (detects OS/arch, verifies checksum, prefers the static binary when available)
curl -fsSL https://raw.githubusercontent.com/alexskrypnyk/skilltest/main/install.sh | bash
```

`skilltest self-update` (P2) fetches the latest release for the installed artifact form, verifies the checksum, and swaps itself after confirmation (`--yes` for scripts). A non-blocking, cached, once-a-day release check may print an update notice after command output; `SKILLTEST_NO_UPDATE_CHECK=1` or `--no-update-check` disables it, and it never runs in CI (detected via `CI=1`).

## The two Docker images

Docker appears in two distinct roles; the images are separate on purpose:

1. **Tool image** (`skilltest`): the PHAR plus a PHP runtime. Runs the deterministic suite and all offline commands anywhere Docker runs - this is how CI runs the gate without installing PHP. It contains no agent CLI and no credentials.
2. **Agent image** (`skilltest-agent`): the base for `--env docker` llm trials (see `environments.md`): the Claude Code CLI preinstalled plus common tooling, extended per repo via `llm.docker.setup`. Credentials enter only as explicit env vars at container start.

The tool image can orchestrate agent containers (it talks to the Docker socket when `--env docker` is requested from inside the tool image), but the default CI recipe keeps it simpler: tool image for the gate, host runner for scheduled llm work.

## CI recipes

Per-push gate (free, deterministic):

```yaml
jobs:
  skilltest:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: docker run --rm -v "$PWD":/work -w /work ghcr.io/alexskrypnyk/skilltest:latest
```

Scheduled llm + matrix run with regression gate (spends tokens, nightly):

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

## Versioning and compatibility

- Semantic versioning on the tool; the `eval.yaml`/`skilltest.yml`/`results.json` schemas version independently (`MAJOR.MINOR`, see `config.md`) and the tool documents which schema majors each release reads.
- Exit codes, check ids, pack contents, and the results schema are compatibility surfaces: breaking any of them is a major, and pack pattern additions (which can newly fail suites) are called out in release notes.
- The repository ships a `.skilltest-version` convention (optional pin file) that CI recipes and `install.sh` honour, so a repo can pin the tool the same way it pins any other toolchain piece.

## Consumer footprint

Everything a consumer repo contains, in total: `skilltest.yml`, one `eval.yaml` per skill, `fixtures/` per skill (recorded transcripts and task fixtures), an optional committed `baseline.json`, and the CI steps above. No PHP code, no test classes, no Composer project. Deleting skilltest from a repo is deleting those files.
