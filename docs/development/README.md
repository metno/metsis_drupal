# Development and CI

[Back to README](../../README.md#5-development-and-testing) |
[Installation](../installation.md)

## 1. DDEV setup

The repository is a module-first workspace; `web/` is the local test site.
Use the project's DDEV configuration/add-ons and prepare a Drupal 11.4+
site with PHP 8.4 and the dependencies from the
[installation guide](../installation.md).

Run these commands **on the host** for an already prepared project:

```bash
ddev start
ddev composer install
ddev drush en metsis_drupal -y
ddev drush cr
ddev drush config:status
```

Inside the web container, omit the `ddev` prefix; use `vendor/bin/drush` if
Drush is not on PATH. Never edit generated dependencies under `web/core`,
`web/modules/contrib`, `vendor`, or `node_modules`.
Export only intentional configuration changes.

**Existing VS Code/Copilot history must be migrated before restarting or
recreating the container.** Follow the [history storage guide](vscode-history.md)
before any such operation. Local DDEV customizations are not all tracked and
are not reproduced by installing the upstream add-ons alone.

## 2. Frontend development

Install root lint/format dependencies and map application dependencies:

```bash
ddev exec npm ci
ddev exec npm --prefix js/metsis ci
ddev exec npm --prefix js/metsis run build
```

Use `npm install` instead of `npm ci` where a lockfile is not present.
The Vite development server uses `npm --prefix js/metsis run dev` inside the
container; expose it through the local DDEV configuration.
Drupal libraries consume the deployment assets/manifest under `js/metsis/dist`.

## 3. Quality checks

From the module root **inside the container**:

```bash
vendor/bin/phpcs -s --report-full --report-summary
vendor/bin/phpstan analyse --configuration=phpstan.neon
vendor/bin/phpunit --configuration=phpunit.xml tests/src/Unit tests/src/Kernel modules/nbs_extensions/tests/src/Unit
npx eslint .
npx prettier --check .
```

For changed PHP files, run PHPCBF, PHPCS, PHPStan, then relevant PHPUnit tests.
Prefer focused tests, for example:

```bash
vendor/bin/phpunit tests/src/Unit/Plugin/views/area/MetsisMapAreaTest.php
vendor/bin/phpunit tests/src/Unit/SdcComponentsTest.php
vendor/bin/drush sdc-devel:validate metsis_drupal
```

SDC Devel is optional: its PHPUnit checks are skipped when unavailable,
while core schema and rendering checks still run. Vocabulary tests require
`vendor/metno/mmd` resources.

## 4. Browser tests and CI

For interactive UI diagnosis with the VS Code browser/Playwright MCP tools,
agents can load the workspace
[playwright-development skill](../../.agents/skills/playwright-development/SKILL.md).
It covers page sharing, computed dimensions, map isolation, Views AJAX/HTMX,
safe fixtures, and the distinction from PHPUnit WebDriver infrastructure.

Local browser-suite execution requires a compatible ChromeDriver/WebDriver
endpoint, `SIMPLETEST_BASE_URL`, a test database via `SIMPLETEST_DB`, and
`MINK_DRIVER_ARGS_WEBDRIVER`.

```bash
vendor/bin/phpunit --configuration=phpunit.xml tests/src/FunctionalJavascript/MetsisMapsTest.php
```

Map-height assertions measure `.metsis-search-map .map-container`, not the
outer `.metsis-search-map` wrapper, which also contains the projection selector.
The test explicitly configures 400px result-map and 250px bbox-map heights,
checks canvas backing dimensions, and reports measured dimensions on failure.

The `metsis_map_test` fixture replaces both search execution and Solr field
discovery. Its service provider selects a config-only field manager after all
service definitions load, preserving configured datasource fields without a
Solr connection. The browser test clears the field-definition cache and checks
the bbox field type before saving the View, covering fresh CI installations.

The [quality workflow](../../.github/workflows/quality.yml) runs ESLint, Prettier,
PHPStan, PHPCS, and PHPUnit. The reusable
[Drupal preparation action](../../.github/actions/drupal-prepare/action.yml)
installs dependencies, symlinks the module, and provisions MMD for tests.

The PHPUnit job provisions matching Chrome/ChromeDriver, starts WebDriver on
`127.0.0.1:4444` and a multi-worker PHP Drupal server on `127.0.0.1:8888`,
and checks both services before testing. Start the PHP server with `web/` as its
working directory and an absolute router path: Drupal plugin discovery uses
paths relative to the document root, and a module-root working directory can
produce an HTTP 500 reporting that `block_page` does not exist.

CI runs the suites configured in `phpunit.xml` without a positional `tests/`
argument. That argument limits discovery to the parent module and omits the
20 NBS submodule tests (159 tests instead of 179 in the current checkout).
Headless Chrome uses software WebGL
for map canvases. Failed runs upload server/driver logs and browser output as
`phpunit-browser-diagnostics`.
