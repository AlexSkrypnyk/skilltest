# Execution environments

This page covers where a live trial actually runs: on your machine (`host`) or inside a container (`docker`), what goes into the fresh workspace every trial gets, and the lifecycle hooks that bracket a run with setup and teardown. Only the live, token-spending commands care about any of this - the deterministic suite reads files in place and needs no environment machinery. If you want the deterministic gate in CI without installing PHP, the tool also ships as a Docker image of its own; see [distribution](distribution.md).

## Choosing an environment

The repo-level `llm.environment` key in `skilltest.yml` picks the environment, and `host` is the default:

```yaml
llm:
  environment: docker
```

The `llm` command overrides it per invocation with `--env host` or `--env docker`. The `record` command follows the config value and has no flag. The `matrix` command runs on the host only; asking it for docker is a configuration error: `the docker environment is not yet implemented; run with --env host.`

Any other value in the file fails validation: `unknown environment '...'; must be 'host' or 'docker'.`

## Preflight

A live run without a working agent or credentials is a guaranteed, expensive failure, so skilltest checks both before a single trial starts. A preflight failure is a configuration error: the tool exits 2 with a message naming the missing piece, and no tokens are spent.

For `host`, the agent binary is the `SKILLTEST_AGENT` environment variable when set, otherwise the first executable `claude` on `PATH`. Credentials are detected heuristically: a non-blank `ANTHROPIC_API_KEY` or `CLAUDE_CODE_OAUTH_TOKEN`, or an authenticated Claude Code home (a `~/.claude` directory).

For `docker`, the agent ships inside the image, so the host needs only the docker CLI (the `SKILLTEST_DOCKER` variable when set, otherwise the first `docker` on `PATH`) and a daemon that answers `docker version`. Credentials are stricter here: one of the 2 variables must be set, because only explicit environment variables cross into the container. A host `~/.claude` login does not count.

The exact failure messages:

| Environment | Missing | Message |
|---|---|---|
| host | binary | `the 'claude' agent was not found on PATH; install Claude Code or set SKILLTEST_AGENT to its path.` |
| host | credentials | `no agent credentials found; set ANTHROPIC_API_KEY or CLAUDE_CODE_OAUTH_TOKEN, or authenticate Claude Code (~/.claude).` |
| docker | CLI | `the 'docker' binary was not found on PATH; install Docker or set SKILLTEST_DOCKER to its path to use --env docker.` |
| docker | daemon | `the Docker daemon is not reachable; start Docker to use --env docker.` |
| docker | credentials | `no agent credentials to pass into the container; set ANTHROPIC_API_KEY or CLAUDE_CODE_OAUTH_TOKEN.` |

## The workspace

Every trial gets a fresh directory - the agent's working directory for the run - assembled the same way in both environments. Workspaces live under `.skilltest/tmp/` in your repository root, one `ws-*` directory per trial, so nothing ever touches the system temp or your working tree. Assembly happens in a fixed order:

1. **Fixture**: the task's `fixture:` file or directory is copied in. The path resolves relative to the skill directory (absolute paths work too). A directory's contents land in the workspace root; a single file keeps its name. A missing fixture aborts with `fixture '...' was not found.`
2. **Repos**: each entry in the task's `inputs.repos` list is materialized as a detached `git worktree` checkout, which is cheap (it shares the source repository's object store), offline, and removed after the trial. This is how a skill that operates on a repository gets a real, isolated checkout instead of hand-staged file fixtures.
3. **Skill**: the skill under test is copied into the workspace's discovery path, `.claude/skills/<name>`, so the agent finds it the way it finds any installed skill.
4. **Working directory**: the agent starts in the workspace root, or in the `inputs.workdir` subdirectory when the task declares one.

A task using repos looks like this:

