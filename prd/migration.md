# Migrating the harness repository to skilltest

This PRD covers the consumer side of the extraction: what in the harness repository's (`AlexSkrypnyk/harness`) `tests/` project maps to what in skilltest, and the order of operations for the switch. The tool itself is built in this repository (`alexskrypnyk/skilltest`) against the other PRDs in this directory; the harness `tests/src` implementation is available as reference material for that build, with no obligation to preserve its shape.

## Concept mapping

| In `tests/` today | In skilltest |
|---|---|
| `phpunit/<Name>SkillTest.php` contract class per skill | `skills/<name>/eval.yaml` per skill |
| `SkillTestBase` (the layer machinery) | The tool itself |
| `@group layer0` (static SKILL.md validation) | `deterministic` suite, `structure` group |
| `@group layer1` (hook enforcement via `HookRunner`) | `deterministic` suite, `hooks` group, cases in `skilltest.yml` |
| `@group layer2` (transcript grading via `TranscriptGrader`) | `deterministic` suite, `transcript` group |
| `@group layer3` (danger scan via `DangerScanner`) | `deterministic` suite, `security` group |
| `@group layer4` (`ClaudeRunner` live eval + `Judge`) | `llm` suite (`live` + `judge`), plus `matrix` |
| `requiredHarnessCalls()` / `forbiddenHarnessCalls()` | `contract.commands.required` / `.forbidden` |
| `expectedByProducts()` / `forbiddenByProducts()` (label => PCRE) | Same `label: pattern` maps under `contract.commands`; common patterns become `pack:` references |
| `expectedTools()` / `allowedTools()` | `contract.tools.required` / `.allowed` |
| `securityExpectations()` | `security.forbidden-tokens` |
| `rubric()` | `llm.judge.rubric` |
| `transcriptJsonl()` heredoc fixtures | Committed `fixtures/transcript.jsonl` files, produced by `skilltest record` |
| Broker-bypass assertion hardcoded in `assertMeetsContract()` | Repo-level `guards:` entry using `pack:gh-mutations` |
| `harness` invocation normalisation hardcoded in `TranscriptGrader` | `aliases:` in `skilltest.yml` |
| Command-reference resolution via `CommandRegistry::fromBinary()` | `commands.resolve.binary: bin/harness` in `skilltest.yml` |
| `SkillCoverageTest` | Built-in coverage gate |
| `Support/` unit tests for the machinery | skilltest's own test suite, in the skilltest repository |
| `composer --working-dir=tests test` CI job | `skilltest` (deterministic) CI step via the tool Docker image |
| `composer --working-dir=tests eval-judge` + `HARNESS_EVAL_LIVE=1` | `skilltest llm` / `skilltest matrix`, explicit and credentialed |

## What the harness repository keeps

After migration, the plugin repo contains no eval framework code at all:

- `skilltest.yml` at the root: skills path, `aliases` for `bin/harness`, `commands.resolve` against the binary, `guards`, the two hook scripts' enforcement cases, model ladder, lifecycle hooks pointing at the playground reset script for llm runs.
- `skills/<name>/eval.yaml` for each of the seven shipped skills, each with its contract, security expectations, rubric, and llm tasks.
- `skills/<name>/fixtures/transcript.jsonl` recorded fixtures.
- CI: the deterministic step on every push (tool image), the llm/matrix step scheduled with a secret.
- Deleted: the whole `tests/` Composer project (`src/`, `phpunit/`, `composer.json`, `phpunit.xml.dist`, lint configs) once parity is confirmed.

## Order of operations

1. **Build skilltest to P1** in its own repository, against these PRDs, with its own unit suite covering the machinery to the same standard the current `Support/` tests do (the judge and transcript grading stay testable offline from recorded fixtures).
2. **Author the consumer config in the harness repo on a feature branch**: `skilltest.yml` plus seven `eval.yaml` files translated from the contract classes (mechanical, table above), fixtures moved from heredocs to files.
3. **Run both in parallel** on the branch: the existing PHPUnit suite and `skilltest` must both pass, and a deliberate contract violation (edit a fixture to include `gh pr create`) must fail both. Parity means same failures, not just same passes.
4. **Swap CI**: replace the `tests/` job with the skilltest deterministic step; add the scheduled llm/matrix job as a new, separate workflow with its secret.
5. **Delete `tests/`** in the same PR that swaps CI, so the repo never carries two sources of truth.
6. **Record fresh fixtures** via `skilltest record` per skill as a follow-up, replacing the hand-written heredoc transcripts with genuinely recorded runs.

## Acceptance for the migration PR

- `skilltest` exits `0` on the repo, exits `1` when any single contract line, hook case, security pattern, or structure rule is deliberately violated, and exits `2` on a malformed `eval.yaml`.
- `skilltest llm --skill run-harness-workflow` completes a live trial against the playground test bed using the lifecycle reset hooks.
- `skilltest matrix` produces the per-model grid and a minimal-model verdict for at least one skill.
- CI runs the deterministic suite with no secrets and no PHP setup on the runner.
- No PHPUnit, no `tests/` directory, no framework code remains in the harness repository.
