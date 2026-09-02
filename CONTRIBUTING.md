# Contributing

Thank you for considering a contribution to this project. This guide covers setting up a local environment, running the linting and tests, and building the release artifacts.

## Local setup

Requires PHP >= 8.3 and Composer.

    git clone https://github.com/alexskrypnyk/skilltest.git
    cd skilltest
    composer install
    ./skilltest version

`./skilltest` runs the application straight from the source tree, so there is no build step during development. A source checkout reports `development` as its version.

## Linting and tests

Always go through the Composer scripts. They set the correct configuration, working directory, and flags, so a direct call to `vendor/bin/phpunit`, `vendor/bin/phpcs`, `vendor/bin/phpstan`, or `vendor/bin/rector` silently diverges from CI.

    composer lint          # PHPCS, PHPStan, and Rector in dry-run mode
    composer lint-fix      # Rector, then PHPCBF
    composer test          # PHPUnit, no coverage
    composer test-coverage # PHPUnit with coverage reports

Coverage reports land in `.logs/.coverage-html/index.html` and `.logs/cobertura.xml`.

To run a single test class or method, forward the argument to the Composer script with `--`:

    composer test -- --filter=McpServeCommandTest
    composer test -- --filter='McpServeCommandTest::testMissingDefinitionFileFails'

To start from a clean dependency tree:

    composer reset
    composer install

## Quality standards

- PHP_CodeSniffer: Drupal coding standards plus a strict-types requirement (`phpcs.xml`).
- PHPStan: level 9 (`phpstan.neon`).
- Rector: PHP 8.3 modernisation and code quality (`rector.php`).

Every PHP file declares `strict_types=1`. Local variables and method arguments use `snake_case`; method names and class properties use `camelCase`.

## Adding a command

1. Create a class in `src/Command/YourCommand.php` extending `Symfony\Component\Console\Command\Command`.
2. Register it in `src/app.php` with `$application->add(new YourCommand());`.
3. Add a functional test in `tests/phpunit/Functional/YourCommandTest.php`.

Unit tests live in `tests/phpunit/Unit/` and use mocks with no I/O; functional tests live in `tests/phpunit/Functional/` and touch the real file system. Shared utilities are in `tests/phpunit/Traits/`.

## Building the PHAR

    composer build

This installs Box into `vendor-bin/`, validates `box.json`, and compiles `.build/skilltest.phar`.

## Building the Docker images

Both images build from a clean checkout with no published release needed. See the [Docker images](README.md#docker-images) section of the README for what each one is for.

The tool image compiles the PHAR inside its builder stage. Pass the release tag as `VERSION` so the compiled-in `skilltest version` matches it:

    docker build -f .docker/Dockerfile.tool \
      --build-arg VERSION=0.0.0-test -t skilltest:local .

    docker run --rm -v "$PWD/tests/fixtures/distribution":/work -w /work \
      skilltest:local version

The agent image tracks the current Claude Code CLI and takes no build arguments:

    docker build -f .docker/Dockerfile.agent -t skilltest-agent:local .
    docker run --rm skilltest-agent:local claude --version

`.github/workflows/test-distribution.yml` builds both images, lints them with Hadolint, and exercises the install script on every pull request. `.github/workflows/release-docker.yml` publishes them to GitHub Container Registry on a pushed tag.
