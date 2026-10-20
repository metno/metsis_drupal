# Maps and visualisation

[Back to README](../README.md#4-functionality-and-documentation) |
[Search and Views](search-and-views.md)

## 1. Views result map

Add **METSIS Map** as a header/footer area. Each map has isolated settings and
result geometry, full available width, and a height of 150–1000px
(400px default). Accepted numeric form values are stored as integers;
fractional heights are rejected rather than truncated.

JSON settings must be an object, with object-valued `features` and `mapOptions`
when supplied. Empty results clear geometry. AJAX/HTMX replacement releases
old map instances. Standalone WMS maps retain their 600px default.

Feature-title tooltips wrap and stay inside their map container. Leaving the
map, dragging, or resizing hides them without retaining an invisible layout
box at old desktop coordinates. Dataset geometry and overlapping feature
colors are unchanged; multiple globe-spanning features can fill the map.

## 2. Bounding-box filter

Configure map/coordinate input and tabs in the bbox Views filter.
Its map height is 150–1000px (250px default), independent of the result map.
Exposed-form blocks inherit that height unless configured with a local override.
Result-row Leaflet previews retain their separately supplied height
(250px in the result renderer).

Each bbox map owns its map, drawing source, coordinate inputs, resize observer,
and teardown, allowing a View and exposed-form block on the same page.
The sizing stylesheet uses an ordinary Drupal library, separate from Vite
processing, to avoid invalid development asset URLs. Width fits its container,
including narrow columns and non-tabbed layouts.

## 3. WMS and dataset visualisation

The optional all-products WMS selector appears only when every resource has
the same selectable layer-name set, irrespective of ordering. Selecting a
layer updates all products. Failed capability requests disable the shared
selector and display an error.

Search rows and dynamic landing pages can offer inline WMS and supported
OPeNDAP feature visualisations. These HTMX fragments have independent mounts
and do not replace the primary search map.

## 4. Regression coverage

[`MetsisMapsTest.php`](../tests/src/FunctionalJavascript/MetsisMapsTest.php)
checks rendered canvases/heights, bbox tab switching, AJAX replacement, and
simultaneous View/block maps against an isolated backend. Its responsive
scenario checks real default-row markup, search-button alignment, date-field
bounds, mobile abstract expansion, and hidden-tooltip overflow across Stark,
Olivero, and Claro at 1440, 1024, 820, 769, 768, 390, and 320px.
See [browser testing](development/README.md#4-browser-tests-and-ci) for
WebDriver prerequisites and CI diagnostics.
