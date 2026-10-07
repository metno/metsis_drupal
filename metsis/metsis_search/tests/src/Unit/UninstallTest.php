<?php

namespace Drupal\Tests\metsis_search\Unit;

use Drupal\block\BlockInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\facets\FacetSourceInterface;
use Drupal\search_api\IndexInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests search uninstall selection and deletion order.
 *
 * @group metsis_search
 */
class UninstallTest extends UnitTestCase {

  /**
   * Tests cleanup of active sources, including customized source names.
   */
  public function testActiveSource(): void {
    $this->assertCleanup('search_api:views_page__customized__results');
  }

  /**
   * Tests cleanup when the source entity has already been deleted.
   */
  public function testMissingSource(): void {
    $this->assertCleanup(NULL);
  }

  /**
   * Tests that config import controls its own entity deletions.
   */
  public function testConfigSync(): void {
    require_once dirname(__DIR__, 3) . '/metsis_search.install';
    $container = new ContainerBuilder();
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->expects($this->once())->method('invalidateAll');
    $container->set('cache.render', $cache);
    \Drupal::setContainer($container);

    metsis_search_uninstall(TRUE);
  }

  /**
   * Runs uninstall with module-owned and unrelated entity fixtures.
   *
   * @param string|null $active_source_name
   *   The active source plugin ID, or NULL for an already deleted source.
   */
  protected function assertCleanup(?string $active_source_name): void {
    require_once dirname(__DIR__, 3) . '/metsis_search.install';
    $prefixes = [
      'block' => 'block.block',
      'facets_facet' => 'facets.facet',
      'facets_facet_source' => 'facets.facet_source',
      'view' => 'views.view',
      'search_api_index' => 'search_api.index',
      'search_api_server' => 'search_api.server',
      'search_api_sorts_field' => 'search_api_sorts.search_api_sorts_field',
      'search_api_autocomplete_search' => 'search_api_autocomplete.search',
      'date_format' => 'core.date_format',
    ];
    $source_name = $active_source_name ?? 'search_api:views_page__metsis_search__results';
    $fixtures = [
      'facets_facet' => [
        'user_created' => ['facet_source_id' => $source_name],
        'unrelated' => ['facet_source_id' => 'search_api:views_page__other__results'],
      ],
      'view' => [
        'metsis_search' => [],
        'other' => [],
      ],
      'block' => [
        'custom_theme_facet' => ['plugin' => 'facet_block:user_created'],
        'admin_theme_disabled_facet' => ['plugin' => 'facet_block:user_created'],
        'missing_shipped_facet' => ['plugin' => 'facet_block:collection'],
        'custom_exposed' => ['plugin' => 'views_exposed_filter_block:metsis_search-results'],
        'admin_exposed' => ['plugin' => 'views_exposed_filter_block:metsis_search-removed_display'],
        'view_block' => ['plugin' => 'views_block:metsis_search-custom_display'],
        'unrelated_facet' => ['plugin' => 'facet_block:unrelated'],
        'unrelated_exposed' => ['plugin' => 'views_exposed_filter_block:other-results'],
        'similar_view_name' => ['plugin' => 'views_exposed_filter_block:metsis_search_extra-results'],
      ],
      'search_api_sorts_field' => [
        'views_page---metsis_search__results_timestamp' => ['display_id' => 'views_page---metsis_search__results'],
        'custom_sort' => ['display_id' => 'views_page---metsis_search__new_display'],
        'unrelated_sort' => ['display_id' => 'views_page---other__results'],
      ],
      'search_api_autocomplete_search' => [
        'metsis_search' => ['index_id' => 'metsis'],
        'custom_autocomplete' => ['index_id' => 'metsis'],
        'unrelated_autocomplete' => ['index_id' => 'other'],
      ],
      'search_api_index' => [
        'metsis' => [],
      ],
      'search_api_server' => [
        'local_metsis' => [],
      ],
      'date_format' => [
        'solr_format' => [],
      ],
    ];
    if ($active_source_name !== NULL) {
      $fixtures['facets_facet_source']['search_api__views_page__metsis_search__results'] = ['name' => $active_source_name];
    }

    $entities = [];
    $deleted = [];
    $read_only = FALSE;
    foreach ($fixtures as $type => $items) {
      foreach ($items as $id => $properties) {
        $interface = match ($type) {
          'block' => BlockInterface::class,
          'facets_facet_source' => FacetSourceInterface::class,
          'search_api_index' => IndexInterface::class,
          default => ConfigEntityInterface::class,
        };
        $entity = $this->createMock($interface);
        $entity->method('id')->willReturn($id);
        $entity->method('get')->willReturnCallback(static fn($key) => $properties[$key] ?? NULL);
        $entity->method('delete')->willReturnCallback(function () use ($type, $id, &$entities, &$deleted, &$read_only): void {
          if ($type === 'search_api_index') {
            $this->assertTrue($read_only, 'Remote documents must be protected before index deletion.');
          }
          unset($entities[$type][$id]);
          $deleted[] = "$type:$id";
        });
        if ($type === 'block') {
          $entity->method('getPluginId')->willReturn($properties['plugin']);
        }
        if ($type === 'facets_facet_source') {
          $entity->method('getName')->willReturn($properties['name']);
        }
        if ($type === 'search_api_index') {
          $entity->expects($this->once())->method('set')->with('read_only', TRUE)
            ->willReturnCallback(static function () use (&$read_only, $entity) {
              $read_only = TRUE;
              return $entity;
            });
        }
        $entities[$type][$id] = $entity;
      }
    }

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('hasDefinition')->willReturnCallback(static fn($type) => isset($prefixes[$type]));
    $storages = [];
    $definitions = [];
    foreach ($prefixes as $type => $prefix) {
      $definition = $this->createMock(ConfigEntityTypeInterface::class);
      $definition->method('getConfigPrefix')->willReturn($prefix);
      $definitions[$type] = $definition;
      $storage = $this->createMock(EntityStorageInterface::class);
      $storage->method('loadMultiple')->willReturnCallback(static function (?array $ids = NULL) use (&$entities, $type): array {
        $items = $entities[$type] ?? [];
        return $ids === NULL ? $items : array_intersect_key($items, array_flip($ids));
      });
      $storage->method('loadByProperties')->willReturnCallback(static function (array $properties) use (&$entities, $type): array {
        return array_filter($entities[$type] ?? [], static function ($entity) use ($properties): bool {
          foreach ($properties as $key => $values) {
            if (!in_array($entity->get($key), (array) $values, TRUE)) {
              return FALSE;
            }
          }
          return TRUE;
        });
      });
      $storages[$type] = $storage;
    }
    $manager->method('getDefinition')->willReturnCallback(static fn($type) => $definitions[$type]);
    $manager->method('getStorage')->willReturnCallback(static fn($type) => $storages[$type]);

    $config_manager = $this->createMock(ConfigManagerInterface::class);
    $config_manager->method('getEntityTypeIdByName')->willReturnCallback(static function ($name) use ($prefixes): ?string {
      foreach ($prefixes as $type => $prefix) {
        if (str_starts_with($name, $prefix . '.')) {
          return $type;
        }
      }
      return NULL;
    });
    $module_list = $this->createMock(ModuleExtensionList::class);
    $module_list->method('getPath')->with('metsis_search')->willReturn(dirname(__DIR__, 3));
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->expects($this->exactly(2))->method('invalidateAll');
    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $manager);
    $container->set('config.manager', $config_manager);
    $container->set('extension.list.module', $module_list);
    $container->set('cache.render', $cache);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->with('metsis_search')->willReturn($this->createMock(LoggerInterface::class));
    $container->set('logger.factory', $logger_factory);
    \Drupal::setContainer($container);

    metsis_search_uninstall();
    $expected = [
      'block:custom_theme_facet',
      'block:admin_theme_disabled_facet',
      'block:missing_shipped_facet',
      'block:custom_exposed',
      'block:admin_exposed',
      'block:view_block',
      'search_api_sorts_field:views_page---metsis_search__results_timestamp',
      'search_api_sorts_field:custom_sort',
      'search_api_autocomplete_search:metsis_search',
      'search_api_autocomplete_search:custom_autocomplete',
      'facets_facet:user_created',
    ];
    if ($active_source_name !== NULL) {
      $expected[] = 'facets_facet_source:search_api__views_page__metsis_search__results';
    }
    array_push($expected, 'view:metsis_search', 'search_api_index:metsis', 'search_api_server:local_metsis', 'date_format:solr_format');
    $this->assertSame($expected, $deleted);
    $this->assertCount(3, $entities['block']);
    $this->assertArrayHasKey('unrelated', $entities['facets_facet']);

    // Running cleanup again with already missing entities must be harmless.
    metsis_search_uninstall();
    $this->assertSame($expected, $deleted);
  }

}
