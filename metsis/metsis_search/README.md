# Metsis Search
The Metsis Search module for Drupal 8 are based on the contributed modules, search_api, search_api_solr, search_api_sorts, search_api_autocomplete and facets, for the search interface. The search interface are search_api views.

## Search API views created by METSIS search
* Metsis Search (metsis_search). The main search view. [search_api view].
* Metsis Elements (metsis_elements). Search view for child (level 2) elements. [search_api contextual view].
* Metsis Metadata Details (metsis_metadata_details). View to show additional Metadata details for a given dataset. [search_api contextual view].
* Metsis Metadata Details Parent (metsis_metadata_details_parent). View to show extended metadata information for a parent dataset. [search_api contextual view].

## Endpoints
* /metsis/search - metsis_search view endpoint.
* /metsis/elements/%/search - metsis_elements view endpoint.
* /metsis/metadata - metsis_metadata_details view endpoint.
* /metsis/metadata/parent - metsis_metadata_details_parent view endpoint.
* /metsis/search/map - Ajax callback for Search map bbox filter. (/metsis/search/map?ttlat=&tllon=&brlat&=brlon&proj=)
* /metsis/elements/count - Ajax callback for children count (/metsis/elements/count?metadata_identifier=)
* /metsis/metadata/export/{id} - Form with export options for exporting metadata given id.


## Facets
Facets for search are provided by the facets module. Facets are tied to a specific search view. Configured
facets are blocks that can be placed in regions on search page.

## Uninstall

Uninstalling Metsis Search deletes its shipped configuration entities through
Drupal's entity storage API. This includes Search API sort fields, autocomplete
searches, Views, facet sources, indexes, servers, blocks, and the Solr date format.
Drupal removes module-prefixed settings automatically.

All facets attached to the module's facet sources are removed, including facets
created manually after installation. Facet block placements and exposed-form or
View block placements are deleted across every theme, including disabled blocks
and placements for removed displays. Sort fields attached to the module's Views
and autocomplete searches attached to its indexes are also removed.

Facets, blocks, sort fields, and autocomplete searches for unrelated sources,
Views, and indexes are retained unless Drupal identifies a dependency requiring
their removal or update. Indexes attached to the module's servers are removed as
well. Solr documents are preserved by marking indexes read-only before deleting
their entities; local tracker cleanup still runs.
During configuration synchronization, the config importer controls entity changes
instead of this hook. Export the cleanup configuration before synchronizing it
to another site.


## TODO
* Clean up code (remove unused code, not used configuration etc)
* Update this documentation with endpoints etc.
* Integrate result map with children (elements) view.
