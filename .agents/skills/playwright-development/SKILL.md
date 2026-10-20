---
name: playwright-development
description: "Use when developing or debugging METSIS Drupal UI with Playwright MCP or the VS Code integrated browser: inspect pages, verify CSS dimensions, OpenLayers/Leaflet maps, Views AJAX, HTMX dialogs, exposed filters, and browser regressions."
---

# Playwright-assisted METSIS development

Use the interactive browser to reproduce bugs and verify actual rendered behavior.
Pair observations with focused automated regression tests; do not equate a
successful page load, screenshot, or map-shaped container with working behavior.

## 1. Discover tools and obtain a page

1. Read the current browser-pages attachment. Page IDs belong to the current
   browser session: never copy IDs from old conversations or this skill.
2. Discover the available tools with tool search before using deferred tools.
   Search for "Playwright browser openBrowserPage readPage runPlaywrightCode".
   Tool names and signatures vary by host; use the returned definitions.
3. Reuse a shared page when possible. If pages are open but not shared, use
   `openBrowserPage` with the target URL to request access. Do not assume access
   to an unshared tab.
4. Open the local DDEV URL from the environment or project configuration.
   The URL used during development was `https://metsis-drupal.ddev.site`;
   verify the current project's URL rather than hardcoding it.
5. Keep the returned page ID for subsequent calls. Read its accessibility
   snapshot before interacting.

Prefer dedicated navigation, read, click, typing, and screenshot tools.
Use `runPlaywrightCode` only when those are insufficient, such as measuring
computed dimensions or asserting multiple instances.

## 2. Inspect, act, and verify

- Start with the reported URL, inputs, expected result, and measurable failure.
- Use accessible roles/names or stable application selectors. Snapshot element
  references may become stale after navigation, AJAX, or HTMX replacement;
  read the page again before acting on new markup.
- Wait for a concrete condition, not an arbitrary sleep: a visible dialog,
  replaced result container, enabled control, or rendered canvas.
- A completed request does not prove success. Check visible errors, resulting
  content, URL changes, console failures, and relevant network responses.
- Reload after changing PHP/Twig/libraries and rebuilding Drupal caches.
  Distinguish a stale page from a code regression.
- Screenshots provide visual evidence, but use the page snapshot/DOM for
  interaction and numeric assertions.
- Keep tool output small. For offloaded snapshots, search the saved output
  and read only relevant ranges rather than dumping it.

Playwright snippets have a provided `page` object. Access browser globals only
inside `page.evaluate`, `locator.evaluate`, or `locator.evaluateAll`; never use
bare `document` or `window` in the tool's outer execution context. Keep each
snippet self-contained, concise, and focused. If a call returns a deferred
result ID, resume it using the documented tool arguments instead of repeating
the action.

### Example: diagnose map dimensions and scope

Use after obtaining the current page ID:

```javascript
return page
  .locator(".bbox-map-filter-container, .leaflet-container")
  .evaluateAll((elements) =>
    elements.map((element) => ({
      id: element.id,
      height: getComputedStyle(element).height,
      width: element.getBoundingClientRect().width,
      inlineStyle: element.getAttribute("style"),
      inBlock: !!element.closest(".metsis-search-exposed-form-block"),
    })),
  );
```

Computed CSS and bounding rectangles answer different questions. Inspect both
when padding, borders, responsive layout, or CSS custom properties affect size.
Derive rem-based expectations from the root computed font size, not a fixed 16px.

## 3. METSIS-specific checks

### Independent map instances

- Main result map: `.metsis-search-map`.
- Bbox filter map: `.bbox-map-filter-container`.
- Result-row Leaflet map: `.leaflet-container`.
- Block scope: `.metsis-search-exposed-form-block`.
- View filter scope: `.metsis-filters`.

