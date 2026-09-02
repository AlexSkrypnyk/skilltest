# Distribution

skilltest ships as a tool you download, never as a dependency you require: no Composer entry in your repository, no vendor directory, no autoload wiring. You add config files and fixtures, install a single executable, and run it. This page covers every way to install that executable - the install script, the PHAR by hand, and the Docker images - plus version pinning, self-update, and the CI recipes that put them to work.

## Pick an artifact

Every release publishes 2 artifact forms. Both report the same `skilltest version` for a given release, so you can mix them across machines safely.

| Artifact | Needs on the machine | Best for |
|---|---|---|
| PHAR (`skilltest.phar`) | PHP 8.3 or newer | Developers and CI runners that already have PHP |
| Docker images (`ghcr.io/alexskrypnyk/skilltest` and `ghcr.io/alexskrypnyk/skilltest-agent`) | Docker | CI without PHP; anyone who wants zero install |

## The install script

The recommended path when the machine has PHP 8.3 or newer:

```bash
curl -fsSL https://raw.githubusercontent.com/alexskrypnyk/skilltest/main/install.sh | bash
```

The script downloads the release PHAR and its `skilltest.phar.sha256` companion, verifies the SHA-256 checksum, and installs the PHAR as an executable named `skilltest`. If the checksum doesn't match, it aborts and installs nothing - the partial downloads are cleaned up on exit. It finishes by running `skilltest version`, so you see immediately what you got.

It installs to `/usr/local/bin` when that's writable, falling back to `~/bin` (created if needed), and warns when the chosen directory isn't on your `PATH`. It works with either `curl` or `wget` and verifies with either `sha256sum` or `shasum`, so stock Linux and macOS both work. It checks for PHP 8.3 or newer up front and aborts with a clear message when that's missing, rather than installing a PHAR you can't run.

The script takes no flags; 4 environment variables control it:

| Variable | Effect | Default |
|---|---|---|
| `SKILLTEST_VERSION` | Install this release tag instead of the latest | The latest release |
| `SKILLTEST_INSTALL_DIR` | Install into this directory (created if needed) | `/usr/local/bin`, else `~/bin` |
| `SKILLTEST_REPO` | Read releases from this `owner/name` repository | `alexskrypnyk/skilltest` |
| `SKILLTEST_RELEASE_BASE_URL` | Fetch release assets from this base URL | The GitHub releases of `SKILLTEST_REPO` |

## The PHAR, by hand

Every release attaches 2 assets: `skilltest.phar` and its checksum file `skilltest.phar.sha256`. To do what the install script does yourself:

```bash
curl -fsSLO https://github.com/alexskrypnyk/skilltest/releases/latest/download/skilltest.phar
curl -fsSLO https://github.com/alexskrypnyk/skilltest/releases/latest/download/skilltest.phar.sha256
sha256sum -c skilltest.phar.sha256
chmod +x skilltest.phar && mv skilltest.phar /usr/local/bin/skilltest
```

On macOS, swap the verification line for `shasum -a 256 -c skilltest.phar.sha256`. For a specific release, replace `latest/download` with `download/<tag>` in both URLs.

## Docker images

Every release publishes 2 images to GitHub Container Registry, because Docker plays 2 unrelated roles here.

| | `skilltest` (tool image) | `skilltest-agent` (agent image) |
|---|---|---|
| Registry | `ghcr.io/alexskrypnyk/skilltest` | `ghcr.io/alexskrypnyk/skilltest-agent` |
| Contains | The skilltest PHAR on a PHP runtime | The Claude Code CLI plus git, curl, jq on Node |
| Role | Runs skilltest itself | The sandbox a skill under test runs inside |
| Used by | Anyone running the deterministic gate without PHP | skilltest, for `--env docker` llm trials |
| Credentials | None, ever | `ANTHROPIC_API_KEY` passed at container start |

Keeping them separate keeps the trust boundary honest. The tool image is the thing you trust: it ships no agent CLI and never receives credentials, so the CI gate can't make a network call or spend a token. The agent image is the thing you deliberately don't trust: a skill is arbitrary instructions, so live trials run it in a throwaway container that only sees what was passed in on purpose.

### Running the tool image

Mount the repository under test at `/work` and pass any command:

```bash
docker run --rm -v "$PWD":/work -w /work ghcr.io/alexskrypnyk/skilltest:latest
docker run --rm -v "$PWD":/work -w /work ghcr.io/alexskrypnyk/skilltest:latest validate
docker run --rm -v "$PWD":/work -w /work ghcr.io/alexskrypnyk/skilltest:latest coverage --format=markdown
```

The entrypoint is `skilltest`, so the first argument is a command, not a binary name. A bare invocation runs `run`, the default command: the deterministic suite and the coverage gate.

### Running the agent image

skilltest starts agent containers itself when a run asks for the `docker` [environment](environments.md), so this image is rarely run by hand:

```bash
skilltest llm --env docker
```

