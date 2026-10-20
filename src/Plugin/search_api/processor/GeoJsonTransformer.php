<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Plugin\search_api\processor;

use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\metsis_drupal\LoggerTrait;
use Drupal\search_api\IndexInterface;
use Drupal\metsis_drupal\MetsisConstants;
use Drupal\metsis_drupal\Utility\MetsisSolrUtilities;

/**
 * Process geojson field transformer query.
 *
 * @SearchApiProcessor(
 *   id = "geojson_transformer",
 *   label = @Translation("Solr geojson_transformer"),
 *   description = @Translation("Adds geosjon transformer field"),
 *   stages = {
 *     "postprocess_query" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class GeoJsonTransformer extends ProcessorPluginBase {
  use LoggerTrait;

  /**
   * {@inheritDoc}
   */
  public static function supportsIndex(IndexInterface $index) {
    if ($index->id() === MetsisConstants::METSIS_SOLR_INDEX_ID) {
      return TRUE;
    }
    return FALSE;

  }

  /**
   * {@inheritdoc}
   */
  public function postprocessSearchResults(ResultSetInterface $results) {
    $query = $results->getQuery();

    // Ensure we have results to process.
    if ($query->getProcessingLevel() !== QueryInterface::PROCESSING_FULL) {
      return;
    }

    // Array to store all GeoJSON features.
    $features = [];
    // Iterate over the result items.
    foreach ($results->getResultItems() as $result_item) {
      // Get the field value for the GeoJSON field.
      $raw_document = $result_item->getExtraData('search_api_solr_document');
      $raw_fields = is_object($raw_document) && method_exists($raw_document, 'getFields') ? (array) $raw_document->getFields() : (is_array($raw_document) ? $raw_document : []);
      $values = $this->getFieldValues($result_item, $raw_fields, 'geometry_geojson');
      if ($values !== []) {
        if (count($values) !== 1) {
          continue;
        }
        $geojsonString = $values[0];
        $geoJsonArray = json_decode($geojsonString, TRUE);
        // Check if the value is an GeoJsonArray.
        if (is_array($geoJsonArray) && array_key_exists('type', $geoJsonArray) && array_key_exists('coordinates', $geoJsonArray)) {
          $landing_page = $this->getFirstFieldValue($result_item, $raw_fields, 'related_url_landing_page');
          $metadata_identifier = $this->getFirstFieldValue($result_item, $raw_fields, 'metadata_identifier');
          $wms_resources = $this->buildWmsResources($result_item, $raw_fields);
          $solr_id = $this->getFirstFieldValue($result_item, $raw_fields, 'id');
          if ($solr_id === '' && $metadata_identifier !== '') {
            $solr_id = MetsisSolrUtilities::toSolrId($metadata_identifier);
          }
          [$temporal_start, $temporal_end] = $this->buildTemporalExtent($result_item, $raw_fields);
          $is_parent = strtolower($this->getFirstFieldValue($result_item, $raw_fields, 'isParent'));
          $is_parent = in_array($is_parent, ['1', 'true'], TRUE);

          // Add the GeoJSON string as a Feature in the FeatureCollection.
          $features[] = [
            'type' => 'Feature',
            'geometry' => [
              'type' => $geoJsonArray['type'],
              'coordinates' => $geoJsonArray['coordinates'],
            ],
            'properties' => [
              'id' => $result_item->getId(),
              'title' => $this->getFirstFieldValue($result_item, $raw_fields, 'title'),
              'metadata_identifier' => $metadata_identifier,
              'landing_page' => $landing_page,
              'wms_resources' => $wms_resources,
              'has_wms' => !empty($wms_resources),
              'solr_id' => $solr_id,
              'temporal_start' => $temporal_start,
              'temporal_end' => $temporal_end,
              'is_parent' => $is_parent,
            ],
          ];
        }
      }
    }

    // Build the GeoJSON FeatureCollection.
    $feature_collection = [
      'type' => 'FeatureCollection',
      'features' => $features,
    ];
    // Get the view from the query object.
    // Add the geojson object to the views drupalSettings.
    // The map app will load it an render the features.
    if ($view = $results->getQuery()->getOption('search_api_view')) {
      /** @var \Drupal\views\ViewExecutable $view */
      $view->element['#attached']['drupalSettings']['metsis_drupal']['search']['results']['geojson_feature_collection'] = $feature_collection;
      $view->element['#attached']['drupalSettings']['metsis_drupal']['search']['instances'][$view->dom_id]['geojson_feature_collection'] = $feature_collection;
    }
  }

  /**
   * Convert a PHP array (decoded GeoJSON) into a GeoJSON string.
   *
   * @param array $geojson_array
   *   The PHP array representing the GeoJSON structure.
   *
   * @return string
   *   The GeoJSON string.
   */
  protected function arrayToGeoJson(array $geojson_array): string {
    // GeoJSON must be an associative array with "type" and "coordinates".
    if (!isset($geojson_array['type']) && !isset($geojson_array['coordinates'])) {
      throw new \InvalidArgumentException('Invalid GeoJSON array structure.');
    }
    $geojson = [
      'type' => $geojson_array['type'],
      'coordinates' => $geojson_array['coordinates'],
    ];
    return json_encode($geojson);
  }

  /**
   * Build normalized WMS resource descriptors from result fields.
   *
   * @param \Drupal\search_api\Item\ItemInterface $result_item
   *   Search API result item.
   * @param array<string, mixed> $raw_fields
   *   Raw Solr fields from search_api_solr_document extra data.
   *
   * @return array<int, array{url: string, layers: array<int, string>}>
   *   Normalized WMS resources for feature properties.
   */
  protected function buildWmsResources(ItemInterface $result_item, array $raw_fields): array {
    $urls = $this->getFieldValues($result_item, $raw_fields, 'data_access_url_ogc_wms');
    if ($urls === []) {
      return [];
    }

    $layer_values = $this->getFieldValues($result_item, $raw_fields, 'data_access_wms_layers');
    $layers = [];
    foreach ($layer_values as $layer_value) {
      foreach (preg_split('/\s*,\s*/', $layer_value) ?: [] as $layer_name) {
        $trimmed = trim($layer_name);
        if ($trimmed !== '') {
          $layers[] = $trimmed;
        }
      }
    }
    $layers = array_values(array_unique($layers));

    $resources = [];
    foreach ($urls as $url) {
      if ($url === '') {
        continue;
      }
      $resources[] = [
        'url' => $url,
        'layers' => $layers,
      ];
    }

    return $resources;
  }

  /**
   * Return first scalar value for a field from raw Solr doc or result field.
   */
  protected function getFirstFieldValue(ItemInterface $result_item, array $raw_fields, string $field_name): string {
    $values = $this->getFieldValues($result_item, $raw_fields, $field_name);
    return $values[0] ?? '';
  }

  /**
   * Return the earliest start and latest end date of a result.
   *
   * Mirrors the search row: the end is empty when open-ended (fewer end dates
   * than start dates).
   *
   * @return array{0: string, 1: string}
   *   Start and end dates.
   */
  protected function buildTemporalExtent(ItemInterface $result_item, array $raw_fields): array {
    $starts = $this->getFieldValues($result_item, $raw_fields, 'temporal_extent_start_date');
    $ends = $this->getFieldValues($result_item, $raw_fields, 'temporal_extent_end_date');
    if ($starts === []) {
      return ['', ''];
    }
    sort($starts);
    sort($ends);
    $end = ($ends !== [] && count($starts) <= count($ends)) ? (string) end($ends) : '';
    return [$starts[0], $end];
  }

  /**
   * Return normalized scalar field values from raw Solr doc or result field.
   *
   * @return string[]
   *   Scalar field values as strings.
   */
  protected function getFieldValues(ItemInterface $result_item, array $raw_fields, string $field_name): array {
    $candidate_values = [];

    if (array_key_exists($field_name, $raw_fields)) {
      $candidate_values = is_array($raw_fields[$field_name])
        ? $raw_fields[$field_name]
        : [$raw_fields[$field_name]];
    }
    elseif ($field = $result_item->getField($field_name)) {
      $candidate_values = $field->getValues();
    }

    $values = [];
    foreach ($candidate_values as $candidate) {
      if (is_scalar($candidate) && (string) $candidate !== '') {
        $values[] = (string) $candidate;
      }
    }

    return $values;
  }

}
