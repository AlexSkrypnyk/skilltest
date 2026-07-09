# Distribution smoke-test fixture

A minimal repository directory mounted into the tool image so the distribution CI can exercise the container one-liners from `prd/distribution.md`:

```bash
docker run --rm -v "$PWD":/work -w /work ghcr.io/alexskrypnyk/skilltest:latest version
docker run --rm -v "$PWD":/work -w /work ghcr.io/alexskrypnyk/skilltest:latest
```

It carries one well-formed skill with an `eval.yaml`, so the bare invocation - the default `run` command, the deterministic gate - discovers a skill, runs the suite, and exits `0`. The PHAR smoke test points at this fixture with `--dir=` for the same reason.
