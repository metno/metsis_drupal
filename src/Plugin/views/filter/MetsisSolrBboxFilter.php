<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Plugin\views\filter;

use Drupal\views\Attribute\ViewsFilter;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\search_api\Plugin\views\filter\SearchApiFilterTrait;

/**
 * Defines a filter for filtering on dates.
 *
 * @ingroup views_filter_handlers
 */
#[ViewsFilter(id: 'metsis_filter_bbox')]
class MetsisSolrBboxFilter extends FilterPluginBase implements ContainerFactoryPluginInterface {
  use SearchApiFilterTrait;

  public const DEFAULT_MAP_HEIGHT = 250;
  public const MIN_MAP_HEIGHT = 150;
  public const MAX_MAP_HEIGHT = 1000;

  /**
   * Disable the possibility to force a single value.
   *
   * @var bool
   */
  protected $alwaysMultiple = TRUE;

  /**
   * Filter configuration admin UI summary.
   *
   * {@inheritdoc},
   */
  public function adminSummary() {
    if ($this->isExposed() && $this->options['expose']['map_input'] && $this->options['expose']['user_input']) {
      return $this->t('Exposed BBox filter Map & User input');
    }
    if ($this->options['expose']['map_input'] && $this->isExposed()) {
      return $this->t('Map input BBox filter exposed');
    }
    elseif ($this->isExposed()) {
      return $this->t('Exposed BBox filter');
    }
    elseif (!empty($this->value)) {
      return $this->t('@operator: @coordinates',
      [
        '@coordinates' => implode(', ', $this->value),
        '@operator' => $this->operators()[$this->operator]['short'],
      ]);
    }
    else {
      return $this->t('No coordinates');
    }
  }

  /**
   * Define bbox filter options.
   *
   * {@inheritDoc}
   */
  protected function defineOptions(): array {
    $options = parent::defineOptions();

    // Set default operator.
    $options['operator'] = ['default' => 'intersects'];
    // Allow map input instead of manual coordinate input.
    $options['expose']['contains']['map_input'] = ['default' => FALSE];
    $options['expose']['contains']['user_input'] = ['default' => FALSE];
    $options['expose']['contains']['tabs_component'] = ['default' => FALSE];
    $options['expose']['contains']['map_height'] = ['default' => self::DEFAULT_MAP_HEIGHT];

    return $options;
  }

  /**
   * Define available operators.
   *
   * {@inheritdoc}
   */
  public function operators(): array {
    $operators = [
      "contains" => [
        'title' => $this->t('Contains'),
        'method' => 'opBbox',
        'short' => 'Contains',
        'values' => 4,
      ],
      "within" => [
        'title' => $this->t('Within'),
        'method' => 'opBbox',
        'short' => 'Within',
        'values' => 4,
      ],
      "intersects" => [
        'title' => $this->t('Intersects'),
        'method' => 'opBbox',
        'short' => 'Intersects',
        'values' => 4,
      ],
      "equals" => [
        'title' => $this->t('Equals'),
        'method' => 'opBbox',
        'short' => 'Equals',
        'values' => 4,
      ],
      "disjoint" => [
        'title' => $this->t('Disjoint'),
        'method' => 'opBbox',
        'short' => 'Disjoint',
        'values' => 4,
      ],
    ];

    return $operators;
  }

