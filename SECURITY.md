# Security policy

## Supported versions

Security fixes land on the latest released minor. Upgrade to the current release before reporting an issue, and check that it still reproduces there.

| Version | Supported |
|---------|-----------|
| Latest release | Yes |
| Anything older | No |

## Reporting a vulnerability

Do not open a public issue for a security problem.

Report it privately through GitHub: go to the [Security tab](https://github.com/alexskrypnyk/skilltest/security) and choose **Report a vulnerability**. This opens a private advisory visible only to the maintainers.

Include what you have: the affected version, the steps to reproduce, and what an attacker gains. A proof of concept helps but is not required to file.

Expect an acknowledgement within a week. If a report is accepted, the fix and the advisory are published together, and the report is credited unless you ask otherwise.

## Scope

skilltest reads skill files, runs hook scripts, and - in the llm suite - executes an agent CLI against a workspace it assembles. Findings in any of that are in scope, particularly:

- A skill file, `eval.yaml`, or `skilltest.yml` that causes code execution outside the paths skilltest is meant to run.
- Escape from a `docker` environment trial into the host.
- Credentials or environment values reaching persisted results, logs, or artifacts unredacted.
- The install script or the published images accepting a tampered download.

Out of scope: a skill under test behaving badly on purpose. Detecting that is what skilltest is for, and the deterministic suite reports it as a finding rather than a vulnerability in the tool.
