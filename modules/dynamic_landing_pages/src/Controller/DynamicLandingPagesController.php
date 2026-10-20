<?php

declare(strict_types=1);

namespace Drupal\dynamic_landing_pages\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\metsis_drupal\Service\CatalogButtonBuilder;
use Drupal\metsis_drupal\Service\DatasetVisualisationBuilder;
use Drupal\metsis_drupal\Service\LeafletMapRenderer;
use Drupal\metsis_drupal\Service\MetadataDocumentNormalizer;
use Drupal\metsis_drupal\Service\SolrDocumentLoader;
use Drupal\metsis_drupal\Utility\MetsisSolrUtilities;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders standalone dataset landing pages from Solr.
 *
 * The route receives a UUID-style identifier. The controller prepends the
 * configured naming authority (e.g. "no.met.adc") to form the full Solr
 * document ID, loads the document via SolrDocumentLoader, normalizes it
 * through MetadataDocumentNormalizer, and injects structured <head> metadata
 * (JSON-LD, Open Graph, Dublin Core, citation tags, canonical URL).
 *
 * Normalized data is stored in the dedicated "dynamic_landing_pages" cache bin
 * keyed by the full document ID. On each request the controller performs a
 * lightweight Solr timestamp check; the cache is only rebuilt when either the
 * Solr "timestamp" field or "last_metadata_updated_date" has changed.
 */
final class DynamicLandingPagesController extends ControllerBase {

