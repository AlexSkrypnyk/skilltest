# Distribution smoke-test fixture

A minimal repository directory mounted into the tool image so the distribution
CI can exercise the container one-liner from `prd/distribution.md`:

```bash
docker run --rm -v "$PWD":/work -w /work ghcr.io/alexskrypnyk/skilltest:latest version
```

It has no skilltest configuration on purpose: the check here is that the image
starts, mounts the working directory, and runs the compiled binary.
