# Metadata and extensions

[Back to README](../README.md#4-functionality-and-documentation) |
[Installation](installation.md)

## 1. Metadata pages and dialogs

Metadata documents provide normalized summaries, personnel, citations, data
access, vocabularies, and geography from Solr documents.
HTMX progressively enhances metadata links into native dialogs while preserving
the full-page endpoint.

| Feature              | Route                                 |
| -------------------- | ------------------------------------- |
| METSIS settings      | `/admin/config/metno/metsis-drupal`   |
| Status report        | `/admin/reports/metsis-status-report` |
| Metadata page        | `/metsis/metadata/{id}`               |
| HTMX metadata dialog | `/metsis/metadata/htmx/{id}`          |
| Metadata export      | `/metsis/metadata/export/*`           |
| Bokeh service/form   | `/services/bokeh-plot/*`              |

See [`metsis_drupal.routing.yml`](../metsis_drupal.routing.yml) for exact
route definitions and access requirements.
Data-access links use theme button styling and announce file/new-tab behavior.

### Data center

Metadata pages, search metadata dialogs, and dynamic landing pages share a
**Data center** section immediately after Personnel (first when Personnel is
absent). The normalizer reads the stored Solr fields `data_center_long_name`,
`data_center_short_name`, `data_center_name`, and `data_center_url`; no additional
Solr request is needed.

The primary label uses the long name, then the combined name, then the short
name. A distinct, shorter short name is displayed as subtext. Whitespace and
case differences do not create duplicate labels; abbreviations already present
as complete labels in the primary name, such as `(NIRD)`, are not repeated.
No fixed abbreviation-length cutoff or generated acronyms are used.

Read-only sampling of 32 distinct combined names found useful labels such as
`MET Norway` and `NO/MET/ADC`, but also full names duplicated across all three
fields. The combined field repeated the short field wherever the latter was
present, so it is a fallback rather than an abbreviation-repair mechanism.

Only absolute HTTP(S) resources become links. Links open a new window/tab with
`noopener noreferrer` and translated screen-reader notification. Names without
a usable URL remain plain text; valid URL-only entries use the translated label
“Data center”. An entirely empty entry is omitted. Both templates use the
[`data_center` component](../components/data_center).

## 2. Export and vocabularies

Metadata exports use MMD and XSLT-oriented transformations.
Vocabulary enrichment uses cached MMD SKOS data for labels and popovers.
These features require MMD resources under `vendor/metno/mmd`.

See [MetVocabService usage](met-vocab-service-usage.md) for dependency injection,
lookup results, and language fallbacks, and [architecture](architecture.md)
for rendering/export services.

## 3. Dynamic landing pages

[`dynamic_landing_pages`](../modules/dynamic_landing_pages) renders standalone
dataset pages directly from Solr, including metadata summaries, personnel cards,
parent/child context, map previews, export, and visualisation controls.
It provides a document-like presentation consistent with the search metadata UI.

Normalized landing-page cache keys use `dataset:v2:` so entries cached before
data-center support are rebuilt even when source timestamps have not changed.
Existing dataset cache tags and timestamp-based refresh behavior are preserved.

## 4. NBS Extensions

[`nbs_extensions`](../modules/nbs_extensions) adds NetCDF on-demand requests.
Configure its backend at `/admin/config/metno/nbs-extensions`.
Rows offer **Request "CF-NetCDF file"** for configured supported products
without an existing HTTP `.nc` resource.

Anonymous visitors can log in through core Drupal username/password
authentication inside the dialog. Successful login loads the protected request
form without reloading search. Normal Form API submission remains the
no-JavaScript fallback.

See the [NBS extension guide](../modules/nbs_extensions/README.md) for product
configuration, backend payloads, access, authentication, and request behavior.
