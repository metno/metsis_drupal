<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\metsis_drupal\Utility\MetsisHelper;
use Drupal\metsis_drupal\LoggerTrait;

/**
 * This class handles the rendering of a dataset result row.
 *
 * Used by MetsisSearchRow views row plugin.
 */
final class ResultRowRenderer {

  use LoggerTrait;
  use StringTranslationTrait;

  /**
   * Metsis search helper service.
   *
   * @var \Drupal\metsis_drupal\Utility\MetsisHelper
   */
  protected MetsisHelper $metsisHelper;

  /**
   * The metsis_drupal module extension.
   *
   * @var \Drupal\Core\Extension\Extension
   */
  protected $moduleExtension;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The config provider service.
   *
   * @var \Drupal\metsis_drupal\Service\ConfigProvider
   */
  protected ConfigProvider $configProvider;

  /**
   * The leaflet map renderer service.
   *
   * @var \Drupal\metsis_drupal\Service\LeafletMapRenderer
   */
  protected LeafletMapRenderer $leafletMapRenderer;

  /**
   * The Met vocabulary service.
   *
   * @var \Drupal\metsis_drupal\Service\MetVocabServiceInterface
   */
  protected MetVocabServiceInterface $metVocabService;

  /**
   * The markdown detector service.
   *
   * @var \Drupal\metsis_drupal\Service\MarkdownDetectorInterface
   */
  protected MarkdownDetectorInterface $markdownDetector;

  /**
   * Constructs a ResultRowRenderer object.
   */
  public function __construct(
    MetsisHelper $metsis_helper,
    ModuleHandlerInterface $module_handler,
    ConfigProvider $config_provider,
    LeafletMapRenderer $leaflet_map_renderer,
    MetVocabServiceInterface $met_vocab_service,
    MarkdownDetectorInterface $markdown_detector,
  ) {
    $this->metsisHelper = $metsis_helper;
    $this->moduleExtension = $module_handler->getModule('metsis_drupal');
    $this->moduleHandler = $module_handler;
    $this->configProvider = $config_provider;
    $this->leafletMapRenderer = $leaflet_map_renderer;
    $this->metVocabService = $met_vocab_service;
    $this->markdownDetector = $markdown_detector;
  }

  /**
   * Render abstract field using appropriate markup format.
   *
   * Automatically detects markdown content and uses metsis_markdown filter
   * when markdown module is installed and metsis_markdown filter exists.
   * Falls back to metsis_html for non-markdown content or if markdown
   * rendering fails.
   *
   * @param string $abstract_text
   *   The raw abstract text from Solr.
   *
   * @return array
   *   Processed text render array for the abstract field.
   */
  private function renderAbstractField(string $abstract_text): array {
    if (empty($abstract_text)) {
      return [
        '#type' => 'processed_text',
        '#text' => '',
        '#format' => 'metsis_html',
      ];
    }

    // Check if markdown module is installed and filter format exists.
    $markdown_available = $this->moduleHandler->moduleExists('markdown');

    // Detect markdown patterns in the abstract.
    $is_markdown = $markdown_available && $this->markdownDetector->detectMarkdown($abstract_text);

    // Choose filter format based on detection.
    $format = $is_markdown ? 'metsis_markdown' : 'metsis_html';

    // Linkify bare URLs before text filtering for markdown-enabled records.
    if ($is_markdown) {
      $abstract_text = $this->metsisHelper->linkify($abstract_text);
    }

    return [
      '#type' => 'processed_text',
      '#text' => $abstract_text,
      '#format' => $format,
    ];
  }

