<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit\Plugin\views\filter;

use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Element\Number;
use Drupal\metsis_drupal\Plugin\views\filter\MetsisSolrBboxFilter;
use Drupal\metsis_drupal\Plugin\views\filter\MetsisSolrDateRangeFilter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests exposed METSIS filter configuration and operator identifiers.
 */
#[Group('metsis_drupal')]
final class MetsisExposedFiltersTest extends UnitTestCase {

  /**
   * Tests custom and non-exposed operator forms.
   *
   * @param class-string<\Drupal\views\Plugin\views\filter\FilterPluginBase> $class
   *   The filter class.
   * @param array $values
   *   The filter values.
   */
  #[DataProvider('filterProvider')]
  public function testOperatorIdentifiers(string $class, array $values): void {
    $filter = $this->getMockBuilder($class)->disableOriginalConstructor()->onlyMethods([])->getMock();
    $filter->setStringTranslation($this->getStringTranslationStub());
    $filter->options = [
      'exposed' => TRUE,
      'is_grouped' => FALSE,
      'expose' => [
        'identifier' => 'custom_bounds',
        'operator_id' => 'custom_predicate',
        'use_operator' => TRUE,
        'label' => 'Bounds',
        'description' => '',
        'map_input' => TRUE,
        'user_input' => TRUE,
        'tabs_component' => TRUE,
      ],
    ];
    $filter->value = $values;
    $filter->operator = 'intersects';
    $form = [];
    $filter->buildExposedForm($form, new FormState());
    self::assertArrayHasKey('custom_predicate', $form['custom_bounds_wrapper']);
    self::assertArrayNotHasKey('custom_bounds_op', $form['custom_bounds_wrapper']);
    self::assertArrayHasKey('data-bef-auto-submit-exclude', $form['custom_bounds_wrapper']['custom_predicate']['#attributes']);
    if ($filter instanceof MetsisSolrDateRangeFilter) {
      self::assertContains('metsis-date-range-fieldset', $form['custom_bounds_wrapper']['#attributes']['class']);
      self::assertContains('metsis_drupal/metsis_date_range_styles', $form['custom_bounds_wrapper']['#attached']['library']);
    }
    if ($filter instanceof MetsisSolrBboxFilter) {
      self::assertSame(250, $filter->getExposedMapHeight());
      self::assertSame('--metsis-bbox-map-height: 250px;', $form['custom_bounds_wrapper']['bbox_map_filter']['map']['#attributes']['style']);
      foreach ([150, 1000] as $height) {
        $filter->setExposedMapHeight($form['custom_bounds_wrapper'], $height);
        self::assertSame("--metsis-bbox-map-height: {$height}px;", $form['custom_bounds_wrapper']['bbox_map_filter']['map']['#attributes']['style']);
      }
    }
    $filter->options['expose']['use_operator'] = FALSE;
    $form = [];
    $filter->buildExposedForm($form, new FormState());
    self::assertArrayNotHasKey('custom_predicate', $form['custom_bounds_wrapper']);
    self::assertArrayHasKey('custom_bounds', $form['custom_bounds_wrapper']);
    if ($filter instanceof MetsisSolrDateRangeFilter) {
      self::assertContains('metsis_drupal/metsis_date_range_styles', $form['custom_bounds_wrapper']['#attached']['library']);
    }
  }

  /**
   * Provides the spatial and temporal filter classes and input shapes.
   */
  public static function filterProvider(): array {
    return [
      'bbox' => [MetsisSolrBboxFilter::class, ['minX' => '', 'maxX' => '', 'minY' => '', 'maxY' => '']],
      'dates' => [MetsisSolrDateRangeFilter::class, ['min' => '', 'max' => '']],
    ];
  }