```yaml
llm:
  tasks:
    - name: reviews-a-repo
      prompt: Review the repository in the current directory.
      inputs:
        repos:
          - source: .          # a path to a local clone, relative to the repo root or absolute
            commit: main       # SHA, branch, or tag; HEAD when omitted
            dest: workdir      # required: the subdirectory inside the workspace
        workdir: workdir       # where the agent starts
```

Both `source` and `dest` are required for every repo entry. `dest` and `workdir` must be relative paths without a `..` segment, so a task can never write outside its workspace. When a task declares MCP mocks, their wiring is also written into the workspace at launch; see [llm checks](checks-llm.md).

After the trial, worktrees are removed through git's own bookkeeping (`git worktree remove --force`, then a prune) and the directory tree is deleted, whether the trial passed, failed, or threw. Pass `--keep-workspace` to `llm` to preserve every workspace instead; each path is printed to stderr as `workspace preserved: <path>` so you can inspect exactly what the agent left behind. A kept workspace's checkouts stay registered as worktrees of their source repository until you remove them yourself.

## Trial timeout

Every trial runs under a wall-clock budget of 300 seconds, in either environment; set `SKILLTEST_TRIAL_TIMEOUT` (in seconds) to change it. A trial that exceeds the budget is terminated - SIGTERM first, then SIGKILL after a 1-second grace period - and reported with exit code 124, failing the trial with `agent run timed out after <n>s.` rather than stalling the run.

## `host`

Trials run on the machine itself: the host's `claude` binary, the host's existing authentication, workspaces under the project's `.skilltest/tmp/`. There's zero setup beyond an authenticated CLI, which makes it the fastest loop for skill authors. Concurrent trials run through a bounded process pool, so `--parallel` shortens wall-clock time without changing any verdict.

The trade-off is honesty about isolation: the agent runs with your user's permissions, constrained by the contract's allowed tools and the turn cap, not by an OS boundary. `host` is the right choice for development and for CI runners that are already ephemeral sandboxes.

## `docker`

Trials run in containers, one fresh container per trial. The workspace is still assembled on the host, exactly as `host` assembles it, and then bind-mounted into the container - so what the agent reads and writes stays a real, inspectable directory while the process itself is sealed off from your machine.

The image comes from the `llm.docker` block, and every key is optional:

```yaml
llm:
  environment: docker
  docker:
    image: ghcr.io/alexskrypnyk/skilltest-agent:latest
    setup: |
      RUN apt-get update && apt-get install -y --no-install-recommends php-cli
    cpus: 2
    memory-mb: 2048
```

- `image` names the base; the default is `ghcr.io/alexskrypnyk/skilltest-agent:latest`, the official agent image shipping the Claude Code CLI plus git, curl, jq, bash, and unzip on a Node 24 base. It's pulled automatically when not present locally.
- `setup` is raw Dockerfile instructions appended after the base image, for whatever tooling the skill under test drives (`php`, `composer`, `gh`, and so on). The run image is built once per run and tagged `skilltest-run-<run id>`, so per-trial cost is a container start, not an image build. With no `setup`, the base image is used as-is.
- `cpus` and `memory-mb` cap each container (`--cpus` and `--memory`); both default to no limit, and each must be a positive number when set.

Each trial's container is started with a name and a `skilltest.run` label, the workspace mounted at `/work`, and the command run through `sh -c`. The shape of the invocation:

```
docker run --rm --name skilltest-<run>-<trial> --label skilltest.run=<run> \
  [--cpus=N] [--memory=Nm] [-e ANTHROPIC_API_KEY] [-e CLAUDE_CODE_OAUTH_TOKEN] \
  -v <workspace>:/work -w /work <image> sh -c '<agent command>'
```

Credentials are forwarded by name only (`-e ANTHROPIC_API_KEY`, not `-e ANTHROPIC_API_KEY=...`), so the secret never appears on a command line or in a process listing. Nothing else from the host environment crosses the boundary.

