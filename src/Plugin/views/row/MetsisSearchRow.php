<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Plugin\views\row;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Htmx\Htmx;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\search_api\Plugin\views\ResultRow;
use Drupal\search_api\Plugin\views\row\SearchApiRow;
use Drupal\Core\Form\FormStateInterface;
use Drupal\metsis_drupal\Service\DatasetVisualisationBuilder;
use Drupal\metsis_drupal\Service\MetadataExportService;
use Drupal\metsis_drupal\Service\ResultRowRenderer;
use Drupal\nbs_extensions\Service\NetCDFOnDemandButtonBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Views row plugin for METSIS search results.
 *
 * @ViewsRow(
 *   id = "metsis_search_row",
 *   title = @Translation("METSIS result row"),
 *   help = @Translation("Renders search results using selectable row styles and optional operation links.")
 * )
 */
class MetsisSearchRow extends SearchApiRow implements ContainerFactoryPluginInterface {

  /**
   * Does the row plugin support to add fields to its output.
   *
   * @var bool
   */
  protected $usesFields = FALSE;

  /**
   * Optional NetCDF on-demand button builder from the NBS Extensions module.
   *
   * @var \Drupal\nbs_extensions\Service\NetCDFOnDemandButtonBuilder|null
   */
  protected ?NetCDFOnDemandButtonBuilder $netcdfOnDemandButtonBuilder;

  /**
   * Result row renderer service.
   *
   * @var \Drupal\metsis_drupal\Service\ResultRowRenderer
   */
  protected ResultRowRenderer $rowRenderer;

  /**
   * Metadata export service.
   *
   * @var \Drupal\metsis_drupal\Service\MetadataExportService
   */
  protected MetadataExportService $metadataExportService;

  /**
   * Dataset visualisation builder.
   *
   * @var \Drupal\metsis_drupal\Service\DatasetVisualisationBuilder
   */
  protected DatasetVisualisationBuilder $visualisationBuilder;

  /**
   * Cached enabled export options with descriptions, keyed by type.
   *
   * @var array<string, array{label: string, description: string}>|null
   */
  protected ?array $exportOptionsCache = NULL;

  /**
   * Construct the plugin with DI.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\metsis_drupal\Service\ResultRowRenderer $metsis_row_renderer
   *   The result row renderer service.
   * @param \Drupal\metsis_drupal\Service\MetadataExportService $metadata_export_service
   *   The metadata export service.
   * @param \Drupal\metsis_drupal\Service\DatasetVisualisationBuilder $visualisation_builder
   *   The dataset visualisation builder.
   * @param \Drupal\nbs_extensions\Service\NetCDFOnDemandButtonBuilder|null $netcdf_ondemand_button_builder
   *   The optional NBS NetCDF button builder.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ResultRowRenderer $metsis_row_renderer,
    MetadataExportService $metadata_export_service,
    DatasetVisualisationBuilder $visualisation_builder,
    ?NetCDFOnDemandButtonBuilder $netcdf_ondemand_button_builder = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->rowRenderer = $metsis_row_renderer;
    $this->metadataExportService = $metadata_export_service;
    $this->visualisationBuilder = $visualisation_builder;
    $this->netcdfOnDemandButtonBuilder = $netcdf_ondemand_button_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    /** @var \Drupal\Core\Extension\ModuleHandlerInterface $module_handler */
    $module_handler = $container->get('module_handler');
    $netcdf_ondemand_button_builder = $module_handler->moduleExists('nbs_extensions')
      ? $container->get('nbs_extensions.netcdf_on_demand_button_builder')
      : NULL;

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('metsis_drupal.result_row_renderer'),
      $container->get('metsis_drupal.metadata_export_service'),
      $container->get('metsis_drupal.dataset_visualisation_builder'),
      $netcdf_ondemand_button_builder,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['show_operations'] = ['default' => TRUE];
    $options['component_icon_width'] = ['default' => 88];
    $options['doi_show_identifier'] = ['default' => FALSE];
    $options['doi_icon_size'] = ['default' => 24];
    $options['doi_logo_color'] = ['default' => TRUE];
    $options['temporal_extent_short_notation'] = ['default' => FALSE];
    $options['temporal_extent_compact_labels'] = ['default' => TRUE];
    $options['temporal_extent_add_icon'] = ['default' => TRUE];
    $options['temporal_extent_icon_id'] = ['default' => 'date-time'];
    // default, compact, detailed, custom.
    $options['style'] = ['default' => 'default'];
    $options['custom_style_name'] = ['default' => ''];
    $options['view_modes'] = ['default' => []];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    $form['show_operations'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Render operation links'),
      '#default_value' => $this->options['show_operations'],
      '#description' => $this->t('If enabled, the row will receive operation links (view/edit) when available.'),
    ];

