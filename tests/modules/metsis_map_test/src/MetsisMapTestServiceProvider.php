<?php

declare(strict_types=1);

namespace Drupal\metsis_map_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Isolates Solr field discovery for map browser tests.
 */
final class MetsisMapTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $container->getDefinition('solr_field.manager')->setClass(MapTestSolrFieldManager::class);
  }

}