  /**
   * Tests all input modes attach layout independently of map and tab assets.
   */
  public function testBboxInputModes(): void {
    $modes = [
      [FALSE, FALSE, FALSE],
      [TRUE, FALSE, FALSE],
      [TRUE, TRUE, FALSE],
      [TRUE, TRUE, TRUE],
    ];
    foreach ($modes as [$map, $coords, $tabs]) {
      foreach ([FALSE, TRUE] as $predicate) {
        $filter = $this->getMockBuilder(MetsisSolrBboxFilter::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $filter->setStringTranslation($this->getStringTranslationStub());
        $filter->options = [
          'exposed' => TRUE,
          'is_grouped' => FALSE,
          'expose' => [
            'identifier' => 'custom_bounds',
            'operator_id' => 'custom_predicate',
            'use_operator' => $predicate,
            'label' => 'Bounds',
            'description' => '',
            'map_input' => $map,
            'user_input' => $coords,
            'tabs_component' => $tabs,
          ],
        ];
        $filter->value = ['minX' => '', 'maxX' => '', 'minY' => '', 'maxY' => ''];
        $filter->operator = 'intersects';
        $form = [];
        $filter->buildExposedForm($form, new FormState());
        $wrapper = $form['custom_bounds_wrapper'];
        self::assertContains('metsis_drupal/bbox_map_filter_styles', $wrapper['#attached']['library']);
        self::assertSame($map, $wrapper['#metsis_bbox_show_map']);
        self::assertSame(!$map || $coords, $wrapper['#metsis_bbox_show_coords']);
        self::assertSame($tabs, $wrapper['#metsis_bbox_use_tabs']);
        self::assertSame($predicate, isset($wrapper['custom_predicate']));
        self::assertContains('metsis-bbox-fields', $wrapper['custom_bounds']['#attributes']['class']);
        self::assertSame($map, isset($wrapper['bbox_map_filter']));
        foreach (['minX', 'maxX', 'minY', 'maxY'] as $coordinate) {
          self::assertSame($map && !$coords ? 'hidden' : 'textfield', $wrapper['custom_bounds'][$coordinate]['#type']);
          self::assertContains('metsis-bbox-field', $wrapper['custom_bounds'][$coordinate]['#wrapper_attributes']['class']);
        }
      }
    }
  }

  /**
   * Tests a submitted height survives Views option unpacking and rendering.
   */
  public function testConfiguredBboxHeight(): void {
    $filter = $this->getMockBuilder(MetsisSolrBboxFilter::class)
      ->disableOriginalConstructor()
      ->onlyMethods([])
      ->getMock();
    $filter->setStringTranslation($this->getStringTranslationStub());
    $filter->options = [
      'exposed' => TRUE,
      'is_grouped' => FALSE,
      'expose' => [
        'identifier' => 'bounds',
        'operator_id' => 'bounds_op',
        'use_operator' => TRUE,
        'label' => 'Bounds',
        'description' => '',
        'map_input' => TRUE,
        'user_input' => TRUE,
        'tabs_component' => TRUE,
        'map_height' => 250,
      ],
    ];
    $filter->value = ['minX' => '', 'maxX' => '', 'minY' => '', 'maxY' => ''];
    $filter->operator = 'intersects';
    foreach (['150', '333', '1000'] as $height) {
      $state = new FormState();
      $element = [
        '#value' => $height,
        '#parents' => ['options', 'expose', 'map_height'],
        '#min' => MetsisSolrBboxFilter::MIN_MAP_HEIGHT,
        '#max' => MetsisSolrBboxFilter::MAX_MAP_HEIGHT,
        '#step' => 1,
      ];
      $complete_form = [];
      Number::validateNumber($element, $state, $complete_form);
      self::assertSame([], $state->getErrors());
      $submitted = $filter->options['expose'];
      $submitted['map_height'] = $height;
      $options = $filter->submitFormCalculateOptions($filter->options, [
        'expose' => $submitted,
      ]);
      $filter->unpackOptions($filter->options, $options, NULL, FALSE);
      self::assertSame((int) $height, $filter->getExposedMapHeight());
      $form = [];
      $filter->buildExposedForm($form, new FormState());
      self::assertSame(
        "--metsis-bbox-map-height: {$height}px;",
        $form['bounds_wrapper']['bbox_map_filter']['map']['#attributes']['style'],
      );
      $filter->setExposedMapHeight($form['bounds_wrapper'], 261);
      self::assertSame((int) $height, $filter->getExposedMapHeight());
      $view_form = [];
      $filter->buildExposedForm($view_form, new FormState());
      self::assertSame(
        "--metsis-bbox-map-height: {$height}px;",
        $view_form['bounds_wrapper']['bbox_map_filter']['map']['#attributes']['style'],
      );
    }
  }

  /**
   * Tests zero coordinates are valid with a renamed bbox identifier.
   */
  public function testZeroBboxCoordinates(): void {
    $filter = $this->getMockBuilder(MetsisSolrBboxFilter::class)
      ->disableOriginalConstructor()
      ->onlyMethods([])
      ->getMock();
    $filter->setStringTranslation($this->getStringTranslationStub());
    $filter->options = [
      'exposed' => TRUE,
      'expose' => ['identifier' => 'custom_bounds'],
    ];
    $state = new FormState();
    $state->setValue('custom_bounds', ['minX' => '0', 'maxX' => '20', 'minY' => '0', 'maxY' => '70']);
    $form = [];
    $filter->validateExposed($form, $state);
    self::assertSame([], $state->getErrors());
  }

  /**
   * Tests optional open-ended date input with a custom filter identifier.
   */
  public function testCustomDateInput(): void {
    $filter = $this->getMockBuilder(MetsisSolrDateRangeFilter::class)
      ->disableOriginalConstructor()
      ->onlyMethods([])
      ->getMock();
    $filter->options = [
      'exposed' => TRUE,
      'is_grouped' => FALSE,
      'expose' => ['identifier' => 'dates'],
    ];
    $filter->setStringTranslation($this->getStringTranslationStub());
    $filter->value = ['min' => '', 'max' => '', 'type' => 'date'];
    $filter->operator = 'intersects';
    self::assertFalse($filter->acceptExposedInput([]));
    self::assertTrue($filter->acceptExposedInput(['dates' => ['min' => '2020-01-01', 'max' => '']]));
  }

}