    $form['style'] = [
      '#type' => 'select',
      '#title' => $this->t('Row style'),
      '#options' => [
        'default' => $this->t('Default'),
        'compact' => $this->t('Compact'),
        'detailed' => $this->t('Detailed'),
        'nbs' => $this->t('NBS Sentinel Products'),
        'custom' => $this->t('Custom'),
      ],
      '#default_value' => $this->options['style'],
      '#description' => $this->t('Choose which row template/style to use for rendering results.'),
    ];
    $form['custom_style_name'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Custom row style machine name'),
      '#default_value' => $this->options['custom_style_name'] ?? '',
      '#machine_name' => ['exists' => [self::class, 'styleNameExists']],
      '#required' => FALSE,
      '#description' => $this->t('Used in metsis-search-row-custom--MACHINE-NAME.html.twig, replacing underscores with hyphens in the filename. Missing templates fall back to the Default row.'),
      '#states' => [
        'visible' => [':input[name="style"]' => ['value' => 'custom']],
      ],
    ];

    $form['component_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Component settings'),
    ];

    $form['component_settings']['shared_icons'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Shared icon settings'),
    ];

    $form['component_settings']['shared_icons']['component_icon_width'] = [
      '#type' => 'number',
      '#title' => $this->t('Collection and license icon width'),
      '#default_value' => (int) ($this->options['component_icon_width'] ?? 88),
      '#description' => $this->t('Shared width (px) for the collection and CC license components.'),
      '#min' => 16,
      '#max' => 256,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['component_settings']['doi_component'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('DOI component'),
    ];

    $form['component_settings']['doi_component']['doi_show_identifier'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show DOI identifier text'),
      '#default_value' => !empty($this->options['doi_show_identifier']),
      '#description' => $this->t('Show DOI identifier text next to the DOI icon.'),
    ];

    $form['component_settings']['doi_component']['doi_icon_size'] = [
      '#type' => 'number',
      '#title' => $this->t('DOI icon size'),
      '#default_value' => (int) ($this->options['doi_icon_size'] ?? 24),
      '#description' => $this->t('Icon size (px) for the DOI component.'),
      '#min' => 12,
      '#max' => 128,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['component_settings']['doi_component']['doi_logo_color'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use original DOI logo colors'),
      '#default_value' => !empty($this->options['doi_logo_color']),
      '#description' => $this->t('When unchecked, DOI icon is rendered in black/white style.'),
    ];

    $form['component_settings']['temporal_extent_component'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Temporal extent component'),
    ];

    $form['component_settings']['temporal_extent_component']['temporal_extent_short_notation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Temporal extent short notation'),
      '#default_value' => !empty($this->options['temporal_extent_short_notation']),
      '#description' => $this->t('Render temporal extent as "start to end" (or just start when end is empty).'),
    ];

    $form['component_settings']['temporal_extent_component']['temporal_extent_compact_labels'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Temporal extent compact labels'),
      '#default_value' => !empty($this->options['temporal_extent_compact_labels']),
      '#description' => $this->t('When short notation is disabled: checked = label and value on one line, unchecked = stacked lines.'),
      '#states' => [
        'visible' => [
          ':input[name$="[temporal_extent_short_notation]"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['component_settings']['temporal_extent_component']['temporal_extent_add_icon'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show temporal extent icon'),
      '#default_value' => !empty($this->options['temporal_extent_add_icon']),
      '#description' => $this->t('Display icon in the temporal extent header.'),
    ];

    $form['component_settings']['temporal_extent_component']['temporal_extent_icon_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Temporal extent icon ID'),
      '#default_value' => (string) ($this->options['temporal_extent_icon_id'] ?? 'date-time'),
      '#description' => $this->t('Icon ID in the metsis_drupal icon pack used for temporal extent (for example: date-time).'),
      '#required' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name$="[temporal_extent_add_icon]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    parent::buildOptionsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    // Views stores the submitted 'row_options' values, so the nested fieldset
    // values must be flattened there rather than only into $this->options.
    $parents = $form['#parents'] ?? ['row_options'];
    $values = (array) $form_state->getValue($parents, []);
    $component_settings = (array) ($values['component_settings'] ?? []);
    $shared_icons = (array) ($component_settings['shared_icons'] ?? []);
    $doi_component = (array) ($component_settings['doi_component'] ?? []);
    $temporal_component = (array) ($component_settings['temporal_extent_component'] ?? []);
    $icon_id = trim((string) ($temporal_component['temporal_extent_icon_id'] ?? 'date-time'));

    $component_options = [
      'component_icon_width' => max(16, (int) ($shared_icons['component_icon_width'] ?? 88)),
      'doi_show_identifier' => !empty($doi_component['doi_show_identifier']),
      'doi_icon_size' => max(12, (int) ($doi_component['doi_icon_size'] ?? 24)),
      'doi_logo_color' => !empty($doi_component['doi_logo_color']),
      'temporal_extent_short_notation' => !empty($temporal_component['temporal_extent_short_notation']),
      'temporal_extent_compact_labels' => !empty($temporal_component['temporal_extent_compact_labels']),
      'temporal_extent_add_icon' => !empty($temporal_component['temporal_extent_add_icon']),
      'temporal_extent_icon_id' => $icon_id !== '' ? $icon_id : 'date-time',
    ];
    unset($values['component_settings']);
    $values = $component_options + $values;
    $form_state->setValue($parents, $values);

    $this->options = $values + $this->options;
    $this->options['custom_style_name'] = (string) ($values['custom_style_name'] ?? '');
    parent::submitOptionsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function render($row): array|string {
    // Determine style and theme name.
    $style = $this->options['style'] ?? 'default';
    $theme_hook = 'metsis_search_row_' . $style;
    if ($style === 'custom' && !empty($this->options['custom_style_name'])) {
      $theme_hook .= '__' . $this->options['custom_style_name'];
    }

    /** @var \Drupal\search_api\Plugin\views\ResultRow $row */
    $solr_doc = $row->_item->getExtraData('search_api_solr_document')->getFields();

    // Get the highlighted solr fields if available.
    $highlighted_fields = $row->_item->getExtraData('highlighted_fields') ?? [];

    // Build the generated excerpt as a safe render array if available.
    $excerpt = $this->buildExcerpt($row);

    // Generate the rendered fields array.
    $fields = $this->rowRenderer->renderRow($solr_doc, $this->options, $highlighted_fields);

    // Build operations block.
    $operations = $this->buildOperations($solr_doc, $fields, $row);

    // Build render array for row style.
    return [
      '#theme' => $theme_hook,
      '#view' => $this->view,
      '#options' => $this->options,
      '#row' => $row,
      '#solr_doc' => $solr_doc,
      '#highlighted' => $highlighted_fields,
      '#excerpt' => $excerpt,
      '#fields' => $fields,
      '#operations' => $operations,
    ];
  }

  /**
   * Custom template names are local configuration, not unique entities.
   */
  public static function styleNameExists(string $name): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function validateOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::validateOptionsForm($form, $form_state);
    if ($form_state->getValue('style') === 'custom') {
      $name = (string) $form_state->getValue('custom_style_name');
      if ($name === '' || preg_match('/^[a-z0-9_]+$/', $name) !== 1) {
        $form_state->setError($form['custom_style_name'], $this->t('Enter a machine name using lowercase letters, numbers, and underscores.'));
      }
    }
  }

  /**
   * Build excerpt render array from the row item.
   *
   * @param \Drupal\search_api\Plugin\views\ResultRow $row
   *   The result row.
   *
   * @return array
   *   Render array for excerpt or empty array.
   */
  private function buildExcerpt(ResultRow $row): array {
    $excerpt = [];
    $excerpt_markup = $row->_item->getExcerpt();
    if (!empty($excerpt_markup)) {
      $excerpt = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['metsis-search-excerpt'],
        ],
        'content' => [
          '#markup' => $excerpt_markup,
          '#allowed_tags' => ['em', 'strong', 'span'],
        ],
      ];
    }
    return $excerpt;
  }

  /**
   * Build operations render array.
   *
   * @param array $solr_doc
   *   The Solr document.
   * @param array $fields
   *   The rendered fields.
   * @param \Drupal\search_api\Plugin\views\ResultRow $row
   *   The result row.
   *
   * @return array
   *   Render array for operations or empty array.
   */
  private function buildOperations(array $solr_doc, array $fields, ResultRow $row): array {
    $operations = [];
    if (empty($this->options['show_operations'])) {
      return $operations;
    }

    $metadata_identifier = (string) ($solr_doc['id'] ?? '');
    $dataset_identifier = (string) ($fields['metadata_identifier'] ?? '');
    $row_id = (string) ($fields['id'] ?? '');
    // Sanitize row_id for safe use in CSS IDs and selectors.
    $row_id = $this->sanitizeIdValue($row_id);
    $popover_id = 'metsis-export-popover-' . $row_id;

    $is_parent = !empty($solr_doc['isParent']) && $solr_doc['isParent'] == TRUE;
    $operations = [
      '#type' => 'container',
      '#attributes' => ['class' => ['metsis-row-operations']],
      '#attached' => [
        'library' => ['metsis_drupal/metsis_metadata_dialog'],
      ],
      'controls' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['metsis-row-operations-controls']],
      ],
    ];

    if ($this->netcdfOnDemandButtonBuilder !== NULL) {
      $netcdf_button = $this->netcdfOnDemandButtonBuilder->build($solr_doc);
      if ($netcdf_button !== NULL) {
        $operations['controls']['netcdf_on_demand'] = $netcdf_button;
      }
    }

    // Add collection filter if parent.
    if ($is_parent && $dataset_identifier !== '') {
      $operations['controls']['collection_filter'] = $this->buildCollectionFilter($solr_doc, $dataset_identifier);
    }

    // Add metadata preview loaded via HTMX into a native dialog.
    if ($metadata_identifier !== '') {
      $this->buildMetadataModalTrigger($operations, $metadata_identifier);
    }

    // Add export options if metadata identifier present.
    if ($metadata_identifier !== '' && $row_id !== '') {
      $this->buildExportOptions($operations, $metadata_identifier, $row_id, $popover_id);
    }

    // Add data access options if row id is available.
    if ($row_id !== '') {
      $this->buildDataAccessOptions($operations, $solr_doc, $row_id);
    }

    if ($row_id !== '') {
      $visualisations = $this->visualisationBuilder->build(
        $solr_doc,
        $row_id,
        $metadata_identifier,
      );
      if ($visualisations !== []) {
        unset($visualisations['controls']['#type'], $visualisations['controls']['#attributes']);
        $operations['controls'] += $visualisations['controls'];
        unset($visualisations['controls']);
        $operations += $visualisations;
      }
    }

    return $operations;
  }

  /**
   * Build metadata modal trigger button.
   *
   * @param array $operations
   *   The operations array to mutate.
   * @param string $metadata_identifier
   *   The Solr document id.
   */
  private function buildMetadataModalTrigger(array &$operations, string $metadata_identifier): void {
    $target_id = 'metsis-metadata-modal-root';
    $spinner_id = 'metsis-metadata-spinner-' . substr(md5($metadata_identifier), 0, 8);
    $metadata_htmx_url = Url::fromRoute('metsis_drupal.metadata_document_htmx', [
      'id' => $metadata_identifier,
    ]);

    $button = [
      '#type' => 'button',
      '#value' => $this->t('View metadata'),
      '#attributes' => [
        'type' => 'button',
        'class' => ['button', 'button--small', 'metsis-metadata-modal-trigger'],
        'hx-select' => 'dialog[data-metsis-metadata-dialog]',
      ],
    ];

    (new Htmx())
      ->get($metadata_htmx_url)
      ->onlyMainContent()
      ->target('#' . $target_id)
      ->swap('innerHTML')
      ->indicator('#' . $spinner_id)
      ->on('htmx:AfterSwap', 'Drupal.metsis.metadataDialog.afterSwap(this);')
      ->on('htmx:AfterSettle', 'Drupal.metsis.vocabPopover.afterSwap(this);')
      ->applyTo($button);

    $operations['controls']['metadata_modal'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['metsis-metadata-modal-trigger-wrap']],
      'button' => $button,
      'spinner' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => $spinner_id,
          'class' => ['htmx-indicator', 'metsis-metadata-button-spinner'],
          'aria-hidden' => 'true',
        ],
        'icon' => [
          '#type' => 'icon',
          '#pack_id' => 'metsis_drupal_spinners',
          '#icon_id' => 'oval',
          '#settings' => [
            'stroke' => 'currentColor',
            'height' => '14',
            'width' => '14',
          ],
        ],
      ],
    ];
  }

  /**
   * Build collection filter render array.
   *
   * @param array $solr_doc
   *   The Solr document.
   * @param string $dataset_identifier
   *   The dataset identifier.
   *
   * @return array
   *   Render array for collection filter.
   */
  private function buildCollectionFilter(array $solr_doc, string $dataset_identifier): array {
    $found_children = (int) ($solr_doc['found_children']['numFound'] ?? 0);
    $total_children = (int) ($solr_doc['total_children']['numFound'] ?? 0);
    $collection_button = [
      '#type' => 'button',
      '#value' => isset($solr_doc['found_children'], $solr_doc['total_children']) ? (string) $this->t('@found of @total children found', [
        '@found' => $found_children,
        '@total' => $total_children,
      ]) : (string) $this->t('Open this collection'),
      '#attributes' => [
        'class' => ['metsis-collection-button', 'button'],
        'data-collection-id' => $dataset_identifier,
        'title' => (string) $this->t('Filter on this collection'),
      ],
    ];

    return [
      '#type' => 'component',
      '#component' => 'metsis_drupal:icon_button',
      '#props' => [
        'icon_size' => 20,
      ],
      '#slots' => [
        'button' => $collection_button,
      ],
    ];
  }

  /**
   * Build export options render array.
   *
   * @param array $operations
   *   The operations array to mutate.
   * @param string $metadata_identifier
   *   The metadata identifier.
   * @param string $row_id
   *   The row ID.
   * @param string $popover_id
   *   The popover ID.
   */
  private function buildExportOptions(array &$operations, string $metadata_identifier, string $row_id, string $popover_id): void {
    $anchor_suffix = $this->buildAnchorSuffix($row_id, $metadata_identifier);
    $anchor_name = '--metsis-export-trigger-' . $anchor_suffix;

    // Load export options once per render pass and cache on the instance.
    if ($this->exportOptionsCache === NULL) {
      $labels = $this->metadataExportService->getEnabledExportOptions();
      $descriptions = $this->metadataExportService->getDescriptions();
      $this->exportOptionsCache = [];
      foreach ($labels as $type_key => $label) {
        $this->exportOptionsCache[$type_key] = [
          'label' => (string) $label,
          'description' => (string) ($descriptions[$type_key] ?? ''),
        ];
      }
    }

    if ($this->exportOptionsCache === []) {
      return;
    }

    // Trigger button — uses native Popover API, no JS required.
    $operations['controls']['export_trigger'] = [
      '#type' => 'button',
      '#value' => $this->t('Export metadata &#9662;'),
      '#attributes' => [
        'type' => 'button',
        'class' => ['metsis-popover-trigger', 'metsis-export-trigger'],
        'style' => 'anchor-name: ' . $anchor_name . ';',
        'popovertarget' => $popover_id,
        'popovertargetaction' => 'toggle',
        'aria-haspopup' => 'true',
      ],
    ];

    // Popover panel with one download link per export type.
    $operations['export_popover'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => $popover_id,
        'popover' => '',
        'class' => ['metsis-popover-panel', 'metsis-export-popover'],
        'style' => 'position-anchor: ' . $anchor_name . ';',
        'data-nosnippet' => 'true',
      ],
    ];

    foreach ($this->exportOptionsCache as $type_key => $info) {
      $download_url = Url::fromRoute(
        'metsis_drupal.metadata_export_download',
        ['id' => $metadata_identifier, 'type' => $type_key],
        ['absolute' => FALSE]
      );

      $htmx_url = Url::fromRoute(
        'metsis_drupal.metadata_export_htmx_redirect',
        ['id' => $metadata_identifier, 'type' => $type_key],
      );
      $description = trim(PlainTextOutput::renderFromHtml($info['description']));
      $link_label = $description !== ''
        ? $description
        : $info['label'];

      $export_button = [
        '#type' => 'button',
        '#value' => $link_label,
        '#attributes' => [
          'class' => ['metsis-popover-option', 'metsis-export-option'],
          'rel' => 'nofollow noarchive noopener noreferrer',
          'referrerpolicy' => 'no-referrer',
          'data-nosnippet' => 'true',
          'title' => $this->t('Click to download @format metadata', ['@format' => $info['label']]),
          'download' => $metadata_identifier . '_' . $type_key . '.xml',
        ],
      ];
      (new Htmx())
        ->get($htmx_url)
        ->swap('none')
        // ->indicator('#metsis-export-spinner')
        ->onlyMainContent()
        ->redirectHeader($download_url)
        ->applyTo($export_button);

      $operations['export_popover']['item_' . $type_key] = [
        '#type' => 'component',
        '#component' => 'metsis_drupal:icon_button',
        '#props' => [
          'icon_size' => 16,
          'icon_pack' => 'metsis_drupal',
          'icon_id' => 'download',
        ],
        '#slots' => [
          'button' => $export_button,
        ],
      ];
    }
  }

  /**
   * Build download data popover from data_access_json.
   *
   * @param array $operations
   *   The operations array to mutate.
   * @param array $solr_doc
   *   The Solr document.
   * @param string $row_id
   *   The row ID.
   */
  private function buildDataAccessOptions(array &$operations, array $solr_doc, string $row_id): void {
    $rows = $this->extractDataAccessRows($solr_doc['data_access_json'] ?? []);
    if ($rows === []) {
      return;
    }

    $anchor_suffix = $this->buildAnchorSuffix($row_id, (string) ($solr_doc['id'] ?? ''));
    $anchor_name = '--metsis-data-access-trigger-' . $anchor_suffix;
    $popover_id = 'metsis-data-access-popover-' . $row_id;

    $items = [];
    foreach ($rows as $row_index => $entry) {
      $type = trim((string) ($entry['type'] ?? ''));
      $description = trim((string) ($entry['description'] ?? ''));
      $resource = $this->normalizeUri($entry['resource'] ?? '');
      if ($type === '' || $resource === '') {
        continue;
      }

      $normalized_type = strtolower($type);
      $url = $resource;
      $target_blank = TRUE;
      $label = $this->t('Download data (@type)', ['@type' => $type]);
      $attributes = [
        'class' => ['metsis-popover-option', 'metsis-data-access-option'],
        'rel' => 'nofollow noarchive noopener noreferrer',
        'referrerpolicy' => 'no-referrer',
        'data-nosnippet' => 'true',
      ];

      if ($normalized_type === 'http') {
        $path = parse_url($resource, PHP_URL_PATH);
        $extension = strtolower(pathinfo(is_string($path) ? $path : '', PATHINFO_EXTENSION));
        $file_type = match ($extension) {
          'nc', 'netcdf' => 'NetCDF file',
          'zip' => 'ZIP archive',
          'pdf' => 'PDF file',
          'mp3' => 'MP3 audio file',
          'doc', 'docx' => 'Word document',
          'xls', 'xlsx' => 'Excel spreadsheet',
          'ppt', 'pptx' => 'PowerPoint presentation',
          'csv' => 'CSV file',
          'txt' => 'text file',
          default => $extension !== '' ? strtoupper($extension) . ' file' : 'data file',
        };
        $label = str_starts_with($resource, 'https://nbstds') && $extension === 'zip'
          ? $this->t('Direct HTTP download (@file_type, SAFE)', ['@file_type' => $file_type])
          : $this->t('Direct HTTP download (@file_type)', ['@file_type' => $file_type]);
        $target_blank = FALSE;
        $attributes['download'] = TRUE;
      }
      elseif ($normalized_type === 'opendap') {
        $url = $this->normalizeOpendapLandingPageUrl($resource);
        $label = $this->t('OPeNDAP access');
      }
      elseif ($normalized_type === 'ogc wms') {
        $url = $this->normalizeOgcWmsCapabilitiesUrl($resource);
        $label = $this->t('OGC WMS capabilities');
      }

      if ($description !== '') {
        $attributes['title'] = $description;
      }

      if ($target_blank) {
        $label = $this->t('@label (opens in a new tab)', ['@label' => $label]);
        $attributes['target'] = '_blank';
      }

      $items[] = [
        'item_key' => 'item_' . $row_index,
        'title' => $label,
        'url' => $url,
        'attributes' => $attributes,
      ];
    }

    if ($items === []) {
      return;
    }

    $operations['controls']['data_access_trigger'] = [
      '#type' => 'button',
      '#value' => $this->t('Download data &#9662;'),
      '#attributes' => [
        'type' => 'button',
        'class' => ['metsis-popover-trigger', 'metsis-data-access-trigger'],
        'style' => 'anchor-name: ' . $anchor_name . ';',
        'popovertarget' => $popover_id,
        'popovertargetaction' => 'toggle',
        'aria-haspopup' => 'true',
      ],
    ];

    $operations['data_access_popover'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => $popover_id,
        'popover' => '',
        'class' => ['metsis-popover-panel', 'metsis-data-access-popover'],
        'style' => 'position-anchor: ' . $anchor_name . ';',
        'data-nosnippet' => 'true',
      ],
    ];

    foreach ($items as $item) {
      $link_attributes = $item['attributes'];
      $link_attributes['class'][] = 'button';
      $link = [
        '#type' => 'link',
        '#title' => $item['title'],
        '#url' => Url::fromUri($item['url']),
        '#attributes' => $link_attributes,
      ];

      $operations['data_access_popover'][$item['item_key']] = [
        '#prefix' => '<div class="metsis-data-access-button">',
        '#suffix' => '</div>',
        '#type' => 'component',
        '#component' => 'metsis_drupal:icon_button',
        '#props' => [
          'icon_size' => 16,
          'icon_pack' => 'metsis_drupal',
          'icon_id' => 'download',
        ],
        '#slots' => [
          'button' => $link,
        ],
      ];
    }
  }

  /**
   * Extract data access rows from Solr document value.
   *
   * @param mixed $value
   *   Solr data_access_json value.
   *
   * @return array<int, array<string, mixed>>
   *   Data access rows.
   */
  private function extractDataAccessRows(mixed $value): array {
    if (!is_array($value) || $value === []) {
      return [];
    }

    if (!array_is_list($value)) {
      return [];
    }

    $first = reset($value);
    if (is_array($first) && array_is_list($first)) {
      $value = $first;
    }

    $rows = [];
    foreach ($value as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $rows[] = $entry;
    }

    return $rows;
  }

  /**
   * Build a safe anchor suffix for row-scoped popovers.
   *
   * @param string $row_id
   *   Row ID.
   * @param string $fallback
   *   Fallback identifier.
   *
   * @return string
   *   Safe anchor suffix.
   */
  private function buildAnchorSuffix(string $row_id, string $fallback): string {
    $anchor_suffix = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $row_id) ?? '';
    $anchor_suffix = trim($anchor_suffix, '-_');
    if ($anchor_suffix === '') {
      $anchor_suffix = substr(md5($fallback), 0, 16);
    }
    return $anchor_suffix;
  }

  /**
   * Sanitize a value for safe use as a CSS ID attribute or selector.
   *
   * CSS IDs must only contain: a-z, A-Z, 0-9, hyphen, underscore.
   * Non-matching characters are replaced with hyphens, and leading/trailing
   * special characters are trimmed. If the result is empty, a safe hash-based
   * fallback is used.
   *
   * @param string $value
   *   Value to sanitize.
   *
   * @return string
   *   Safe CSS ID value.
   */
  private function sanitizeIdValue(string $value): string {
    // Replace non-alphanumeric chars (except hyphens/underscores).
    $sanitized = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $value) ?? '';
    // Trim leading/trailing special characters.
    $sanitized = trim($sanitized, '-_');
    // If the result is empty, use a hash-based fallback.
    if ($sanitized === '') {
      $sanitized = 'id-' . substr(md5($value), 0, 8);
    }
    return $sanitized;
  }

  /**
   * Normalize URI values and keep only valid absolute URLs.
   *
   * @param mixed $value
   *   URI value.
   *
   * @return string
   *   Valid URL or empty string.
   */
  private function normalizeUri(mixed $value): string {
    if (is_array($value)) {
      foreach ($value as $item) {
        $uri = $this->normalizeUri($item);
        if ($uri !== '') {
          return $uri;
        }
      }
      return '';
    }

    if (!is_scalar($value)) {
      return '';
    }

    $uri = trim((string) $value);
    if ($uri === '') {
      return '';
    }

    return filter_var($uri, FILTER_VALIDATE_URL) ? $uri : '';
  }

  /**
   * Convert OPeNDAP endpoint URL to THREDDS landing page URL.
   *
   * @param string $resource
   *   Resource URL.
   *
   * @return string
   *   Landing page URL with .html suffix.
   */
  private function normalizeOpendapLandingPageUrl(string $resource): string {
    if (preg_match('/\.html?(?:[?#]|$)/i', $resource) === 1) {
      return $resource;
    }

    if (preg_match('/^([^?#]+)([?#].*)?$/', $resource, $matches) !== 1) {
      return $resource;
    }

    $base = $matches[1];
    $suffix = $matches[2] ?? '';
    return $base . '.html' . $suffix;
  }

  /**
   * Normalize OGC WMS endpoint URL to include GetCapabilities params.
   *
   * @param string $resource
   *   Resource URL.
   *
   * @return string
   *   WMS URL with service/request query params.
   */
  private function normalizeOgcWmsCapabilitiesUrl(string $resource): string {
    $parts = parse_url($resource);
    if (!is_array($parts)) {
      return $resource;
    }

    $query = [];
    if (!empty($parts['query']) && is_string($parts['query'])) {
      parse_str($parts['query'], $query);
    }

    $query['service'] = 'WMS';
    $query['request'] = 'GetCapabilities';

    $rebuilt = '';
    if (!empty($parts['scheme'])) {
      $rebuilt .= $parts['scheme'] . '://';
    }
    if (!empty($parts['user'])) {
      $rebuilt .= $parts['user'];
      if (!empty($parts['pass'])) {
        $rebuilt .= ':' . $parts['pass'];
      }
      $rebuilt .= '@';
    }
    if (!empty($parts['host'])) {
      $rebuilt .= $parts['host'];
    }
    if (!empty($parts['port'])) {
      $rebuilt .= ':' . $parts['port'];
    }
    if (!empty($parts['path'])) {
      $rebuilt .= $parts['path'];
    }

    $rebuilt .= '?' . http_build_query($query);

    if (!empty($parts['fragment'])) {
      $rebuilt .= '#' . $parts['fragment'];
    }

    return $rebuilt;
  }

  /**
   * {@inheritdoc}
   */
  public function optionsSummary() {
    $summary = [];
    $summary[] = $this->t('Style: @style', ['@style' => $this->options['style']]);
    $summary[] = $this->t('Operations: @val', ['@val' => $this->options['show_operations'] ? $this->t('Enabled') : $this->t('Disabled')]);
    $summary[] = $this->t('Collection/CC width: @width px', ['@width' => (int) ($this->options['component_icon_width'] ?? 88)]);
    $summary[] = $this->t('DOI identifier: @val', ['@val' => !empty($this->options['doi_show_identifier']) ? $this->t('Shown') : $this->t('Hidden')]);
    $summary[] = $this->t('DOI icon size: @size px', ['@size' => (int) ($this->options['doi_icon_size'] ?? 24)]);
    $summary[] = $this->t('DOI logo color: @val', ['@val' => !empty($this->options['doi_logo_color']) ? $this->t('Original') : $this->t('Black/white')]);
    $summary[] = $this->t('Temporal notation: @val', ['@val' => !empty($this->options['temporal_extent_short_notation']) ? $this->t('Short') : $this->t('Detailed')]);
    $summary[] = $this->t('Temporal labels: @val', ['@val' => !empty($this->options['temporal_extent_compact_labels']) ? $this->t('Inline') : $this->t('Stacked')]);
    $summary[] = $this->t('Temporal icon: @val (@id)', [
      '@val' => !empty($this->options['temporal_extent_add_icon']) ? $this->t('Shown') : $this->t('Hidden'),
      '@id' => (string) ($this->options['temporal_extent_icon_id'] ?? 'date-time'),
    ]);
    return $summary;
  }

}