  /**
   * Render a metsis result row, based on the row plugin configuration.
   *
   * @param array $solr_doc
   *   The raw solr document result.
   * @param array $options
   *   The row plugin configuration options.
   * @param array $highlighted
   *   The highlighted snippets returned.
   *
   * @return array
   *   The constructed solr feilds render array.
   */
  public function renderRow(array $solr_doc, array $options, array $highlighted): array {
    $fields = [];
    $icon_width = max(16, (int) ($options['component_icon_width'] ?? 88));

    // Handle title. Use highlighted field if available.
    $fields['title'] = [
      '#markup' => $solr_doc['title'] ?? '',
    ];
    if (!empty($highlighted['title_hl'])) {
      $fields['title'] = [
        '#markup' => $highlighted['title_hl'][0],
      ];
    }
    // Handle abstract/description. Use highlighted field if available.
    $fields['metadata_identifier'] = $solr_doc['metadata_identifier'] ?? '';
    $fields['id'] = $this->metsisHelper->toSolrId($solr_doc['metadata_identifier']);

    // Render abstract with markdown detection support.
    $abstract_text = $solr_doc['abstract'] ?? '';
    if (!empty($highlighted['abstract_hl'])) {
      $abstract_text = $highlighted['abstract_hl'][0];
    }
    $fields['abstract'] = $this->renderAbstractField($abstract_text);

    // Labding page URL.
    $fields['landing_page'] = $solr_doc['related_url_landing_page'] ?? '';

    // Build leaflet map if geometry is available.
    if (!empty($solr_doc['geometry_geojson'])) {
      $fields['leaflet_map'] = $this->leafletMapRenderer->buildLeafletMap($solr_doc['geometry_geojson'], '250px', $solr_doc['id']);
    }
    if (!empty($solr_doc['use_constraint_identifier'])) {
      $fields['license_icon'] = $this->getLicenseIconMarkup(
        $solr_doc['use_constraint_identifier'],
        $icon_width,
      );
    }
    if (!empty($solr_doc['use_constraint_license_text'])) {
      $fields['license_text'] = [
        '#markup' => $this->metsisHelper->linkify($solr_doc['use_constraint_license_text']),
        '#attributes' => [
          'class' => ['metsis-search-license-text'],
        ],
      ];
    }
    if (!empty($solr_doc['isParent']) && $solr_doc['isParent'] == TRUE) {
      $fields['parent'] = $this->getCollectionIconMarkup(
        $solr_doc['metadata_identifier'],
        $icon_width,
      );
      if (isset($solr_doc['found_children'], $solr_doc['total_children'])) {
        $fields['children_count'] = $this->getChildDatasetCountMarkup($solr_doc);
      }
    }
    $parent = $solr_doc['parent']['docs'][0] ?? NULL;
    if (is_array($parent)) {
      $fields['parent_metadata'] = $parent;
    }

    if (!empty($solr_doc['dataset_citation_doi'])) {
      $var = $solr_doc['dataset_citation_doi'];
      $doi_uri = is_string($var) ? $var : (is_array($var) ? reset($var) : NULL);
      if (NULL !== $doi_uri) {
        $fields['doi_icon'] = $this->getDoiIconMarkup(
          $doi_uri,
          (bool) ($options['doi_show_identifier'] ?? FALSE),
          (bool) ($options['doi_logo_color'] ?? TRUE),
          max(12, (int) ($options['doi_icon_size'] ?? 24)),
        );
      }
    }

    if (!empty($solr_doc['thumbnail_url'])) {
      $fields['thumbnail'] = [
        '#theme' => 'image',
        '#uri' => $solr_doc['thumbnail_url'],
        '#alt' => "A WMS thumbnail",
        '#title' => "A ",
        '#attributes' => [
          'class' => ['metsis-search-thumbnail'],
          'loading' => 'lazy',
        ],
      ];

      if (!empty($options['thumbnail_link_to_wms']) && !empty($solr_doc['data_access_url_ogc_wms'])) {
        $wms_url = Url::fromRoute('metsis_drupal.wms', ['id' => $fields['id']])->toString();
        $title = Html::escape((string) $this->t('Visualise WMS layers'));
        $fields['thumbnail']['#prefix'] = '<a href="' . Html::escape($wms_url) . '" title="' . $title . '">';
        $fields['thumbnail']['#suffix'] = '</a>';
      }
    }

    $fields['last_metadata_update'] = $this->getLatestMetadataUpdate($solr_doc);
    $icon_id = trim((string) ($options['temporal_extent_icon_id'] ?? ''));
    $fields['temporal_extent'] = $this->getTemporalExtentMarkup(
      $solr_doc,
      (bool) ($options['temporal_extent_add_icon'] ?? TRUE),
      $icon_id !== '' ? $icon_id : 'date-time',
      (bool) ($options['temporal_extent_short_notation'] ?? FALSE),
      (bool) ($options['temporal_extent_compact_labels'] ?? TRUE),
    );

    return $fields;
  }

  /**
   * Returns the latest value from last_metadata_update_datetime.
   *
   * Keeps source multivalue data in $solr_doc untouched and only provides a
   * derived single value for template rendering.
   *
   * @param array $solr_doc
   *   The raw Solr document.
   *
   * @return string
   *   The latest datetime string, or empty string when unavailable.
   */
  private function getLatestMetadataUpdate(array $solr_doc): string {
    if (empty($solr_doc['last_metadata_update_datetime'])) {
      return '';
    }

    $values = $solr_doc['last_metadata_update_datetime'];
    if (!\is_array($values)) {
      return \is_string($values) ? $values : '';
    }

    $latest_value = '';
    $latest_timestamp = NULL;
    $string_values = [];

    foreach ($values as $value) {
      if (!\is_string($value) || $value === '') {
        continue;
      }
      $string_values[] = $value;
      $timestamp = strtotime($value);
      if ($timestamp === FALSE) {
        continue;
      }
      if ($latest_timestamp === NULL || $timestamp > $latest_timestamp) {
        $latest_timestamp = $timestamp;
        $latest_value = $value;
      }
    }

    if ($latest_value !== '') {
      return $latest_value;
    }

    sort($string_values);
    return $string_values !== [] ? (string) end($string_values) : '';
  }

  /**
   * Generate markup for child dataset count.
   *
   * @param array $solr_doc
   *   The solr document array.
   *
   * @return array
   *   The render array for this component.
   */
  public function getChildDatasetCountMarkup(array $solr_doc): array {
    $found_children = $solr_doc['found_children']['numFound'] ?? 0;
    $total_children = $solr_doc['total_children']['numFound'] ?? 0;

    $renderArray = [
      '#markup' => $this->t('@found of @total', [
        '@found' => $found_children,
        '@total' => $total_children,
      ]),
    ];
    return $renderArray;
  }