It's the default base image for those trials; point trials at a different base with `llm.docker.image` in `skilltest.yml`, and layer project tooling on top with `llm.docker.setup` (see [configuration](config.md)). Credentials are never baked in: `ANTHROPIC_API_KEY` enters as an environment variable when the container starts. To inspect the image directly:

```bash
docker run --rm -it ghcr.io/alexskrypnyk/skilltest-agent:latest bash
```

### Image tags

Each release pushes both images with a tag matching the release, and `latest` moves only for a stable `X.Y.Z` release - a pre-release such as `1.2.3-rc1` publishes its own tag and leaves `latest` alone. The tool image compiles the PHAR during its build and stamps the release tag into it, so `skilltest version` inside the container matches the tag on the image. The 2 images also change on different schedules: the tool image is pinned and reproducible, rebuilt when skilltest is released, while the agent image tracks the current Claude Code CLI, because testing against a stale agent tests nothing.

## Pinning a version

Commit a `.skilltest-version` file containing a release tag at the root of your repository:

```
1.2.3
```

The install script reads it from the directory it runs in, so the same CI step installs the pinned version on every machine. The file holds a single tag; `#` comments and blank lines are ignored. `SKILLTEST_VERSION` wins over the file when both are set. For Docker, pin the image tag instead: `ghcr.io/alexskrypnyk/skilltest:1.2.3`.

## Staying current

### self-update

```bash
skilltest self-update
```

`self-update` fetches the newest release tag from GitHub, downloads the PHAR and its checksum file, verifies the SHA-256, asks for confirmation, and replaces the running executable. `--yes` skips the confirmation for scripts. A few properties worth knowing:

- It runs only from an installed PHAR. From a source checkout there's nothing to replace, so it refuses with exit code 2.
- When you're already on the newest release it says so and exits 0.
- A checksum mismatch refuses the swap and leaves your executable untouched, with exit code 1.
- The swap writes the verified PHAR beside the old file and renames it into place, so a crash mid-update can't leave you half an executable.

### The update notice

After a `run`, skilltest may print a single line on stderr:

```
A new skilltest release is available: 1.3.0 (you have 1.2.0). Run `skilltest self-update` to upgrade.
```

The check is deliberately unobtrusive: at most 1 network read a day (the newest tag is cached in `.skilltest/cache/update-check.json` under your repository root), stderr only so `--json` output stays clean, and silent when the network is unreachable. It never runs when any of these hold:

- `--no-update-check` was passed to `run`.
- `SKILLTEST_NO_UPDATE_CHECK` is set to any non-empty value.
- `CI` is set to any non-empty value, which CI providers do, so pipelines never see it.
- You're running from source, where the version is `development`.

Nothing ever updates itself implicitly; the notice only points at `self-update`.

## Checking what you have

```bash
skilltest version
```

```
skilltest 1.2.3
Config schema:  1
Results schema: 1
PHP:            8.3.15 (phar)
```

`skilltest version --json` emits the same data as a single JSON object: the tool name and version, the supported config and results schema versions, and the PHP version with the runtime form (`phar` or `source`). Source checkouts report `development`; the real version is stamped in when the release PHAR is compiled.

## CI recipes

The per-push gate is free and deterministic - no model, no network, no tokens:

```yaml
jobs:
  skilltest:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: |
          docker run --rm -v "$PWD":/work -w /work \
            ghcr.io/alexskrypnyk/skilltest:latest
```

The scheduled llm and matrix run spends tokens, so it's nightly and gated against a committed baseline:

```yaml
jobs:
  skilltest-llm:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: |
          base=https://raw.githubusercontent.com/alexskrypnyk/skilltest
          curl -fsSL "$base/main/install.sh" | bash
      - run: skilltest matrix --output results.json
        env:
          ANTHROPIC_API_KEY: ${{ secrets.ANTHROPIC_API_KEY }}
      - run: |
          skilltest gate --baseline .skilltest/baseline.json \
            --current results.json --format github-actions
      - uses: actions/upload-artifact@v4
        with: {name: skilltest-results, path: results.json}
```

## Versioning and compatibility

The tool follows semantic versioning, and the release tag becomes the version `skilltest version` reports. Exit codes (0 pass, 1 fail, 2 configuration error), check ids, pack contents, and the results schema are compatibility surfaces: breaking any of them is a major release. The `eval.yaml`, `skilltest.yml`, and `results.json` schemas version independently of the tool; [configuration](config.md) covers how those versions work.

## What lands in your repository

Everything a consumer repository contains, in total: `skilltest.yml` at the root, 1 `eval.yaml` per skill, a `fixtures/` directory per skill for recorded transcripts and task fixtures, an optional committed baseline `results.json` for the regression gate, and the CI steps above. No PHP code, no test classes, no Composer project. Removing skilltest from a repository is deleting those files.

Everything on this page also builds from a checkout: `composer build` compiles the PHAR into `.build/skilltest.phar`, and the 2 Dockerfiles live under `.docker/`. [CONTRIBUTING.md](../CONTRIBUTING.md) walks through local builds. For what the gate actually checks, head to [deterministic checks](checks-deterministic.md); for every command and its flags, see [the CLI reference](cli.md).