Timeouts and cleanup are thorough because a killed `docker run` client doesn't necessarily stop its container: a timed-out trial's container is force-removed by name, and at the end of the run a label sweep removes any container left behind, the built run image is deleted, and the scratch area is removed once empty. A failing container that printed nothing still gets a diagnostic in its transcript: `docker: the trial timed out and its container was killed.` or `docker: the container exited with code <n> and produced no output.`

`docker` is the right default when you're running other people's skills (a skill is arbitrary instructions; the security group scans it statically, but isolation is the runtime defense), when trials must not touch your real config, and when reproducibility across machines matters. The practical costs: you need a running Docker daemon, and credentials must be environment variables.

## Lifecycle hooks

External state is the hard part of testing skills whose side effects aren't filesystem-local - boards, PRs, deployments, a shared test bed. Lifecycle hooks, declared under `llm.lifecycle` in `skilltest.yml`, give a run deterministic setup and teardown at 4 points: `before-run` and `after-run` bracket the whole invocation, `before-task` and `after-task` bracket every trial. Both `llm` and `matrix` run them; `record` does not.

```yaml
llm:
  lifecycle:
    before-run:
      - command: php playground/reset.php
        error-on-fail: true
    before-task:
      - command: 'echo "task {{ task }} trial {{ trial }}"'
    after-run:
      - command: php playground/reset.php
        on-host: true
```

Each hook accepts these keys:

| Key | Default | Meaning |
|---|---|---|
| `command` | required | The shell command to run. |
| `working-directory` | repo root | Where it runs; relative paths resolve against the root. |
| `exit-codes` | `[0]` | Acceptable exit codes, a single integer or a list. |
| `error-on-fail` | `false` | Whether a failure in a `before-*` phase aborts the run. |
| `on-host` | `false` | Run on the host even when trials run in docker. |

A `before-run` or `before-task` hook that fails its acceptable exit codes aborts the run with exit 2 when it declares `error-on-fail: true`, so a broken setup can never let a trial run against dirty state. Every other failure - a `before-*` hook without `error-on-fail`, or any `after-*` hook - warns on stderr and continues, because a failed teardown must not mask the trial's own verdict. The warning names the phase, command, and exit: `lifecycle <phase> hook '<command>' failed with exit <n> (expected <codes>).` A hook that hangs is killed like a trial: after 60 seconds on the host, or 600 seconds when it runs in a container.

Template variables are substituted into commands as `{{ name }}`, and they exist only in the per-trial phases. `before-task` and `after-task` hooks receive:

| Variable | Value |
|---|---|
| `{{ skill }}` | The skill name. |
| `{{ task }}` | The task name. |
| `{{ trial }}` | The 1-based trial number. |
| `{{ model }}` | The resolved model id. |
| `{{ workspace }}` | The absolute workspace path on the host. |
| `{{ vars.<key> }}` | Each scalar value under the task's `inputs:`, except `workdir`. |

`before-run` and `after-run` hooks receive no variables, and any unrecognized `{{ ... }}` token substitutes to an empty string rather than reaching the shell as a literal brace expression.

Under `docker`, hooks share the trial's isolation by default: each runs in a fresh container from the run image, with the same credentials forwarded and its working directory mounted at `/work`. That mount is the only host directory the hook container sees, so `{{ workspace }}` - a host path - is not reachable inside it; a hook that needs the workspace, or must manage host-side state like resetting a shared test bed, sets `on-host: true` to stay on the host.

Hooks are how a shared external test bed becomes trial-safe: reset before each trial, and trials against mutable services stop colliding. For fully hermetic runs, prefer [MCP mocks](checks-llm.md) over lifecycle-managed real services; hooks are the tool when the real service is the point.

## What environments never change

The contract, the checks, the judge, and the report are identical in both environments. An environment decides where a trial runs and what it can touch - never what passing means. The one trace it leaves is bookkeeping: the results document records which environment the run used in its run metadata (see [reporting](reporting.md)).

For the full key reference see [configuration](config.md); for the flags on `llm`, `matrix`, and `record` see the [CLI reference](cli.md).
