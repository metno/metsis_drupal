# Search and Views

[Back to README](../README.md#4-functionality-and-documentation) |
[Installation](installation.md)

## 1. Query modes

Views using `search_api_index_metsis` receive METSIS query preparation without
requiring a query tag. `inactive` selects only Inactive metadata; `flat_search`
matches datasets directly, omitting child joins/counts while retaining parent
metadata. Other indexes remain unchanged.

## 2. Layout and templates

New Views retain the normal Views layout. Add the `metsis` query tag to opt into
the shared results layout. The original `metsis_search` View uses that layout.
Themes can override:

- `views-view--metsis-VIEW-ID--DISPLAY-ID.html.twig`
- `views-exposed-form--metsis-VIEW-ID--DISPLAY-ID.html.twig`

Page-level messages and metadata dialog targets are independent of these
templates and remain outside AJAX-replaced View markup.

Under **Advanced → Other → METSIS filter sidebar**, set a positive width in
`rem`, `em`, or `px` (default `24rem`). The setting supports default-display
inheritance and per-display overrides on METSIS-index Views. Only the shared
results layout consumes the width; ordinary layouts are unchanged.
The shared layout wraps filters above results when its available width cannot
fit the configured sidebar, a 26rem results column, and the gap. This responds
to theme sidebars and gutters, not just the viewport. When wrapped, both
sections fill the available width. Custom templates can consume the
`--metsis-sidebar-width` CSS property.

Default result rows stack their preview, abstract, and badges below a 44rem
**row** width, including narrow desktop columns. Long titles and license URLs
wrap, collection buttons fit their controls, and abstract expansion stays
accessible on mobile. Long active-filter labels wrap without pushing
no-results pages beyond the viewport. Temporal fields also stack within narrow filter
containers. The search-box component resets theme form-item/action margins
so its search and clear buttons remain aligned with the input.

Choose **Custom** row style and supply a machine name for
`metsis-search-row-custom--MACHINE-NAME.html.twig`. Replace underscores with
hyphens in the filename. Missing overrides and legacy Detailed/Custom styles
fall back to Default. See [template organization](architecture.md#3-templates-and-components).

The row's **Component settings** control the rendered SDC components:
shared license/collection icon width (minimum 16px), DOI identifier, icon size
and logo color, and temporal extent short (`start to end`) or long notation,
compact labels, date icon and icon ID. Views stores these as flat row options
(for example `temporal_extent_short_notation`); the nested form fieldsets are
flattened on submit.

### Geographic filter summaries

The bounding-box filter supports both the Solr `bbox` field and the
`geospatial_bounds3d` RPT field. Its exposed **Filter identifier** can differ
from the Views handler ID or Solr field name; summary input lookup uses that
configured identifier.

The summary displays one spatial predicate with all four coordinates, including
zero values. Its remove link clears only those coordinates, including hidden
map-only inputs, and preserves other filters and the selected predicate.
Removal works with Views AJAX and ordinary page submissions.

### Temporal filter layout

The date-range filter places an exposed predicate on a full-width row above
the start/end inputs. Date fields wrap according to available width; a fixed
predicate omits the dropdown without changing the date inputs. Styling is
attached by the plugin and uses semantic classes, so renamed filter/operator
identifiers and exposed-form blocks receive the same layout.

Layout regression coverage includes Solo, Olivero, and Stark at 1440, 390, and
320px, with exposed/fixed predicates, custom identifiers, saved date values,
and Views AJAX refreshes.

## 3. Exposed-form block

The **METSIS search exposed form** block renders filters from the
`metsis_search` View's `results` display and submits to that View wherever the
block is placed.

Configure:

- Which filters and the optional secondary Search button are shown.
- Filter weights and automatic or explicit column placement.
- One to three columns, stacking on narrow screens.
- Compact presentation, independent predicate visibility, and bbox height overrides.

New blocks initially show search, temporal, and geographic filters plus the
secondary Search button. Existing selections are preserved. Weight/column
controls appear only for selected elements and retain their preferences when
temporarily hidden. Sort/pager controls are omitted.
The block submits through its Search buttons, not filter-change autosubmit.

Hidden exposed predicates submit **Intersects**. A non-exposed predicate keeps
the View's fixed operator. Hiding is unavailable when the allowed operators
exclude Intersects.

The bbox filter height is 150–1000px (250px default). Blocks inherit it unless
explicitly overridden; an override affects only that block's map. See
[map sizing](maps.md#2-bounding-box-filter).

For a Solo left-sidebar search block, use `solo-col-1-2` for a 1:2
sidebar/content ratio. Block column count controls its internal layout, not
the theme's region widths.
