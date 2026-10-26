<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the map-area migration preserves customized View headers.
 */
#[Group('metsis_drupal')]
final class MetsisMapUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'filter', 'views', 'search_api',
    'search_api_solr', 'leaflet', 'geofield', 'honeypot', 'metsis_drupal',
  ];

  /**
   * Tests extender activation, display inheritance, overrides, and schema.
   */
  public function testSidebarLayout(): void {
    \Drupal::moduleHandler()->loadInclude('metsis_drupal', 'install');
    $settings = $this->config('views.settings');
    $settings->set('display_extenders', ['existing' => 'existing'])->save();
    metsis_drupal_update_11003();
    metsis_drupal_update_11003();
    self::assertSame([
      'existing' => 'existing',
      0 => 'metsis_layout',
    ], $this->config('views.settings')->get('display_extenders'));
    // Do not instantiate the deliberately unknown extender used above.
    $settings->set('display_extenders', ['metsis_layout' => 'metsis_layout'])->save();
    $view = View::create([
      'id' => 'sidebar_test',
      'base_table' => 'search_api_index_metsis',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_plugin' => 'default',
          'display_options' => ['metsis_sidebar_width' => '30em'],
        ],
        'page' => [
          'id' => 'page',
          'display_plugin' => 'page',
          'display_options' => [],
        ],
        'custom' => [
          'id' => 'custom',
          'display_plugin' => 'page',
          'display_options' => [
            'defaults' => ['metsis_sidebar_width' => FALSE],
            'metsis_sidebar_width' => '320px',
          ],
        ],
      ],
    ])->getExecutable();
    $view->setDisplay('page');
    self::assertSame('30em', $view->getDisplay()->getOption('metsis_sidebar_width'));
    self::assertSame(['metsis_sidebar_width'], $view->getDisplay()->defaultableSections('metsis_layout'));
    $view->setDisplay('custom');
    self::assertSame('320px', $view->getDisplay()->getOption('metsis_sidebar_width'));
    $definition = $this->container->get('config.typed')->getDefinition('views_display');
    self::assertSame('string', $definition['mapping']['metsis_sidebar_width']['type']);
  }

  /**
   * Tests migration defaults, inherited headers, and repeated execution.
   */
  public function testMapUpdate(): void {
    \Drupal::moduleHandler()->loadInclude('metsis_drupal', 'install');
    $custom_area = ['id' => 'custom', 'table' => 'views', 'field' => 'area', 'plugin_id' => 'text'];
    $existing_map = [
      'id' => 'existing',
      'table' => 'views',
      'field' => 'metsis_map',
      'plugin_id' => 'metsis_map',
      'map_height' => 750,
    ];
    $view = View::create([
      'id' => 'metsis_search',
      'label' => 'Search',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_plugin' => 'default',
          'display_options' => ['header' => ['custom' => $custom_area]],
        ],
        'page' => ['id' => 'page', 'display_plugin' => 'page', 'display_options' => []],
        'custom' => [
          'id' => 'custom',
          'display_plugin' => 'page',
          'display_options' => [
            'defaults' => ['header' => FALSE],
            'header' => ['existing' => $existing_map],
          ],
        ],
      ],
    ]);
    $view->save();
    metsis_drupal_update_11002();
    $storage = \Drupal::entityTypeManager()->getStorage('view');
    $storage->resetCache();
    $displays = $storage->load('metsis_search')->get('display');
    self::assertSame(400, $displays['default']['display_options']['header']['metsis_map']['map_height']);
    self::assertSame('text', $displays['default']['display_options']['header']['custom']['plugin_id']);
    self::assertArrayNotHasKey('header', $displays['page']['display_options']);
    self::assertCount(1, $displays['custom']['display_options']['header']);
    self::assertSame(750, $displays['custom']['display_options']['header']['existing']['map_height']);
    metsis_drupal_update_11002();
    $storage->resetCache();
    self::assertSame($displays, $storage->load('metsis_search')->get('display'));
  }

}
