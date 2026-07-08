# Execution environments

Two environments run llm work: `host` (the default: fast, uses the machine's own `claude` and credentials) and `docker` (isolated: a container per trial, nothing from the host leaks in or out except what is passed deliberately). The deterministic suite needs no environment machinery - it reads files and runs hook scripts in place - but it can execute inside the Docker image too, which is how CI runs the gate without installing PHP (see `distribution.md`).

Selection: `llm.environment` in repo config, overridden per invocation with `--env host|docker`.

## The workspace

Every llm trial gets a fresh workspace directory - the agent's working directory for the run - assembled the same way in both environments:

1. **Fixtures**: the task's `fixture:` file or directory is copied in (paths relative to the skill directory).
2. **Repos (P1)**: the task's `repos:` list materialises local git repositories into the workspace via `git worktree add --detach` - cheap (shared object store), offline, and automatically removed after the trial. This is how skills that operate on a repository get a real, isolated checkout instead of hand-staged file fixtures:

   ```yaml
   inputs:
     repos:
       - source: .                  # this repo, or any local clone path
         commit: main               # SHA, branch, or tag; defaults to HEAD
         dest: workdir              # subdirectory inside the workspace
     workdir: workdir               # where the agent starts
   ```

3. **Skills**: the skill under test (and its declared dependencies) are installed into the workspace's discovery path (`.claude/skills/`) or, for plugin-shaped repos, the plugin is loaded via the runtime's plugin mechanism.
4. **MCP mocks (P2)**: declared mocks are started and wired into the runtime config for the trial (see `checks-llm.md`).

Workspaces are deleted after each trial; `--keep-workspace` (P2) preserves them and prints their paths for debugging.

## `host`

Trials run on the machine: workspace in the project's temp area, the host's `claude` binary, the host's existing authentication (API key, OAuth token, or stored login). Zero setup beyond an authenticated CLI, and the fastest loop for skill authors. The trade-off is honesty about isolation: the agent runs with the host user's permissions, constrained by the contract's `tools.allowed` and the turn cap, not by an OS boundary. `host` is for development and for CI runners that are already ephemeral sandboxes.

## `docker`

Trials run in containers:

- **Image**: `llm.docker.image` names the base (the official `skilltest-agent` image ships the agent CLI preinstalled; see `distribution.md`), and `llm.docker.setup` appends build steps for project tooling (the skill under test may need `php`, `composer`, `gh`, whatever it drives).
- **Prepare once, run many**: the image plus skills plus fixtures common to all trials are baked once per run; each trial then starts a fresh container from the prepared image, so per-trial cost is container start, not image build.
- **Credentials**: `ANTHROPIC_API_KEY` / `CLAUDE_CODE_OAUTH_TOKEN` are passed as container env explicitly; nothing else from the host environment crosses in. Results persisted from containerised runs are redacted like all results.
- **Limits**: per-container CPU and memory limits are configurable (`llm.docker.cpus`, `memory-mb`), and the trial timeout kills the container, not just the process.
- **No Docker-in-CI tax**: CI guidance is `host` on ephemeral runners and `docker` on developer machines - the same guidance skillgrade ships, because it is correct.

`docker` is the right default when running other people's skills (a skill is arbitrary instructions; the security group scans it statically, but isolation is the runtime defence), when trials must not touch the developer's real config, and when reproducibility across machines matters.

## Lifecycle hooks

External state is the hard part of testing skills whose side effects are not filesystem-local (boards, PRs, deployments). Lifecycle hooks give the suite deterministic setup and teardown at four points, with template variables available in commands:

```yaml
llm:
  lifecycle:
    before-run:
      - command: php playground/reset.php
        error-on-fail: true
    before-task:
      - command: 'echo "task {{ task }} trial {{ trial }}"'
    after-task: []
    after-run:
      - command: php playground/reset.php
        error-on-fail: false
```

- `before-run` / `after-run` bracket the whole invocation; `before-task` / `after-task` bracket every trial.
- Each hook declares `error-on-fail` (a failing setup aborts with exit `2`; a failing teardown warns) and acceptable `exit-codes`.
- Hooks run in the environment's context: on the host for `host`, inside the trial container for `docker`, with an explicit `on-host: true` escape for hooks that must manage external state regardless (resetting a shared test bed lives on the host even when trials are containerised).
- Available variables: `{{ skill }}`, `{{ task }}`, `{{ trial }}`, `{{ model }}`, `{{ workspace }}`, plus `{{ vars.* }}` from `inputs:`.

Hooks are how a shared external test bed becomes trial-safe: reset before each trial, and trials against mutable services stop colliding. For fully hermetic runs, prefer MCP mocks over lifecycle-managed real services; hooks are the tool when the real service is the point.

## What environments never change

The contract, the checks, the judge, and the report are identical in both environments. An environment decides where a trial runs and what it can touch - never what passing means.