  /**
   * Generate markup for the temporal extent.
   *
   * @param array $solr_doc
   *   The solr document raw values.
   * @param bool $add_icon
   *   Add an icon. (optional)
   *   Default: True.
   * @param string $default_icon
   *   The icon_id to use. (optional)
   *   Default: date-time.
   * @param bool $short_notation
   *   Render as compact inline string instead of fieldset-like block.
   *   Default: False.
   * @param bool $compact_labels
   *   When short_notation is false, put label and value on the same line.
   *   Default: True.
   *
   * @return array
   *   The render array for this field.
   */
  public function getTemporalExtentMarkup(
    array $solr_doc,
    bool $add_icon = TRUE,
    string $default_icon = 'date-time',
    bool $short_notation = FALSE,
    bool $compact_labels = TRUE,
  ): array {
    // Get and normalize start dates.
    $start_dates = $solr_doc['temporal_extent_start_date'] ?? [];
    if (!is_array($start_dates)) {
      $start_dates = !empty($start_dates) ? [$start_dates] : [];
    }
    $start_dates = array_filter(array_map('strval', $start_dates), static fn($d) => $d !== '');

    // Get and normalize end dates.
    $end_dates = $solr_doc['temporal_extent_end_date'] ?? [];
    if (!is_array($end_dates)) {
      $end_dates = !empty($end_dates) ? [$end_dates] : [];
    }
    $end_dates = array_filter(array_map('strval', $end_dates), static fn($d) => $d !== '');

    if (empty($start_dates)) {
      return [];
    }

    // Get earliest start date (sort and take first)
    sort($start_dates);
    $start_date = reset($start_dates);

    // Determine end date: open-ended if more start dates than end dates.
    $end_date = '';
    if (count($end_dates) > 0 && count($start_dates) <= count($end_dates)) {
      // Get latest end date (sort and take last)
      sort($end_dates);
      $end_date = end($end_dates);
    }

    return [
      '#type' => 'component',
      '#component' => 'metsis_drupal:temporal_extent',
      '#props' => [
        'start_date' => $start_date,
        'end_date' => $end_date,
        'add_icon' => $add_icon,
        'icon_id' => $default_icon,
        'short_notation' => $short_notation,
        'compact_labels' => $compact_labels,
      ],
    ];
  }

  /**
   * Generate icon and link for DOI.
   *
   * @param string $doi_url
   *   The id of this collection.
   * @param bool $show_identifier
   *   (Optional) Show the idenifier text with the link and icon.
   *   Default: False.
   * @param bool $color
   *   (Optional) Use colored icon (origianl DOI logo).
   *   Default: True.
   * @param int $size
   *   (Optional) The DOI icon size in pixels.
   *   Default: 24.
   *
   * @return array
   *   The render array for this component.
   */
  public function getDoiIconMarkup(string $doi_url, bool $show_identifier = FALSE, bool $color = TRUE, int $size = 24): array {
    // Return the doi Icon with url.
    return [
      '#type' => 'component',
      '#component' => 'metsis_drupal:doi',
      '#props' => [
        'doi_url' => $doi_url,
        'show_doi_identifier' => $show_identifier,
        'color' => $color,
        'size' => $size,
      ],
    ];
  }

  /**
   * Create license icon markup for a given license code.
   *
   * @param string $license_code
   *   The license code.
   * @param int $width
   *   (Optional) The icon width in pixels.
   *
   * @return array
   *   The render array for the license icon component.
   */
  public function getLicenseIconMarkup(string $license_code, int $width = 88): array {
    // Get the config for license icons mapping.
    $license_icons_config = $this->configProvider->getLicenseIconsConfig();
    $licenses = $license_icons_config->get('license_icons');

    if ($license_code === 'Not provided') {
      return [];
    }

    // Replace _ with . to match config keys.
    $license_type = str_replace('.', '_', $license_code);
    if (empty($licenses)) {
      return [];
    }
    if (!isset($licenses[$license_type])) {
      $this->getLogger()->warning('No license icon mapping found for license code: @code', ['@code' => $license_code]);
      return [];
    }
    $license = $licenses[$license_type];

    return [
      '#type' => 'component',
      '#component' => 'metsis_drupal:cc_license',
      '#props' => [
        'license_id' => $license_code,
        'license_url' => $license['license_uri'],
        'icon_id' => $license['icon_id'],
        'icon_alt_text' => $license['icon_alt_text'],
        'width' => $width,
      ],
    ];
  }

  /**
   * Generate Icon markup for collection image.
   *
   * @param string $parent_id
   *   The id of this collection.
   * @param int $width
   *   (Optional) The icon width in pixels.
   *
   * @return array
   *   The render array for this component.
   */
  public function getCollectionIconMarkup(string $parent_id, int $width = 88): array {
    return [
      '#type' => 'component',
      '#component' => 'metsis_drupal:collection',
      '#props' => [
        'icon_alt_text' => 'Collection',
        'width' => $width,
      ],
    ];
  }

}
