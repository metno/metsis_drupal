<?php

declare(strict_types=1);

namespace Drupal\metsis_map_test;

use Drupal\search_api\IndexInterface;
use Drupal\search_api_solr\SolrFieldManager;

/**
 * Uses configured index fields without discovering fields from a Solr server.
 */
final class MapTestSolrFieldManager extends SolrFieldManager {

  /**
   * {@inheritdoc}
   */
  protected function buildFieldDefinitionsFromSolr(IndexInterface $index) {
    return [];
  }

}