Read active configuration before concluding heights are leaking. Block bbox
overrides, Views bbox height, result-map area height, and row Leaflet height
are independent. Compare configuration to computed dimensions on the correct
page; the front-page block may not be placed on `/metsis/search`.

For OpenLayers, verify a canvas has nonzero backing dimensions and the map
container has the expected CSS height. Example:

```javascript
return page.locator(".bbox-map-filter-container").evaluateAll((elements) =>
  elements.map((element) => {
    const canvas = element.querySelector("canvas");
    return {
      ready: element.dataset.metsisBboxReady,
      height: element.getBoundingClientRect().height,
      canvasWidth: canvas?.width ?? 0,
      canvasHeight: canvas?.height ?? 0,
    };
  }),
);
```

Canvas dimensions can scale with device pixel ratio; do not require exact
equality with CSS pixels. A canvas alone does not prove tiles loaded: inspect
network failures and visual output when testing the basemap itself.

Scroll lazy maps into view; interact with the map or its tabs if needed.
Check tab hide/show resizing, AJAX replacement, empty results, and simultaneous
View/block maps. Verify drawing updates only the matching form's inputs.
Check unique Leaflet IDs when metadata fragments and result rows coexist.

### Views and forms

- Verify positive and boundary values, saved configuration, and reload behavior.
- Views handler configuration values are nested under `options`; number input
  values may be strings. Tests must reflect the actual submitted form shape.
- Test default-display inheritance and per-display overrides independently.
- After AJAX search, recheck both result and bbox maps and stale geometry.
- Investigate layout regressions using computed style and actual theme wrappers,
  not only module CSS in isolation.

### HTMX metadata and NBS dialogs

- Check one shared dialog/messages target and unique fragment map IDs.
- Open, close, and reopen; verify scroll reset and initializer reattachment.
- Use invalid login to check a single visible error; then authorized test
  credentials to check successful login removes stale errors and continues
  inside the dialog without reloading search.
- Do not send real NetCDF/backend requests merely to test the dialog.
  Use repository fixtures or a controlled test backend.

### Vite and asset failures

When controls render but a map is tiny or blank, inspect CSS/JS responses.
A real regression was a bbox sizing stylesheet rewritten to an invalid Vite
URL returning 404. The fix used a separate ordinary Drupal stylesheet library,
not a global height override.

Check development versus built assets. Tests using deployed bundles must build
them first; Drupal's Vite module uses `$settings['vite']['useDevServer'] = FALSE`
to disable development-server use. Verify the installed module's API before
changing settings. Never edit generated bundles manually.

## 4. Interactive browser is not PHPUnit WebDriver

Interactive Playwright tools control a shared browser. Drupal
`WebDriverTestBase` tests separately require a compatible WebDriver endpoint,
test database, base URL, and Mink configuration. Working interactive tools
do not establish that ChromeDriver is installed or listening.

See [browser testing and CI](../../../docs/development/README.md#4-browser-tests-and-ci)
and [map regression tests](../../../tests/src/FunctionalJavascript/MetsisMapsTest.php).
Honor any instruction to defer functional suites. Missing drivers do not
justify installing software without permission.

## 5. Permissions, fixtures, and completion

- Never install anything with apt without asking the user first.
- Do not restart/recreate DDEV containers from an active development chat.
- An access-denied admin page means the browser is not authorized. Ask for an
  authorized session or permission for a scoped test fixture; do not bypass
  access controls or expose login tokens/credentials in reports.
- Prefer isolated fixtures. If authorized live configuration changes are needed,
  record original values, restore them, and verify restoration. Do not overwrite
  user settings or export unrelated config differences.
- Clean up only fixtures/processes you created. Stop known temporary services;
  do not kill by process name or close unrelated user tabs.
- Add focused unit/kernel/browser regressions at the appropriate layer.
- Report separately: observed live behavior, automated tests passed, tests
  deferred, and anything not verified. Never claim a configuration-form save
  was browser-tested if only configuration reads/unit tests were performed.
