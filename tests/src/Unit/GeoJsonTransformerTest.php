<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit;

use Drupal\metsis_drupal\Plugin\search_api\processor\GeoJsonTransformer;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\views\ViewExecutable;

/**
 * Tests map data isolation and clearing empty search results.
 */
final class GeoJsonTransformerTest extends UnitTestCase {

  /**
   * Tests raw geometry fallback and a subsequent empty result collection.
   */
  public function testScopedResults(): void {
    $view = $this->createMock(ViewExecutable::class);
    $view->dom_id = 'custom-view';
    $query = $this->createMock(QueryInterface::class);
    $query->method('getProcessingLevel')->willReturn(QueryInterface::PROCESSING_FULL);
    $query->method('getOption')->with('search_api_view')->willReturn($view);
    $item = $this->createMock(ItemInterface::class);
    $item->method('getExtraData')->with('search_api_solr_document')->willReturn([
      'geometry_geojson' => '{"type":"Point","coordinates":[10,60]}',
      'title' => 'Custom result',
      'id' => 'no-met-adc-dataset',
      'temporal_extent_start_date' => ['2020-05-01T00:00:00Z', '2019-01-01T00:00:00Z'],
      'temporal_extent_end_date' => ['2021-01-01T00:00:00Z'],
      'isParent' => TRUE,
    ]);
    $item->method('getId')->willReturn('dataset');
    $results = $this->createMock(ResultSetInterface::class);
    $results->method('getQuery')->willReturn($query);
    $results->method('getResultItems')->willReturn([$item]);
    $processor = new GeoJsonTransformer([], 'geojson_transformer', []);
    $processor->postprocessSearchResults($results);
    $collection = $view->element['#attached']['drupalSettings']['metsis_drupal']['search']['instances']['custom-view']['geojson_feature_collection'];
    self::assertSame('Custom result', $collection['features'][0]['properties']['title']);
    self::assertSame([10, 60], $collection['features'][0]['geometry']['coordinates']);
    $properties = $collection['features'][0]['properties'];
    self::assertSame('no-met-adc-dataset', $properties['solr_id']);
    self::assertSame('2019-01-01T00:00:00Z', $properties['temporal_start']);
    // Fewer end dates than start dates means the extent is open-ended.
    self::assertSame('', $properties['temporal_end']);
    self::assertTrue($properties['is_parent']);
    $empty = $this->createMock(ResultSetInterface::class);
    $empty->method('getQuery')->willReturn($query);
    $empty->method('getResultItems')->willReturn([]);
    $processor->postprocessSearchResults($empty);
    self::assertSame([], $view->element['#attached']['drupalSettings']['metsis_drupal']['search']['instances']['custom-view']['geojson_feature_collection']['features']);
  }

}