  /**
   * JSON transformer fields appended to the wildcard field list.
   */
  private const JSON_TRANSFORMERS = [
    'personnel_json:[json]',
    'data_access_json:[json]',
    'platform_json:[json]',
    'related_information_json:[json]',
    'last_metadata_update_json:[json]',
    'dataset_citation_json:[json]',
  ];

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly SolrDocumentLoader $documentLoader,
    private readonly MetadataDocumentNormalizer $normalizer,
    private readonly LeafletMapRenderer $leafletMapRenderer,
    private readonly CacheBackendInterface $cache,
    private readonly CatalogButtonBuilder $catalogButtonBuilder,
    private readonly DatasetVisualisationBuilder $visualisationBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('metsis_drupal.solr_document_loader'),
      $container->get('metsis_drupal.metadata_document_normalizer'),
      $container->get('metsis_drupal.leaflet_map_renderer'),
      $container->get('cache.dynamic_landing_pages'),
      $container->get('metsis_drupal.catalog_button_builder'),
      $container->get('metsis_drupal.dataset_visualisation_builder'),
    );
  }

  /**
   * Renders the dataset landing page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param string $id
   *   The UUID / local identifier (without naming authority prefix).
   *
   * @return array
   *   Render array for the landing page.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When no Solr document matches the constructed identifier.
   */
  public function getLandingPage(Request $request, string $id): array {

    // Dynamically clear the route title default to suppress the block markup.
    if ($route = $request->attributes->get(RouteObjectInterface::ROUTE_OBJECT)) {
      $route->setDefault('_title', '');
    }

    $full_id   = $this->buildFullId($id);
    $cache_key = 'dataset:v3:' . $full_id;
    $solr_id   = MetsisSolrUtilities::toSolrId($full_id);

    // Check the custom cache bin and validate Solr timestamps.
    $cached = $this->cache->get($cache_key);
    if ($cached !== FALSE) {
      $ts_doc = $this->documentLoader->loadDocumentById(
        $solr_id,
        ['timestamp', 'last_metadata_updated_date'],
      );
      if ($ts_doc === NULL) {
        throw new NotFoundHttpException('Dataset not found.');
      }
      $current_ts  = (string) ($ts_doc['timestamp'] ?? '');
      $current_lmu = (string) ($ts_doc['last_metadata_updated_date'] ?? '');
      if (
        $current_ts === $cached->data['solr_timestamp'] &&
        $current_lmu === $cached->data['solr_last_update']
      ) {
        return $this->buildRenderArray($cached->data, $id, $solr_id);
      }
      // One or both timestamps changed — invalidate and rebuild.
      $this->cache->delete($cache_key);
    }

    // Full document load with all JSON + geo field transformers.
    $doc = $this->documentLoader->loadDocumentById(
      $solr_id,
      array_merge(['*'], self::JSON_TRANSFORMERS),
    );
    if ($doc === NULL) {
      throw new NotFoundHttpException('Dataset not found.');
    }

    $title         = (string) ($doc['title'] ?? $doc['title_en'] ?? $doc['metadata_identifier'] ?? $id);
    $abstract_text = (string) ($doc['abstract'] ?? $doc['abstract_en'] ?? '');

    $parent_child = $this->resolveParentChildInfo($doc);
    $summary = $this->normalizer->buildSummary($doc);
    $summary = $this->normalizer->mergeRelatedDatasetIntoSummary($summary, $parent_child);

    $data = [
      'title'            => $title,
      'abstract_text'    => $abstract_text,
      'summary'          => $summary,
      'sections'         => $this->normalizer->buildSections($doc, $this->leafletMapRenderer),
      'metadata_updates' => $this->normalizer->buildMetadataUpdates($doc),
      'parent_child'     => $parent_child,
      'doc_meta'         => $this->extractHeadMetaFields($doc),
      'solr_timestamp'   => (string) ($doc['timestamp'] ?? ''),
      'solr_last_update' => (string) ($doc['last_metadata_updated_date'] ?? ''),
      'raw_solr_doc'     => $doc,
      'export_form'      => NULL,
    ];
    // Cache permanently; invalidate via per-dataset cache tag.
    $this->cache->set(
      $cache_key,
      $data,
      Cache::PERMANENT,
      ['dynamic_landing_pages:dataset:' . $full_id],
    );

    return $this->buildRenderArray($data, $id, $solr_id);
  }

  /**
   * Custom access check for the landing page route.
   *
   * Validates the id parameter format. The route also enforces
   * 'access content' permission, so no redundant check is needed here.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param string $id
   *   The local identifier.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   Access result.
   */
  public function access(AccountInterface $account, string $id): AccessResult {
    return AccessResult::allowedIf(
      preg_match('/^[A-Za-z0-9_.\\-]+$/', $id) === 1
    )->addCacheContexts(['url.path']);
  }

  /**
   * Constructs the full Solr document identifier.
   *
   * Combines the configured naming authority with the UUID route parameter.
   */
  private function buildFullId(string $id): string {
    $naming_authority = (string) ($this->config('dynamic_landing_pages.settings')->get('naming_authority') ?? 'no.met.adc');
    return rtrim($naming_authority, ':') . ':' . $id;
  }

  /**
   * Resolve parent/child collection info, loading the parent doc if needed.
   *
   * @param array<string, mixed> $document
   *   Solr document being rendered.
   *
   * @return array<string, mixed>
   *   Parent/child info for the landing page template.
   */
  private function resolveParentChildInfo(array $document): array {
    $parent_document = NULL;

    if (!empty($document['isChild'])) {
      $parent_identifier = MetsisSolrUtilities::firstValue($document['related_dataset'] ?? '');
      if ($parent_identifier !== '' && MetsisSolrUtilities::isValidIdentifier($parent_identifier)) {
        $parent_document = $this->documentLoader->loadDocumentById(
          MetsisSolrUtilities::toSolrId($parent_identifier),
          ['metadata_identifier', 'related_url_landing_page', 'title',
            'title_en', 'abstract', 'abstract_en', 'temporal_extent*',
          ],
        );
      }
    }

    return $this->normalizer->buildParentChildInfo($document, $parent_document);
  }

  /**
   * Assembles the render array from normalized (possibly cached) data.
   *
   * Sets max-age 0 to disable Drupal's render/page cache — the controller
   * manages caching via the dedicated bin.
   *
   * @param array $data
   *   Normalized data as stored in the cache bin.
   * @param string $id
   *   The local identifier (used to generate the canonical URL).
   * @param string $solr_id
   *   The full Solr document ID (used for the export form).
   *
   * @return array
   *   Render array for the landing page.
   */
  private function buildRenderArray(array $data, string $id, string $solr_id): array {
    $canonical_url = Url::fromRoute('dynamic_landing_pages.landing_page', ['id' => $id])
      ->setAbsolute()
      ->toString();

    // Null-coalesce for cache items written before this key existed.
    $parent_child = $data['parent_child'] ?? NULL;
    if (is_array($parent_child) && !empty($parent_child['is_parent']) && !empty($parent_child['catalog_identifier'])) {
      // Built fresh on every request (not cached) since it carries an
      // HTMX-bound render array.
      $parent_child['catalog_button'] = $this->catalogButtonBuilder->build($parent_child['catalog_identifier']);
    }
    $visualisations = $this->visualisationBuilder->build(
      $data['raw_solr_doc'],
      'landing-' . $solr_id,
      $solr_id,
      TRUE,
    );

    return [
      '#title'            => $data['title'],
      '#theme'            => 'dynamic_landing_page',
      '#abstract'         => [
        '#type'   => 'processed_text',
        '#text'   => $data['abstract_text'],
        '#format' => 'metsis_html',
      ],
      '#summary'          => $data['summary'],
      '#sections'         => $data['sections'],
      '#metadata_updates' => $data['metadata_updates'],
      '#parent_child'     => $parent_child,
      '#raw_solr_doc'      => $data['raw_solr_doc'],
      '#visualisations'    => $visualisations,
      '#export_form'      => [
        '#type' => 'container',
        '#children' => $this->formBuilder()->getForm('Drupal\metsis_drupal\Form\MetadataExportForm', $solr_id),
      ],
      '#attached'         => [
        'library'   => [
          'metsis_drupal/metsis_metadata_document',
          'metsis_drupal/metsis_map',
          'dynamic_landing_pages/dynamic_landing_page',
        ],
        'html_head' => $this->buildHeadMeta($data['doc_meta'], $canonical_url),
      ],
      '#cache'            => ['max-age' => 0],
    ];
  }

  /**
   * Extracts the subset of raw Solr fields needed for <head> metadata tags.
   *
   * Keeps the cache item lean by excluding large or rendering-specific fields.
   * JSON fields are pre-decoded to plain arrays.
   *
   * @param array $doc
   *   The full Solr document.
   *
   * @return array
   *   Subset of Solr fields suitable for storage and head meta building.
   */
  private function extractHeadMetaFields(array $doc): array {
    $fields = [
      'title', 'title_en', 'abstract', 'abstract_en', 'metadata_identifier',
      'thumbnail_url', 'use_constraint_resource', 'last_metadata_updated_date',
      'dataset_citation_doi', 'temporal_extent_start_date', 'temporal_extent_end_date',
      'geographic_extent_rectangle_north', 'geographic_extent_rectangle_south',
      'geographic_extent_rectangle_east', 'geographic_extent_rectangle_west',
      'keywords_gcmd', 'keywords_gemet', 'keywords_northemes', 'keywords_gcmdplt',
    ];

    $meta = [];
    foreach ($fields as $field) {
      if (isset($doc[$field])) {
        $meta[$field] = $doc[$field];
      }
    }

    // Ensure JSON-originated fields are decoded arrays, not raw strings.
    foreach (['personnel_json', 'data_access_json'] as $json_field) {
      if (isset($doc[$json_field])) {
        $val               = $doc[$json_field];
        $meta[$json_field] = is_array($val) ? $val : (json_decode((string) $val, TRUE) ?? []);
      }
    }

    return $meta;
  }

  /**
   * Builds the html_head attachment list for all structured <head> metadata.
   *
   * Each entry is a [render_array, unique_key] tuple as expected by
   * #attached['html_head'].
   *
   * @param array $doc
   *   Subset of Solr fields (from extractHeadMetaFields).
   * @param string $canonical_url
   *   The absolute canonical URL for this landing page.
   *
   * @return list<array>
   *   Head tag entries.
   */
  private function buildHeadMeta(array $doc, string $canonical_url): array {
    $tags        = [];
    $title       = (string) ($doc['title'] ?? $doc['title_en'] ?? '');
    $description = mb_substr((string) ($doc['abstract'] ?? $doc['abstract_en'] ?? ''), 0, 300);
    $metadata_id = (string) ($doc['metadata_identifier'] ?? '');
    $doi         = $this->scalarField($doc['dataset_citation_doi'] ?? '');
    $thumbnail   = (string) ($doc['thumbnail_url'] ?? '');
    $last_update = (string) ($doc['last_metadata_updated_date'] ?? '');
    $keywords    = $this->mergeKeywords($doc);
    $personnel   = $doc['personnel_json'] ?? [];

    // Canonical URL.
    $tags[] = [
      ['#type' => 'html_tag', '#tag' => 'link', '#attributes' => ['rel' => 'canonical', 'href' => $canonical_url]],
      'dynamic_landing_pages_canonical',
    ];

    // Open Graph.
    foreach ([
      'og:type'        => 'article',
      'og:title'       => $title,
      'og:description' => $description,
      'og:url'         => $canonical_url,
    ] as $property => $content) {
      if ($content !== '') {
        $tags[] = [
          ['#type' => 'html_tag', '#tag' => 'meta', '#attributes' => ['property' => $property, 'content' => $content]],
          'dynamic_landing_pages_' . str_replace(':', '_', $property),
        ];
      }
    }
    if ($thumbnail !== '') {
      $tags[] = [
        ['#type' => 'html_tag', '#tag' => 'meta', '#attributes' => ['property' => 'og:image', 'content' => $thumbnail]],
        'dynamic_landing_pages_og_image',
      ];
    }

    // Dublin Core.
    $identifier = $doi !== '' ? $doi : $metadata_id;
    foreach ([
      'DC.title'       => $title,
      'DC.description' => $description,
      'DC.identifier'  => $identifier,
      'DC.date'        => $last_update,
      'DC.coverage'    => $this->buildSpatialCoverageString($doc),
    ] as $name => $content) {
      if ($content !== '') {
        $tags[] = [
          ['#type' => 'html_tag', '#tag' => 'meta', '#attributes' => ['name' => $name, 'content' => $content]],
          'dynamic_landing_pages_' . strtolower(str_replace('.', '_', $name)),
        ];
      }
    }
    foreach ($keywords as $i => $kw) {
      $tags[] = [
        ['#type' => 'html_tag', '#tag' => 'meta', '#attributes' => ['name' => 'DC.subject', 'content' => $kw]],
        'dynamic_landing_pages_dc_subject_' . $i,
      ];
    }

    // DC.creator — investigators only.
    $creator_idx = 0;
    foreach ($personnel as $person) {
      if (
        in_array(strtolower($person['role'] ?? ''), ['investigator', 'principalinvestigator', 'author', 'creator'], TRUE) &&
        !empty($person['name'])
      ) {
        $tags[] = [
          [
            '#type'       => 'html_tag',
            '#tag'        => 'meta',
            '#attributes' => ['name' => 'DC.creator', 'content' => $person['name']],
          ],
          'dynamic_landing_pages_dc_creator_' . $creator_idx,
        ];
        $creator_idx++;
      }
    }

    // Citation meta tags.
    if ($title !== '') {
      $tags[] = [
        [
          '#type'       => 'html_tag',
          '#tag'        => 'meta',
          '#attributes' => ['name' => 'citation_title', 'content' => $title],
        ],
        'dynamic_landing_pages_citation_title',
      ];
    }
    $author_idx = 0;
    foreach ($personnel as $person) {
      if (!empty($person['name'])) {
        $tags[] = [
          [
            '#type'       => 'html_tag',
            '#tag'        => 'meta',
            '#attributes' => ['name' => 'citation_author', 'content' => $person['name']],
          ],
          'dynamic_landing_pages_citation_author_' . $author_idx,
        ];
        $author_idx++;
      }
    }
    if ($doi !== '') {
      $tags[] = [
        ['#type' => 'html_tag', '#tag' => 'meta', '#attributes' => ['name' => 'citation_doi', 'content' => $doi]],
        'dynamic_landing_pages_citation_doi',
      ];
    }
    $pub_date = $this->scalarField($doc['temporal_extent_start_date'] ?? '');
    if ($pub_date !== '') {
      $tags[] = [
        [
          '#type'       => 'html_tag',
          '#tag'        => 'meta',
          '#attributes' => ['name' => 'citation_publication_date', 'content' => $pub_date],
        ],
        'dynamic_landing_pages_citation_pub_date',
      ];
    }

    // Meta description (for search engines).
    if ($description !== '') {
      $tags[] = [
        [
          '#type' => 'html_tag',
          '#tag' => 'meta',
          '#attributes' => ['name' => 'description', 'content' => $description],
        ],
        'dynamic_landing_pages_meta_description',
      ];
    }

    // JSON-LD (schema.org/Dataset) — always last.
    $tags[] = $this->buildJsonLd($doc, $canonical_url, $keywords);

    return $tags;
  }

  /**
   * Builds the JSON-LD <script> tag for schema.org/Dataset.
   *
   * @param array $doc
   *   Subset of Solr fields.
   * @param string $canonical_url
   *   The absolute canonical URL.
   * @param list<string> $keywords
   *   Pre-merged keyword list.
   *
   * @return array{array, string}
   *   A [render_array, unique_key] tuple.
   */
  private function buildJsonLd(array $doc, string $canonical_url, array $keywords): array {
    $data = [
      '@context' => 'https://schema.org/',
      '@type'    => 'Dataset',
      'name'     => (string) ($doc['title'] ?? $doc['title_en'] ?? ''),
      'url'      => $canonical_url,
    ];

    $description = (string) ($doc['abstract'] ?? $doc['abstract_en'] ?? '');
    if ($description !== '') {
      $data['description'] = $description;
    }

    $doi                = $this->scalarField($doc['dataset_citation_doi'] ?? '');
    $data['identifier'] = $doi !== '' ? $doi : (string) ($doc['metadata_identifier'] ?? '');

    if ($keywords !== []) {
      $data['keywords'] = $keywords;
    }

    $start = $this->scalarField($doc['temporal_extent_start_date'] ?? '');
    $end   = $this->scalarField($doc['temporal_extent_end_date'] ?? '');
    if ($start !== '') {
      $data['temporalCoverage'] = $end !== '' ? "{$start}/{$end}" : $start;
    }

    $north = $doc['geographic_extent_rectangle_north'] ?? NULL;
    $south = $doc['geographic_extent_rectangle_south'] ?? NULL;
    $east  = $doc['geographic_extent_rectangle_east'] ?? NULL;
    $west  = $doc['geographic_extent_rectangle_west'] ?? NULL;
    if ($north !== NULL && $south !== NULL && $east !== NULL && $west !== NULL) {
      $data['spatialCoverage'] = [
        '@type' => 'Place',
        'geo'   => [
          '@type' => 'GeoShape',
          'box'   => "{$south} {$west} {$north} {$east}",
        ],
      ];
    }

    $creators  = [];
    $personnel = $doc['personnel_json'] ?? [];
    foreach ($personnel as $person) {
      if (!in_array(strtolower($person['role'] ?? ''), ['investigator', 'principalinvestigator', 'author', 'creator'], TRUE)) {
        continue;
      }
      $person_type = (!empty($person['type']) && strtolower($person['type']) !== 'person') ? 'Organization' : 'Person';
      $creator     = ['@type' => $person_type];
      if (!empty($person['name'])) {
        $creator['name'] = $person['name'];
      }
      if (!empty($person['organisation'])) {
        $creator['affiliation'] = ['@type' => 'Organization', 'name' => $person['organisation']];
      }
      if (!empty($person['name_uri']) && str_contains((string) $person['name_uri'], 'orcid.org')) {
        $creator['sameAs'] = $person['name_uri'];
      }
      $creators[] = $creator;
    }
    if ($creators !== []) {
      $data['creator'] = count($creators) === 1 ? $creators[0] : $creators;
    }

    $license_url = (string) ($doc['use_constraint_resource'] ?? '');
    if ($license_url !== '') {
      $data['license'] = $license_url;
    }

    $date_modified = (string) ($doc['last_metadata_updated_date'] ?? '');
    if ($date_modified !== '') {
      $data['dateModified'] = $date_modified;
    }

    $distributions = [];
    foreach ($doc['data_access_json'] ?? [] as $access) {
      if (empty($access['resource'])) {
        continue;
      }
      $dist = ['@type' => 'DataDownload', 'contentUrl' => $access['resource']];
      if (!empty($access['type'])) {
        $dist['encodingFormat'] = $access['type'];
      }
      if (!empty($access['description'])) {
        $dist['name'] = $access['description'];
      }
      $distributions[] = $dist;
    }
    if ($distributions !== []) {
      $data['distribution'] = $distributions;
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return [
      [
        '#type'       => 'html_tag',
        '#tag'        => 'script',
        '#value'      => $json !== FALSE ? $json : '{}',
        '#attributes' => ['type' => 'application/ld+json'],
      ],
      'dynamic_landing_pages_jsonld',
    ];
  }

  /**
   * Merges keywords from all known vocabulary Solr fields into a flat list.
   *
   * @param array $doc
   *   Subset of Solr fields.
   *
   * @return list<string>
   *   Deduplicated keyword strings.
   */
  private function mergeKeywords(array $doc): array {
    $keywords = [];
    foreach (['keywords_gcmd', 'keywords_gemet', 'keywords_northemes', 'keywords_gcmdplt'] as $field) {
      if (!empty($doc[$field]) && is_array($doc[$field])) {
        $keywords = array_merge($keywords, $doc[$field]);
      }
    }
    return array_values(array_unique($keywords));
  }

  /**
   * Returns a human-readable spatial coverage string from bounding box fields.
   *
   * @param array $doc
   *   Subset of Solr fields.
   *
   * @return string
   *   Coverage string, e.g. "Lat: 60.0 to 70.0, Lon: 10.0 to 20.0".
   */
  private function buildSpatialCoverageString(array $doc): string {
    $parts = [];
    $north = $doc['geographic_extent_rectangle_north'] ?? NULL;
    $south = $doc['geographic_extent_rectangle_south'] ?? NULL;
    $east  = $doc['geographic_extent_rectangle_east'] ?? NULL;
    $west  = $doc['geographic_extent_rectangle_west'] ?? NULL;
    if ($north !== NULL && $south !== NULL) {
      $parts[] = "Lat: {$south} to {$north}";
    }
    if ($east !== NULL && $west !== NULL) {
      $parts[] = "Lon: {$west} to {$east}";
    }
    return implode(', ', $parts);
  }

  /**
   * Returns the scalar string value from a potentially multi-value Solr field.
   *
   * Multi-value Solr fields are returned as arrays; single-value fields as
   * scalars. This helper normalizes both cases to a plain string.
   *
   * @param mixed $value
   *   The raw Solr field value.
   *
   * @return string
   *   The first element if array, the value itself otherwise.
   */
  private function scalarField(mixed $value): string {
    if (is_array($value)) {
      return (string) ($value[0] ?? '');
    }
    return (string) ($value ?? '');
  }

}
