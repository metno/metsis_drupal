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

Feature-title tooltips list every overlapping result feature under the
pointer (up to five titles, then "+N more"). They wrap and stay inside their
map container. Leaving the map, dragging, or resizing hides them without
retaining an invisible layout box at old desktop coordinates.

Clicking result features opens a popup inside the map. Several features show a
list; each entry (or a single feature directly) shows the title, a Collection
badge for parents, the temporal extent in short notation, **Show in results**
(scrolls to and highlights the row), **View metadata** (reuses the row's HTMX
metadata dialog) and an HTTP(S) landing-page link when available. Escape, the
close button, or a click on empty map closes it.

Pins are black by default and blue when hovered or selected; while a popup is
open, other features turn grey. Hovered/selected polygons are highlighted.
Globe-spanning polygons (detected from the EPSG:4326 input) are nearly
transparent so stacked world-covering results do not hide smaller polygons.
GeoJSON features carry `solr_id`, `temporal_start`, `temporal_end` and
`is_parent` properties for the popup.

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

All input modes share the same full-width fieldset layout, with the optional
predicate above the inputs rather than floating beside the next filter:

| Use map | Also show user input | Use tabs | Rendered inputs |
| ------- | -------------------- | -------- | --------------- |
| Off | Either | Either | Coordinates only |
| On | Off | Either | Map only; hidden coordinates remain in the form |
| On | On | Off | Coordinates followed by map |
| On | On | On | Map and coordinates in the `bbox_form_tabs` SDC |

Each mode supports an exposed or fixed predicate. Coordinate fields wrap within
narrow columns. The live longitude/latitude readout is positioned over the map
and cannot change its intrinsic width during pointer movement. The tabs component
receives fresh attributes, not the enclosing fieldset's classes and ID.

## 3. WMS and dataset visualisation

The optional all-products WMS selector appears only when every resource has
the same selectable layer-name set, irrespective of ordering. Selecting a
layer updates all products. Failed capability requests disable the shared
selector and display an error.

Search rows and dynamic landing pages can offer inline WMS and supported
OPeNDAP feature visualisations. These HTMX fragments have independent mounts
and do not replace the primary search map.
Single-product WMS maps initially fit the product extent. Changing the layer
preserves the user's zoom and map position so layers can be compared over the
same area of interest.

## 4. Regression coverage

[`MetsisMapsTest.php`](../tests/src/FunctionalJavascript/MetsisMapsTest.php)
checks rendered canvases/heights, bbox tab switching, AJAX replacement, and
simultaneous View/block maps against an isolated backend. Its responsive
scenario checks real default-row markup, search-button alignment, date-field
bounds, mobile abstract expansion, and hidden-tooltip overflow across Stark,
Olivero, and Claro at 1440, 1024, 820, 769, 768, 390, and 320px.
See [browser testing](development/README.md#4-browser-tests-and-ci) for
WebDriver prerequisites and CI diagnostics.

The bbox layout scenario covers all four modes, with and without an exposed
predicate, across Solo, Olivero, and Stark at 1440, 390, and 320px. It checks
control containment, separation from the next filter, tab switching, real
canvases, and less than 1px map/fieldset width change during pointer movement.
