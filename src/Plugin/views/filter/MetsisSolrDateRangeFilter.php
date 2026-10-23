<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Plugin\views\filter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\search_api\Plugin\views\filter\SearchApiDate;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Solarium\Core\Query\Helper;

/**
 * Defines a filter for filtering on dates.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("metsis_filter_date_range")
 */
class MetsisSolrDateRangeFilter extends SearchApiDate {


  /**
   * The Solr query helper.
   *
   * @var \Solarium\Core\Query\Helper
   */
  protected $solrQueryHelper;

  /**
   * Disable the possibility to force a single value.
   *
   * @var bool
   */
  protected $alwaysMultiple = TRUE;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Helper $solr_query_helper) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->solrQueryHelper = $solr_query_helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
    $configuration,
    $plugin_id,
    $plugin_definition,
    $container->get('solarium.query_helper')
    );
  }

  /**
   * {@inheritDoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    // Set default operator and type.
    $options['operator'] = ['default' => 'intersects'];
    // Parent options not applicable in this context.
    unset($options["expose"]["contains"]["placeholder"]);
    unset($options["expose"]["contains"]["min_placeholder"]);
    unset($options["expose"]["contains"]["max_placeholder"]);
    unset($options['value']['contains']['value']);
    $options['value']['contains']['type']['default'] = 'date';
    return $options;
  }

  /**
   * Add map input option to exposed UI form.
   *
   * {@inheritdoc}
   */
  public function buildExposeForm(&$form, FormStateInterface $form_state) {
    parent::buildExposeForm($form, $form_state);
    unset($form["expose"]["placeholder"]);
    unset($form["expose"]["min_placeholder"]);
    unset($form["expose"]["max_placeholder"]);
    unset($form["value"]["value"]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildExposedForm(&$form, FormStateInterface $form_state) {
    parent::buildExposedForm($form, $form_state);

    $identifier = $this->options['expose']['identifier'];
    $wrapper = $identifier . '_wrapper';
    $operator_key = $this->options['expose']['operator_id'] ?? '';
    if (empty($form[$wrapper][$identifier])) {
      return;
    }

    $form[$wrapper]['#attributes']['class'][] = 'metsis-date-range-fieldset';
    $form[$wrapper]['#attached']['library'][] = 'metsis_drupal/metsis_date_range_styles';
    $form[$wrapper][$identifier]['#attributes']['class'][] = 'metsis-date-range-fields';
    foreach (['min', 'max'] as $bound) {
      if (isset($form[$wrapper][$identifier][$bound])) {
        $form[$wrapper][$identifier][$bound]['#wrapper_attributes']['class'][] = 'metsis-date-range-field';
        $form[$wrapper][$identifier][$bound]['#wrapper_attributes']['class'][] = 'metsis-date-range-field--' . $bound;
        $form[$wrapper][$identifier][$bound]['#attributes']['data-metsis-date-range-bound'] = $bound;
        $form[$wrapper][$identifier][$bound]['#attributes']['data-metsis-date-range-autosubmit'] = '';
        $form[$wrapper][$identifier][$bound]['#attributes']['data-bef-auto-submit-exclude'] = '';
      }
    }

    if (isset($form[$wrapper][$operator_key]) && is_array($form[$wrapper][$operator_key])) {
      $form[$wrapper][$operator_key]['#wrapper_attributes']['class'][] = 'metsis-date-range-operator';
      $form[$wrapper][$operator_key]['#attributes']['data-bef-auto-submit-exclude'] = '';
      $form[$wrapper][$operator_key]['#attributes']['data-metsis-date-range-autosubmit'] = '';
      $form = BubbleableMetadata::mergeAttachments($form, [
        '#attached' => [
          'library' => ['metsis_drupal/metsis_filter_autosubmit'],
        ],
      ]);
    }
  }

  /**
   * Add a type selector to the value form.
   */
  protected function valueForm(&$form, FormStateInterface $form_state) {

    $form['value']['#type'] = 'container';
    $form['value']['#tree'] = TRUE;

    // Remove single value form element.
    unset($form['value']['value']);

    $value_element = &$form['value'];

    $value_element += [
      'min' => [
        '#title' => $this->t("Start date"),
        '#type' => 'date',
        '#default_value' => $this->value['min'],
      ],
      'max' => [
        '#title' => $this->t("End date"),
        '#type' => 'date',
        '#default_value' => $this->value['max'],
      ],
    ];
  }

  /**
   * Defines the operators supported by this filter.
   *
   * @return array[]
   *   An associative array of operators, keyed by operator ID, with information
   *   about that operator:
   *   - title: The full title of the operator (translated).
   *   - short: The short title of the operator (translated).
   *   - method: The method to call for this operator in query().
   *   - values: The number of values that this operator expects/needs.
   */
  public function operators() {
    $operators = [
      'intersects' => [
        'title' => $this->t('Intersects'),
        'method' => 'opSolrDateRange',
        'short' => $this->t('Intersects'),
        'values' => 2,
      ],
      'contains' => [
        'title' => $this->t('Contains'),
        'method' => 'opSolrDateRange',
        'short' => $this->t('Contains'),
        'values' => 2,
      ],
      'within' => [
        'title' => $this->t('Within'),
        'method' => 'opSolrDateRange',
        'short' => $this->t('Within'),
        'values' => 2,
      ],
    ];
    return $operators;
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
   * {@inheritdoc}
   */
  public function acceptExposedInput($input) {
    if (empty($this->options['exposed'])) {
      return TRUE;
    }
    $identifier = $this->options['expose']['identifier'];
    if (!isset($input[$identifier]) || !is_array($input[$identifier])) {
      return FALSE;
    }
    $input[$identifier] += ['min' => '', 'max' => ''];
    $operator_id = $this->options['expose']['operator_id'] ?? '';
    if (!empty($this->options['expose']['use_operator']) && $operator_id !== '') {
      $input[$operator_id] ??= $this->operator;
    }
    $rc = parent::acceptExposedInput($input);
    // We accept open start and end dates.
    if (($input[$identifier]['min'] ?? '') !== '') {
      $rc = TRUE;
    }
    if (($input[$identifier]['max'] ?? '') !== '') {
      $rc = TRUE;
    }
    return $rc;
  }

  /**
   * Create a solr DateRange Query given operator.
   *
   * Store as an option in the query object.
   * Added to solr query in event subscriber.
   *
   * {@inheritdoc}
   */
  public function query(): void {
    if (empty($this->value)) {
      return;
    }
    // Extract input values.
    $min = intval(strtotime($this->value['min'], 0));
    $max = intval(strtotime($this->value['max'], 0));
    if ($min === 0 && $max === 0) {
      return;
    }
    // Only format the input date if it is not 0.
    $start = $min == 0 ? 0 : $this->solrQueryHelper->formatDate($min);
    $end = $max == 0 ? 0 : $this->solrQueryHelper->formatDate($max);

    // Generate solr query.
    $field = "$this->realField";
    $operator = ucfirst($this->operator);
    $date_range_expression = '';
    if ($start != 0 && $end == 0) {
      $date_range_expression = "[$start TO *]";
    }
    elseif ($start == 0 && $end != 0) {
      $date_range_expression = "[* TO $end]";
    }
    elseif (($start != 0 && $end != 0)) {
      $date_range_expression = "[$start TO $end]";
    }
    if ($date_range_expression != '') {
      $date_range_query = "{!field f={$field} op={$operator}}$date_range_expression";

      // The SearchApiSolr subscriber will add the filter to the solr query.
      $this->getQuery()->setOption('metsis_date_range_filter', $date_range_query);
    }
  }

}
