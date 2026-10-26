<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit\Plugin\Block;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\block\BlockInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\metsis_drupal\Hook\MetsisThemeHooks;
use Drupal\metsis_drupal\Plugin\Block\MetsisSearchExposedFormBlock;
use Drupal\metsis_drupal\Plugin\views\filter\MetsisSolrBboxFilter;
use Drupal\metsis_drupal\Service\MetVocabServiceInterface;
use Drupal\views\Entity\View as ViewEntity;
use Drupal\views\Plugin\views\display\Page;
use Drupal\views\Plugin\views\exposed_form\ExposedFormPluginInterface;
use Drupal\views\Plugin\views\filter\StringFilter;
use Drupal\views\ViewExecutable;
use Drupal\views\ViewExecutableFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the METSIS search exposed form block.
 */
#[CoversClass(MetsisSearchExposedFormBlock::class)]
#[CoversClass(MetsisThemeHooks::class)]
#[Group('metsis_drupal')]
final class MetsisSearchExposedFormBlockTest extends TestCase {

  /**
   * Tests dynamic filter options and canonical exposed-form submission.
   */
  #[Test]
  public function testConfiguredFiltersAndFormAction(): void {
    $account = $this->createMock(AccountInterface::class);
    $view = $this->createMock(ViewExecutable::class);
    $view->storage = $this->createMock(ViewEntity::class);
    $view->method('setDisplay')->with('results')->willReturn(TRUE);
    $view->method('access')->with('results', $account)->willReturn(TRUE);
    $view->method('initHandlers');
    $view->method('getUrlInfo')
      ->with('results')
      ->willReturn($this->getViewUrl('/metsis/search'));

    $hidden_filter = $this->createFilter('Hidden filter', [
      'value' => 'hidden_filter',
      'operator' => 'hidden_filter_op',
      'label' => 'Hidden filter',
    ]);
    $visible_filter = $this->createFilter('Visible filter', [
      'value' => 'visible_filter',
      'operator' => 'visible_filter_op',
      'label' => 'Visible filter',
    ]);
    $facet_filter = $this->createFilter('Collection facet', [
      'value' => 'collection',
      'operator' => '',
      'label' => 'Collection facet',
    ], 'facets_filter');

    $display = $this->getMockBuilder(Page::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getHandlers', 'getPlugin'])
      ->getMock();
    $display->method('getHandlers')
      ->with('filter')
      ->willReturn([
        'hidden_filter_plugin' => $hidden_filter,
        'visible_filter_plugin' => $visible_filter,
        'search_api_fulltext' => $this->createFilter('Search', [
          'value' => 'text',
          'operator' => 'search_api_fulltext_op',
          'label' => 'Search',
        ]),
        'temporal_extent_period_dr' => $this->createFilter('Temporal filter', [
          'value' => 'temporal_extent_period_dr',
          'operator' => 'temporal_extent_period_dr_op',
          'label' => 'Temporal filter',
        ]),
        'bbox' => $this->createFilter('Geographic filter', [
          'value' => 'bbox',
          'operator' => 'bbox_op',
          'label' => 'Geographic filter',
        ]),
        'related_dataset' => $this->createFilter('Related dataset', [
          'value' => 'related_dataset',
          'operator' => 'related_dataset_op',
          'label' => 'Related dataset',
        ]),
        'facets_collection' => $facet_filter,
      ]);
    $exposed_form = $this->createMock(ExposedFormPluginInterface::class);
    $exposed_form->expects(self::never())
      ->method('renderExposedForm');
    $display->method('getPlugin')
      ->with('exposed_form')
      ->willReturn($exposed_form);
    $view->display_handler = $display;
    $view->filter = ['facets_collection' => $facet_filter];
    $view->exposed_widgets = [
      'url' => ['#type' => 'textfield', '#element_validate' => ['honeypot_validate']],
      'honeypot_time' => ['#type' => 'hidden', '#value' => 'honeypot-test-token'],
      '#cache' => ['max-age' => 0, 'contexts' => ['user.permissions']],
      'hidden_filter_wrapper' => [
        '#type' => 'container',
        'hidden_filter' => ['#type' => 'textfield'],
      ],
      'hidden_filter_op' => ['#type' => 'select'],
      'visible_filter' => ['#type' => 'textfield'],
      'visible_filter_op' => ['#type' => 'select'],
      'search-box-container' => [
        '#type' => 'container',
        'actions' => [
          'submit' => ['#type' => 'submit', '#value' => 'Update filters'],
        ],
      ],
      'temporal_extent_period_dr_wrapper' => ['#type' => 'container'],
      'bbox_wrapper' => ['#type' => 'fieldset'],
      'related_dataset' => ['#type' => 'textfield'],
      'collection' => ['#type' => 'select', '#options' => ['all' => 'All', 'one' => 'One']],
      'actions' => [
        '#type' => 'container',
        'submit_filters' => ['#type' => 'component'],
      ],
      'sort_by' => ['#type' => 'select'],
      'sort_order' => ['#type' => 'select'],
      'items_per_page' => ['#type' => 'select'],
      '#attributes' => [
        'data-bef-auto-submit' => '',
        'data-bef-auto-submit-delay' => '500',
        'data-bef-auto-submit-minimum-length' => '3',
        'data-bef-auto-submit-full-form' => '',
      ],
      '#info' => [
        'filter-hidden_filter_plugin' => [],
        'filter-visible_filter_plugin' => [],
        'filter-facets_collection' => [],
      ],
    ];
    $view->expects(self::exactly(2))
      ->method('execute')
      ->with('results')
      ->willReturn(TRUE);

    $view_factory = $this->createMock(ViewExecutableFactory::class);
    $view_factory->method('get')->willReturn($view);
    $view_storage = $this->createMock(EntityStorageInterface::class);
    $view_storage->method('load')->with('metsis_search')
      ->willReturn($this->createMock(ViewEntity::class));
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('view')->willReturn($view_storage);

    $block = new MetsisSearchExposedFormBlock(
      [
        'disabled_filters' => ['hidden_filter_plugin'],
        'filter_weights' => [
          'search_api_fulltext' => -8,
          'temporal_extent_period_dr' => 4,
          'bbox' => 6,
          'related_dataset' => 3,
          'facets_collection' => 5,
          'secondary_search' => 8,
        ],
        'filter_columns' => [
          'temporal_extent_period_dr' => '1',
          'bbox' => '3',
          'related_dataset' => 'invalid',
          'secondary_search' => '1',
        ],
        'column_count' => 2,
      ],
      'metsis_search_exposed_form',
      ['provider' => 'metsis_drupal', 'admin_label' => 'METSIS Search exposed form'],
      $view_factory,
      $entity_type_manager,
    );
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')
      ->willReturnCallback(static fn (string $string, array $args = []): string => strtr($string, $args));
    $block->setStringTranslation($translation);

    $configuration_form = $block->buildConfigurationForm([], new FormState());
    $filter_options = $configuration_form['shown_filters']['#options'];
    self::assertIsArray($filter_options);
    $secondary_search_label = $filter_options['secondary_search'];
    unset($filter_options['secondary_search']);
    self::assertSame(
      [
        'hidden_filter_plugin' => 'Hidden filter',
        'visible_filter_plugin' => 'Visible filter',
        'search_api_fulltext' => 'Search',
        'temporal_extent_period_dr' => 'Temporal filter',
        'bbox' => 'Geographic filter',
        'related_dataset' => 'Related dataset',
        'facets_collection' => 'Collection facet',
      ],
      $filter_options,
    );
    self::assertInstanceOf(
      TranslatableMarkup::class,
      $secondary_search_label,
    );
    self::assertSame(
      'Secondary Search button',
      $secondary_search_label->getUntranslatedString(),
    );
    self::assertSame(
      [
        'visible_filter_plugin',
        'search_api_fulltext',
        'temporal_extent_period_dr',
        'bbox',
        'related_dataset',
        'facets_collection',
        'secondary_search',
      ],
      $configuration_form['shown_filters']['#default_value'],
    );
    self::assertSame(-8, $configuration_form['filter_weights']['search_api_fulltext']['#default_value']);
    self::assertSame(4, $configuration_form['filter_weights']['temporal_extent_period_dr']['#default_value']);
    self::assertSame(6, $configuration_form['filter_weights']['bbox']['#default_value']);
    self::assertSame('1', $configuration_form['filter_columns']['temporal_extent_period_dr']['#default_value']);
    self::assertSame('3', $configuration_form['filter_columns']['bbox']['#default_value']);
    self::assertSame('auto', $configuration_form['filter_columns']['related_dataset']['#default_value']);
    self::assertSame('1', $configuration_form['filter_columns']['secondary_search']['#default_value']);
    self::assertSame(8, $configuration_form['filter_weights']['secondary_search']['#default_value']);
    self::assertSame(2, $configuration_form['column_count']['#default_value']);

    $previous_container = \Drupal::hasContainer() ? \Drupal::getContainer() : NULL;
    $container = new ContainerBuilder();
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    \Drupal::setContainer($container);
    try {
      self::assertTrue($block->access($account));
    }
    finally {
      if ($previous_container === NULL) {
        \Drupal::unsetContainer();
      }
      else {
        \Drupal::setContainer($previous_container);
      }
    }

    $form = $block->build();
    self::assertSame($view->exposed_widgets['url'], $form['url']);
    self::assertSame($view->exposed_widgets['honeypot_time'], $form['honeypot_time']);
    self::assertSame($view->exposed_widgets['#cache'], $form['#cache']);
    self::assertArrayNotHasKey('hidden_filter', $form['metsis-search-filter-grid']['column_1']);
    self::assertArrayNotHasKey('hidden_filter_wrapper', $form['metsis-search-filter-grid']['column_1']);
    self::assertArrayNotHasKey('hidden_filter_op', $form['metsis-search-filter-grid']['column_1']);
    self::assertArrayHasKey('visible_filter', $form['metsis-search-filter-grid']['column_1']);
    self::assertArrayHasKey('visible_filter_op', $form['metsis-search-filter-grid']['column_1']);
    self::assertArrayHasKey('search-box-container', $form['metsis-search-filter-grid']['column_1']);
    self::assertArrayHasKey('related_dataset', $form['metsis-search-filter-grid']['column_2']);
    self::assertArrayHasKey('collection', $form['metsis-search-filter-grid']['column_2']);
    self::assertArrayNotHasKey('column_3', $form['metsis-search-filter-grid']);
    self::assertArrayNotHasKey('sort_by', $form);
    self::assertArrayNotHasKey('sort_order', $form);
    self::assertArrayNotHasKey('items_per_page', $form);
    self::assertArrayNotHasKey('actions', $form);
    self::assertArrayHasKey(
      'actions',
      $form['metsis-search-filter-grid']['column_1'],
    );
    self::assertSame(
      8,
      $form['metsis-search-filter-grid']['column_1']['actions']['#weight'],
    );
    self::assertSame(
      'metsis_drupal:icon_button',
      $form['metsis-search-filter-grid']['column_1']['actions']['submit_filters']['#component'],
    );
    self::assertSame(
      'magnifier',
      $form['metsis-search-filter-grid']['column_1']['actions']['submit_filters']['#props']['icon_id'],
    );
    self::assertSame(
      'secondary_search',
      $form['metsis-search-filter-grid']['column_1']['actions']['#attributes']['data-metsis-filter-id'],
    );
    $secondary_submit = $form['metsis-search-filter-grid']['column_1']['actions']['submit_filters']['#slots']['button'];
    self::assertSame('submit', $secondary_submit['#type']);
    self::assertInstanceOf(
      TranslatableMarkup::class,
      $secondary_submit['#value'],
    );
    self::assertSame(
      'Search',
      $secondary_submit['#value']->getUntranslatedString(),
    );
    self::assertArrayNotHasKey(
      'data-bef-auto-submit',
      $form['#attributes'],
    );
    self::assertArrayNotHasKey(
      'data-bef-auto-submit-delay',
      $form['#attributes'],
    );
    self::assertArrayNotHasKey(
      'data-bef-auto-submit-minimum-length',
      $form['#attributes'],
    );
    self::assertArrayNotHasKey(
      'data-bef-auto-submit-full-form',
      $form['#attributes'],
    );
    self::assertSame(
      4,
      $form['metsis-search-filter-grid']['column_1']['temporal_extent_period_dr_wrapper']['#weight'],
    );
    self::assertSame(6, $form['metsis-search-filter-grid']['column_2']['bbox_wrapper']['#weight']);
    self::assertSame(-8, $form['metsis-search-filter-grid']['column_1']['search-box-container']['#weight']);
    $search_button_value = $form['metsis-search-filter-grid']['column_1']['search-box-container']['actions']['submit']['#value'];
    self::assertInstanceOf(TranslatableMarkup::class, $search_button_value);
    self::assertSame('Search', $search_button_value->getUntranslatedString());
    self::assertSame(3, $form['metsis-search-filter-grid']['column_2']['related_dataset']['#weight']);
    self::assertSame(5, $form['metsis-search-filter-grid']['column_2']['collection']['#weight']);
    self::assertSame('/metsis/search', $form['#action']);
    self::assertTrue($form['#metsis_search_exposed_form_block']);
    self::assertContains('metsis-search-exposed-form-block', $form['#attributes']['class']);
    self::assertContains(
      'metsis_drupal/metsis_search_exposed_form_block',
      $form['#attached']['library'],
    );
    self::assertContains(
      'metsis_drupal/metsis_icon_sync',
      $form['#attached']['library'],
    );
    self::assertSame('2', $form['metsis-search-filter-grid']['#attributes']['data-metsis-column-count']);
    self::assertSame(
      '1',
      $form['metsis-search-filter-grid']['column_1']['search-box-container']['#attributes']['data-metsis-filter-column'],
    );
    self::assertSame(
      '3',
      $form['metsis-search-filter-grid']['column_2']['bbox_wrapper']['#attributes']['data-metsis-filter-column'],
    );
    self::assertSame(
      'auto',
      $form['metsis-search-filter-grid']['column_2']['related_dataset']['#attributes']['data-metsis-filter-column'],
    );

    $submit_state = new FormState();
    $submit_state->setValue('shown_filters', [
      'hidden_filter_plugin' => 'hidden_filter_plugin',
      'search_api_fulltext' => 'search_api_fulltext',
      'temporal_extent_period_dr' => 'temporal_extent_period_dr',
      'bbox' => 'bbox',
      'related_dataset' => 'related_dataset',
      'facets_collection' => 'facets_collection',
    ]);
    $submit_state->setValue('filter_weights', ['bbox' => 9, 'secondary_search' => 11]);
    $submit_state->setValue('filter_columns', [
      'bbox' => '3',
      'related_dataset' => 'invalid',
      'secondary_search' => '3',
    ]);
    $submit_state->setValue('column_count', 1);
    $block->blockSubmit([], $submit_state);
    $saved_configuration_form = $block->buildConfigurationForm([], new FormState());
    self::assertSame(
      [
        'hidden_filter_plugin',
        'search_api_fulltext',
        'temporal_extent_period_dr',
        'bbox',
        'related_dataset',
        'facets_collection',
      ],
      $saved_configuration_form['shown_filters']['#default_value'],
    );
    self::assertSame(9, $saved_configuration_form['filter_weights']['bbox']['#default_value']);
    self::assertSame(11, $saved_configuration_form['filter_weights']['secondary_search']['#default_value']);
    self::assertSame('3', $saved_configuration_form['filter_columns']['bbox']['#default_value']);
    self::assertSame('auto', $saved_configuration_form['filter_columns']['related_dataset']['#default_value']);
    self::assertSame('3', $saved_configuration_form['filter_columns']['secondary_search']['#default_value']);
    self::assertSame(1, $saved_configuration_form['column_count']['#default_value']);
    $form_without_secondary_search = $block->build();
    self::assertArrayNotHasKey('actions', $form_without_secondary_search['metsis-search-filter-grid']['column_1']);

    $theme_hooks = new MetsisThemeHooks($this->createMock(MetVocabServiceInterface::class));
    $block_suggestions = [];
    $theme_hooks->themeSuggestionsBlockAlter($block_suggestions, [
      'elements' => ['#plugin_id' => 'metsis_search_exposed_form'],
    ]);
    self::assertSame(
      ['block__metsis_search_exposed_form__metsis_search__results'],
      $block_suggestions,
    );

    $form_suggestions = [];
    $theme_hooks->themeHookSuggestion($form_suggestions, [
      'form' => [
        '#metsis_search_exposed_form_block' => TRUE,
        '#metsis_search_view_id' => 'metsis_search',
        '#metsis_search_display_id' => 'results',
      ],
    ]);
    self::assertSame([
      'views_exposed_form__metsis_search_block',
      'views_exposed_form__metsis_search_block__metsis_search__results',
    ], $form_suggestions);

    $container_suggestions = [];
    $theme_hooks->themeSuggestionsContainerAlter($container_suggestions, [
      'element' => ['#metsis_search_box' => TRUE],
    ]);
    self::assertSame(['container__metsis_search_box'], $container_suggestions);
  }

  /**
   * Tests positive defaults, empty selections, and conditional controls.
   */
  #[Test]
  public function testPositiveSelectionAndConditionalControls(): void {
    $handlers = [
      'search_api_fulltext' => $this->createFilter('Search', ['value' => 'text']),
      'temporal_extent_period_dr' => $this->createFilter('Dates', ['value' => 'dates']),
      'bbox' => $this->createFilter('Bbox', ['value' => 'bbox']),
      'extra' => $this->createFilter('Extra', ['value' => 'extra']),
    ];
    $block = $this->createBlock([], $handlers);
    $form = $block->buildConfigurationForm(['#parents' => ['settings']], new FormState());
    self::assertSame(
      ['search_api_fulltext', 'temporal_extent_period_dr', 'bbox', 'secondary_search'],
      $form['shown_filters']['#default_value'],
    );
    self::assertSame(
      ['visible' => [':input[name="settings[shown_filters][bbox]"]' => ['checked' => TRUE]]],
      $form['filter_columns']['bbox']['#states'],
    );
    self::assertSame($form['filter_columns']['bbox']['#states'], $form['filter_weights']['bbox']['#states']);
    $block->setConfiguration(['shown_filters' => []]);
    self::assertSame([], $block->buildConfigurationForm([], new FormState())['shown_filters']['#default_value']);
    $block->setConfiguration(['disabled_filters' => ['bbox', 'secondary_search', 'stale']]);
    self::assertSame(['search_api_fulltext', 'temporal_extent_period_dr', 'extra'], $block->getConfiguration()['shown_filters']);
    self::assertArrayNotHasKey('disabled_filters', $block->getConfiguration());
    $block->setConfiguration(['shown_filters' => ['extra', 'stale']]);
    self::assertSame(['extra'], $block->buildConfigurationForm([], new FormState())['shown_filters']['#default_value']);
  }

  /**
   * Tests fixed hidden predicates and map overrides with custom identifiers.
   */
  #[Test]
  public function testPredicateAndMapPresentation(): void {
    $bbox = $this->createBboxFilter();
    $date = $this->createFilter('Dates', ['value' => 'dates', 'operator' => 'date_relation'], 'metsis_filter_date_range');
    $date->options['expose'] += ['use_operator' => TRUE, 'operator_id' => 'date_relation'];
    $widgets = [
      'bounds_wrapper' => [
        '#type' => 'fieldset',
        'spatial_relation' => ['#type' => 'select', '#value' => 'contains'],
        'bbox_map_filter' => ['map' => ['#type' => 'container']],
      ],
      'dates_wrapper' => [
        '#type' => 'fieldset',
        'dates_wrapper' => [
          'date_relation' => ['#type' => 'select', '#value' => 'within'],
        ],
      ],
    ];
    $block = $this->createBlock([
      'shown_filters' => ['bbox', 'dates'],
      'hidden_predicates' => ['bbox', 'dates'],
      'bbox_map_heights' => ['bbox' => 180],
      'compact' => TRUE,
      'column_count' => 1,
    ], ['bbox' => $bbox, 'dates' => $date], $widgets);
    $configuration_form = $block->buildConfigurationForm([], new FormState());
    self::assertFalse($configuration_form['hidden_predicates']['bbox']['#disabled']);
    self::assertSame(180, $configuration_form['bbox_map_overrides']['bbox']['height']['#default_value']);
    $form = $block->build();
    $column = $form['metsis-search-filter-grid']['column_1'];
    self::assertSame(
      ['#type' => 'hidden', '#name' => 'spatial_relation', '#value' => 'intersects'],
      $column['bounds_wrapper']['spatial_relation'],
    );
    self::assertSame(
      ['#type' => 'hidden', '#name' => 'date_relation', '#value' => 'intersects'],
      $column['dates_wrapper']['dates_wrapper']['date_relation'],
    );
    self::assertSame('--metsis-bbox-map-height: 180px;', $column['bounds_wrapper']['bbox_map_filter']['map']['#attributes']['style']);
    self::assertContains('metsis-search-exposed-form-block--compact', $form['#attributes']['class']);

    $date->options['expose']['use_operator'] = FALSE;
    $bbox->options['expose']['operator_limit_selection'] = TRUE;
    $bbox->options['expose']['operator_list'] = ['within' => 'within'];
    $configuration_form = $block->buildConfigurationForm([], new FormState());
    self::assertTrue($configuration_form['hidden_predicates']['dates']['#disabled']);
    self::assertTrue($configuration_form['hidden_predicates']['bbox']['#disabled']);
    $form = $block->build();
    self::assertSame('contains', $form['metsis-search-filter-grid']['column_1']['bounds_wrapper']['spatial_relation']['#value']);
    self::assertSame('within', $form['metsis-search-filter-grid']['column_1']['dates_wrapper']['dates_wrapper']['date_relation']['#value']);

    $state = new FormState();
    $state->setValue('shown_filters', ['bbox' => 'bbox', 'dates' => 'dates']);
    $state->setValue('hidden_predicates', ['bbox' => TRUE, 'dates' => TRUE]);
    $block->blockSubmit([], $state);
    self::assertSame([], $block->getConfiguration()['hidden_predicates']);
    self::assertSame([], $block->getConfiguration()['bbox_map_heights']);
  }

  /**
   * Tests map height validation and applicability on submission.
   */
  #[Test]
  public function testMapHeightValidation(): void {
    $bbox = $this->createBboxFilter();
    $block = $this->createBlock([], ['bbox' => $bbox]);
    foreach ([150, 1000, 149, 1001, '1.5', ''] as $height) {
      $state = new FormState();
      $state->setValue('shown_filters', ['bbox' => 'bbox']);
      $state->setValue('bbox_map_overrides', ['bbox' => ['enabled' => TRUE, 'height' => $height]]);
      $form = $block->buildConfigurationForm([], $state);
      $form['bbox_map_overrides']['bbox']['height']['#parents'] = ['bbox_map_overrides', 'bbox', 'height'];
      $block->blockValidate($form, $state);
      self::assertSame(!in_array($height, [150, 1000], TRUE), $state->getErrors() !== []);
      if ($state->getErrors() === []) {
        $block->blockSubmit($form, $state);
        self::assertSame(['bbox' => $height], $block->getConfiguration()['bbox_map_heights']);
      }
    }
    $state = new FormState();
    $state->setValue('shown_filters', []);
    $state->setValue('bbox_map_overrides', ['bbox' => ['enabled' => TRUE, 'height' => 180]]);
    $state->setValue('hidden_predicates', ['bbox' => TRUE]);
    $block->blockSubmit([], $state);
    self::assertSame([], $block->getConfiguration()['shown_filters']);
    self::assertSame([], $block->getConfiguration()['hidden_predicates']);
    self::assertSame([], $block->getConfiguration()['bbox_map_heights']);
  }

  /**
   * Tests the update hook preserves existing block filter visibility.
   */
  #[Test]
  public function testVisibilityUpdate(): void {
    require_once dirname(__DIR__, 5) . '/metsis_drupal.install';
    $plugin = $this->createBlock(
      ['disabled_filters' => ['extra']],
      ['extra' => $this->createFilter('Extra', ['value' => 'extra'])],
    );
    $entity = $this->createMock(BlockInterface::class);
    $entity->method('getPluginId')->willReturn('metsis_search_exposed_form');
    $entity->method('get')->with('settings')->willReturn(['disabled_filters' => ['extra']]);
    $entity->method('getPlugin')->willReturn($plugin);
    $entity->expects(self::once())->method('set')->with('settings', $plugin->getConfiguration());
    $entity->expects(self::once())->method('save');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn([$entity]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('hasDefinition')->with('block')->willReturn(TRUE);
    $manager->method('getStorage')->with('block')->willReturn($storage);
    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $manager);
    $previous = \Drupal::hasContainer() ? \Drupal::getContainer() : NULL;
    \Drupal::setContainer($container);
    try {
      metsis_drupal_update_11001();
      self::assertSame(['secondary_search'], $plugin->getConfiguration()['shown_filters']);
    }
    finally {
      if ($previous === NULL) {
        \Drupal::unsetContainer();
      }
      else {
        \Drupal::setContainer($previous);
      }
    }
  }

  /**
   * Creates a bbox handler with custom exposed identifiers.
   */
  private function createBboxFilter(): MetsisSolrBboxFilter&MockObject {
    $bbox = $this->getMockBuilder(MetsisSolrBboxFilter::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['canExpose', 'isExposed', 'exposedInfo', 'getPluginId', 'isAGroup'])
      ->getMock();
    $bbox->options = [
      'expose' => [
        'label' => 'Geography',
        'identifier' => 'bounds',
        'operator_id' => 'spatial_relation',
        'use_operator' => TRUE,
        'map_input' => TRUE,
        'map_height' => 350,
      ],
    ];
    $bbox->method('canExpose')->willReturn(TRUE);
    $bbox->method('isExposed')->willReturn(TRUE);
    $bbox->method('isAGroup')->willReturn(FALSE);
    $bbox->method('getPluginId')->willReturn('metsis_filter_bbox');
    $bbox->method('exposedInfo')->willReturn(['value' => 'bounds', 'operator' => 'spatial_relation']);
    return $bbox;
  }

  /**
   * Creates a block with an isolated results display.
   */
  private function createBlock(array $configuration, array $handlers, array $widgets = []): MetsisSearchExposedFormBlock {
    $view = $this->createMock(ViewExecutable::class);
    $view->method('setDisplay')->willReturn(TRUE);
    $view->method('getUrlInfo')->willReturn($this->getViewUrl('/metsis/search'));
    $view->filter = [];
    $display = $this->getMockBuilder(Page::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getHandlers', 'getPlugin'])
      ->getMock();
    $display->method('getHandlers')->willReturn($handlers);
    $exposed_form = $this->createMock(ExposedFormPluginInterface::class);
    $exposed_form->method('renderExposedForm')->willReturn($widgets);
    $display->method('getPlugin')->willReturn($exposed_form);
    $view->display_handler = $display;
    $factory = $this->createMock(ViewExecutableFactory::class);
    $factory->method('get')->willReturn($view);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($this->createMock(ViewEntity::class));
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturn($storage);
    $block = new MetsisSearchExposedFormBlock(
      $configuration,
      'metsis_search_exposed_form',
      ['provider' => 'metsis_drupal', 'admin_label' => 'METSIS search'],
      $factory,
      $manager,
    );
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')->willReturnCallback(static fn (string $string, array $args = []): string => strtr($string, $args));
    $block->setStringTranslation($translation);
    return $block;
  }

  /**
   * Creates an exposed Views filter handler mock.
   *
   * @param string $label
   *   The exposed filter label.
   * @param array $exposed_info
   *   Exposed form element metadata.
   * @param string $plugin_id
   *   The filter handler plugin ID.
   *
   * @return \Drupal\views\Plugin\views\filter\StringFilter&\PHPUnit\Framework\MockObject\MockObject
   *   The filter handler mock.
   */
  private function createFilter(string $label, array $exposed_info, string $plugin_id = 'string'): StringFilter&MockObject {
    $filter = $this->getMockBuilder(StringFilter::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['canExpose', 'isExposed', 'adminLabel', 'exposedInfo', 'getPluginId', 'isAGroup'])
      ->getMock();
    $filter->options = ['expose' => ['label' => $label]];
    $filter->method('canExpose')->willReturn(TRUE);
    $filter->method('isExposed')->willReturn(TRUE);
    $filter->method('adminLabel')->willReturn($label);
    $filter->method('exposedInfo')->willReturn($exposed_info);
    $filter->method('getPluginId')->willReturn($plugin_id);
    $filter->method('isAGroup')->willReturn(FALSE);

    return $filter;
  }

  /**
   * Creates a View URL mock.
   */
  private function getViewUrl(string $path): Url&MockObject {
    $url = $this->createMock(Url::class);
    $url->method('toString')->willReturn($path);

    return $url;
  }

}
