<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit\Hook;

use Drupal\metsis_drupal\Hook\ViewsFiltersSummaryHooks;
use Drupal\Tests\UnitTestCase;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\views\ViewExecutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests composite filter summaries independently of Solr field names.
 */
#[Group('metsis_drupal')]
final class ViewsFiltersSummaryHooksTest extends UnitTestCase {

  /**
   * Tests exposed input is keyed by its configured identifier, not handler ID.
   */
  #[DataProvider('inputProvider')]
  public function testExposedIdentifier(string $plugin_id, string $field, array $input): void {
    $filter = $this->createMock(FilterPluginBase::class);
    $filter->method('getPluginId')->willReturn($plugin_id);
    $filter->options = ['id' => $field, 'expose' => ['identifier' => 'custom_bounds']];
    $filter->view = $this->createMock(ViewExecutable::class);
    $filter->view->method('getExposedInput')->willReturn([
      $field => ['wrong input'],
      'custom_bounds' => $input,
    ]);
    $value = NULL;
    (new ViewsFiltersSummaryHooks())->viewsFiltersSummaryFilterValueAlter($value, $filter);
    self::assertSame($input, $value);
  }

  /**
   * Provides field and plugin combinations with renamed exposed identifiers.
   */
  public static function inputProvider(): array {
    $bbox = ['minX' => '0', 'maxX' => '20', 'minY' => '0', 'maxY' => '70'];
    return [
      'bbox' => ['metsis_filter_bbox', 'bbox', $bbox],
      'RPT' => ['metsis_filter_bbox', 'geospatial_bounds3d', $bbox],
      'dates' => ['metsis_filter_date_range', 'temporal_extent_period_dr', ['min' => '2020-01-01', 'max' => '']],
    ];
  }

  /**
   * Tests a missing exposed input does not become a synthetic active value.
   */
  public function testMissingInput(): void {
    $filter = $this->createMock(FilterPluginBase::class);
    $filter->method('getPluginId')->willReturn('metsis_filter_bbox');
    $filter->options = ['id' => 'geospatial_bounds3d', 'expose' => ['identifier' => 'bounds']];
    $filter->view = $this->createMock(ViewExecutable::class);
    $filter->view->method('getExposedInput')->willReturn([]);
    $value = 'previous value';
    (new ViewsFiltersSummaryHooks())->viewsFiltersSummaryFilterValueAlter($value, $filter);
    self::assertNull($value);
  }

  /**
   * Tests zero coordinates and replacement of contrib's per-coordinate items.
   */
  public function testBboxSummary(): void {
    $filter = $this->createMock(FilterPluginBase::class);
    $filter->method('getPluginId')->willReturn('metsis_filter_bbox');
    $filter->operator = 'intersects';
    $filter->value = ['maxY' => '70.1256', 'minY' => '0', 'maxX' => '20', 'minX' => '0'];
    $info = ['id' => 'custom_bounds', 'value' => ['first', 'second', 'third', 'fourth']];
    $hooks = new ViewsFiltersSummaryHooks();
    $hooks->viewsFiltersSummaryInfoAlter($info, $filter);
    self::assertSame('custom_bounds', $info['id']);
    self::assertSame([
      ['id' => 0, 'raw' => 'bbox', 'value' => 'Intersects(0, 20, 0, 70.126)'],
    ], $info['value']);

    foreach ([[], ['minX' => ''], ['minX' => 'invalid']] as $values) {
      $filter->value = $values;
      $hooks->viewsFiltersSummaryInfoAlter($info, $filter);
      self::assertSame([], $info['value']);
    }
  }

}