  /**
   * {@inheritdoc}
   */
  public function buildExposedForm(&$form, FormStateInterface $form_state) {
    parent::buildExposedForm($form, $form_state);

    $identifier = $this->options['expose']['identifier'];
    $wrapper = $identifier . '_wrapper';
    $operator_key = $this->options['expose']['operator_id'] ?? '';
    $map_input_enabled = !empty($this->options['expose']['map_input']);
    $user_input_enabled = !empty($this->options['expose']['user_input']);
    $tabs_component_enabled = !empty($this->options['expose']['tabs_component']);

    if (empty($form[$wrapper][$identifier])) {
      return;
    }
    $form[$wrapper]['#tree'] = FALSE;
    $form[$wrapper][$identifier]['#tree'] = TRUE;
    $form[$wrapper]['#attributes']['class'][] = "bbox-exposed-filter-wrapper";
    $form[$wrapper]['#metsis_fieldset_variant'] = 'metsis_bbox_filter';
    $form[$wrapper]['#metsis_bbox_identifier'] = $identifier;
    $form[$wrapper]['#metsis_bbox_operator_key'] = $operator_key;
    $form[$wrapper]['#metsis_bbox_show_map'] = $map_input_enabled;
    $form[$wrapper]['#metsis_bbox_show_coords'] = !$map_input_enabled || $user_input_enabled;
    $form[$wrapper]['#metsis_bbox_use_tabs'] = $map_input_enabled && $user_input_enabled && $tabs_component_enabled;
    $form[$wrapper]['#attached']['library'][] = 'metsis_drupal/metsis_bbox_summary';
    $form[$wrapper]['#attached']['library'][] = 'metsis_drupal/bbox_map_filter_styles';
    $form[$wrapper][$identifier]['#attributes']['class'][] = 'metsis-bbox-fields';

    foreach (['minX', 'maxX', 'minY', 'maxY'] as $coordinate) {
      $form[$wrapper][$identifier][$coordinate]['#attributes']['data-metsis-bbox-coordinate'] = $coordinate;
      $form[$wrapper][$identifier][$coordinate]['#wrapper_attributes']['class'][] = 'metsis-bbox-field';
    }

    if (isset($form[$wrapper][$operator_key]) && is_array($form[$wrapper][$operator_key])) {
      $form[$wrapper][$operator_key]['#attributes']['data-bef-auto-submit-exclude'] = '';
      $form[$wrapper][$operator_key]['#attributes']['data-metsis-bbox-autosubmit'] = '';
      $form = BubbleableMetadata::mergeAttachments($form, [
        '#attached' => [
          'library' => ['metsis_drupal/metsis_filter_autosubmit'],
        ],
      ]);
    }

    if (!$map_input_enabled) {
      return;
    }

    // Guard: Views and BEF may invoke buildExposedForm more than once.
    // Reuse the existing container if it already exists.
    $form[$wrapper]['bbox_map_filter'] = [
      '#type' => 'container',
      '#title' => $this->t('Select bounding box on map'),
      '#tree' => FALSE,
      '#attributes' => [
        'class' => ['bbox-map-filter-wrapper'],
      ],
    ];
    $form[$wrapper]['bbox_map_filter']['map'] = [
      '#type' => 'container',
      '#tree' => FALSE,
      '#attributes' => [
        'class' => ['bbox-map-filter-container'],
      ],
    ];
    $this->setExposedMapHeight($form[$wrapper], $this->getExposedMapHeight());
    $form[$wrapper]['bbox_map_filter']['map']['coords'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['bbox-map-coordinates']],
      'text' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => ['class' => ['coords']],
        '#value' => $this->t('Create filter...'),
      ],
    ];

    $form[$wrapper]['bbox_map_filter']['attribution'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['bbox-map-filter-attribution']],
    ];

    if (!$user_input_enabled) {
      // Map-only mode: hide the coordinate text inputs.
      $form[$wrapper][$identifier]['minX']['#type'] = 'hidden';
      $form[$wrapper][$identifier]['maxX']['#type'] = 'hidden';
      $form[$wrapper][$identifier]['maxY']['#type'] = 'hidden';
      $form[$wrapper][$identifier]['minY']['#type'] = 'hidden';
    }

    $form = BubbleableMetadata::mergeAttachments($form, [
      '#attached' => [
        'library' => [
          'metsis_drupal/bbox_map_filter',
        ],
      ],
    ]);
  }

  /**
   * Add map input option to exposed UI form.
   *
   * {@inheritdoc}
   */
  public function buildExposeForm(&$form, FormStateInterface $form_state) {
    parent::buildExposeForm($form, $form_state);
    $form['expose']['#type'] = 'container';
    $form['expose']['map_input'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use map for input'),
      '#description' => $this->t('Enable this option to use a map for bounding box input. This will hide the exposed form inputs.'),
      '#default_value' => $this->options['expose']['map_input'],
    ];

    // Add option to also show user input fields. If the above is checked.
    $form['expose']['user_input'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Also show user input fields'),
      '#description' => $this->t('Enable this option to show the exposed form inputs along with the map.'),
      '#default_value' => $this->options['expose']['user_input'],
      "#states" => [
        'visible' => [
          ':input[name="options[expose][map_input]"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $form['expose']['tabs_component'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use tabs component for map and coordinates'),
      '#description' => $this->t('Enable this option to render map and coordinate inputs inside the bbox tabs component when both are shown.'),
      '#default_value' => $this->options['expose']['tabs_component'],
      "#states" => [
        'visible' => [
          ':input[name="options[expose][user_input]"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $form['expose']['map_height'] = [
      '#type' => 'number',
      '#title' => $this->t('Map height (pixels)'),
      '#description' => $this->t('The map fills the available width. Search blocks can override this height.'),
      '#default_value' => $this->getExposedMapHeight(),
      '#min' => self::MIN_MAP_HEIGHT,
      '#max' => self::MAX_MAP_HEIGHT,
      '#step' => 1,
      '#required' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="options[expose][map_input]"]' => ['checked' => TRUE],
        ],
      ],
    ];
  }

  /**
   * Returns the configured height for the exposed map.
   */
  public function getExposedMapHeight(): int {
    return (int) ($this->options['expose']['map_height'] ?? self::DEFAULT_MAP_HEIGHT);
  }

  /**
   * Sets the map mount height, including block-specific overrides.
   */
  public function setExposedMapHeight(array &$wrapper, int $height): void {
    if (isset($wrapper['bbox_map_filter']['map'])) {
      $height = max(self::MIN_MAP_HEIGHT, min(self::MAX_MAP_HEIGHT, $height));
      $wrapper['bbox_map_filter']['map']['#attributes']['style'] = '--metsis-bbox-map-height: ' . $height . 'px;';
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function valueForm(&$form, FormStateInterface $form_state): void {
    parent::valueForm($form, $form_state);

    $form['value']['#type'] = 'container';
    $form['value']['#tree'] = TRUE;

    $value_element = &$form['value'];

    // Add the Latitude and Longitude elements.
    $value_element += [
      'minX' => [
        '#type' => 'textfield',
        '#title' => $this->t('Minimum Longitude (X)'),
        '#default_value' => !empty($this->value['minX']) ? $this->value['minX'] : '',
        '#size' => 10,

      ],
      'maxX' => [
        '#type' => 'textfield',
        '#title' => $this->t('Maximum Longitude (X)'),
        '#default_value' => !empty($this->value['maxX']) ? $this->value['maxX'] : '',
        '#size' => 10,

      ],
      'minY' => [
        '#type' => 'textfield',
        '#title' => $this->t('Minimum Latitude (Y)'),
        '#default_value' => !empty($this->value['minY']) ? $this->value['minY'] : '',
        '#size' => 10,
      ],
      'maxY' => [
        '#type' => 'textfield',
        '#title' => $this->t('Maximum Latitude (Y)'),
        '#default_value' => !empty($this->value['maxY']) ? $this->value['maxY'] : '',
        '#size' => 10,
      ],

    ];
  }

  /**
   * Provide a list of all the numeric operators.
   *
   * {@inheritdoc}
   */
  public function operatorOptions($which = 'title') {
    $options = [];
    foreach ($this->operators() as $id => $info) {
      $options[$id] = $info[$which];
    }

    return $options;
  }

  /**
   * Create a solr BBox query string using the given operator.
   *
   * Store as an option in the query object.
   * Added to solr query in event subscriber.
   *
   * {@inheritdoc}
   */
  public function query() {
    if (empty($this->value)) {
      return;
    }

    // Get the field values.
    $maxX = $this->value['maxX'];
    $minX = $this->value['minX'];
    $maxY = $this->value['maxY'];
    $minY = $this->value['minY'];
    // Validate values.
    if (
    !is_numeric($maxX)
    || !is_numeric($minX)
    || !is_numeric($maxY)
    || !is_numeric($minY)
    ) {
      return;
    }
    // Build the Solr BBox query.
    $field = "$this->realField";
    $operator = ucfirst($this->operator);
    $bbox = "ENVELOPE({$minX}, {$maxX}, {$maxY}, {$minY})";
    $bbox_solr_query = "{!field f={$field} v='{$operator}({$bbox})'}";

    // The SearchApiSolr subscriber will add the filter to the solr query.
    $this->getQuery()->setOption('metsis_bbox_filter', $bbox_solr_query);
  }

  /**
   * {@inheritdoc}
   */
  public function acceptExposedInput($input) {
    $identifier = $this->options['expose']['identifier'];
    if (empty($this->options['exposed'])) {
      return TRUE;
    }

    $rc = parent::acceptExposedInput($input);
    if (!$rc) {
      return FALSE;
    }

    // No exposed bbox input has been submitted yet.
    if (!is_array($input) || !array_key_exists($identifier, $input) || !is_array($input[$identifier])) {
      return TRUE;
    }

    $coordinates = ['minX', 'maxX', 'minY', 'maxY'];
    $has_any_value = FALSE;

    // Accept empty input on initial load; only validate once any value is set.
    foreach ($coordinates as $coordinate) {
      $value = trim((string) ($input[$identifier][$coordinate] ?? ''));
      if ($value !== '') {
        $has_any_value = TRUE;
        break;
      }
    }

    if (!$has_any_value) {
      return TRUE;
    }

    foreach ($coordinates as $coordinate) {
      $value = trim((string) ($input[$identifier][$coordinate] ?? ''));
      if ($value === '' || !is_numeric($value)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function validateExposed(&$form, FormStateInterface $form_state) {
    $identifier = $this->options['expose']['identifier'];
    if (empty($this->options['exposed'])) {
      return;
    }

    $coordinates = ['minX', 'maxX', 'minY', 'maxY'];
    $values = $form_state->getValue($identifier) ?? [];

    if (!is_array($values)) {
      return;
    }

    $has_any_value = FALSE;
    foreach ($coordinates as $coordinate) {
      $value = trim((string) ($values[$coordinate] ?? ''));
      if ($value !== '') {
        $has_any_value = TRUE;
        break;
      }
    }

    // Do not validate on initial page load when bbox fields are empty.
    if (!$has_any_value) {
      return;
    }

    $has_missing_values = FALSE;

    // Check that all coordinates are filled.
    foreach ($coordinates as $coordinate) {
      $value = trim((string) ($values[$coordinate] ?? ''));
      if ($value === '') {
        $has_missing_values = TRUE;
        $form_state->setErrorByName("{$identifier}][{$coordinate}",
          $this->t('The @coordinate coordinate is required.',
            ['@coordinate' => $coordinate]));
      }
    }

    // If there are missing values, don't validate further.
    if ($has_missing_values) {
      return;
    }

    // Validate numeric values and ranges.
    // Validate minY: must be numeric and between -90 and 90.
    $minY = $values['minY'] ?? NULL;
    if ($minY !== NULL && $minY !== '') {
      if (!is_numeric($minY)) {
        $form_state->setErrorByName("{$identifier}][minY",
          $this->t('The minY coordinate must be a number.'));
      }
      elseif ((float) $minY < -90 || (float) $minY > 90) {
        $form_state->setErrorByName("{$identifier}][minY",
          $this->t('The minY coordinate must be between -90 and 90.'));
      }
    }

    // Validate maxY: must be numeric and between -90 and 90.
    $maxY = $values['maxY'] ?? NULL;
    if ($maxY !== NULL && $maxY !== '') {
      if (!is_numeric($maxY)) {
        $form_state->setErrorByName("{$identifier}][maxY",
          $this->t('The maxY coordinate must be a number.'));
      }
      elseif ((float) $maxY < -90 || (float) $maxY > 90) {
        $form_state->setErrorByName("{$identifier}][maxY",
          $this->t('The maxY coordinate must be between -90 and 90.'));
      }
    }

    // Validate minX: must be numeric and between -180 and 180.
    $minX = $values['minX'] ?? NULL;
    if ($minX !== NULL && $minX !== '') {
      if (!is_numeric($minX)) {
        $form_state->setErrorByName("{$identifier}][minX",
          $this->t('The minX coordinate must be a number.'));
      }
      elseif ((float) $minX < -180 || (float) $minX > 180) {
        $form_state->setErrorByName("{$identifier}][minX",
          $this->t('The minX coordinate must be between -180 and 180.'));
      }
    }

    // Validate maxX: must be numeric and between -180 and 180.
    $maxX = $values['maxX'] ?? NULL;
    if ($maxX !== NULL && $maxX !== '') {
      if (!is_numeric($maxX)) {
        $form_state->setErrorByName("{$identifier}][maxX",
          $this->t('The maxX coordinate must be a number.'));
      }
      elseif ((float) $maxX < -180 || (float) $maxX > 180) {
        $form_state->setErrorByName("{$identifier}][maxX",
          $this->t('The maxX coordinate must be between -180 and 180.'));
      }
    }

    // Validate minY <= maxY constraint.
    if (is_numeric($minY) && is_numeric($maxY) && (float) $minY > (float) $maxY) {
      $form_state->setErrorByName("{$identifier}][minY",
        $this->t('The minimum Latitude (minY) cannot be greater than the maximum Latitude (maxY).'));
    }
  }

}
