<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\Core\Config\FileStorage;
use Drupal\search_api\Entity\Server;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Verifies real map canvases, dimensions, and independent block instances.
 */
#[Group('metsis_drupal')]
#[RunTestsInSeparateProcesses]
final class MetsisMapsTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['editor', 'metsis_drupal', 'metsis_map_test', 'block'];

  /**
   * The default View has pre-existing BEF schema gaps.
   *
   * @var string[]
   */
  protected static $configSchemaCheckerExclusions = ['views.view.metsis_search'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Installs the English field types required by the default METSIS server.
   */
  protected function installModulesFromClassProperty(ContainerInterface $container): void {
    $container->get('module_installer')->install(['search_api_solr']);
    $container = \Drupal::getContainer();
    $storage = new FileStorage($container->get('extension.list.module')->getPath('search_api_solr') . '/config/optional');
    foreach (['text_en_7_0_0', 'text_phonetic_en_7_0_0'] as $id) {
      $name = 'search_api_solr.solr_field_type.' . $id;
      $container->get('config.factory')->getEditable($name)->setData($storage->read($name))->save();
    }
    parent::installModulesFromClassProperty($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Browser tests use built assets, never a developer's Vite server.
    $this->writeSettings(['settings' => ['vite' => ['useDevServer' => (object) ['value' => FALSE, 'required' => TRUE]]]]);
    $server = Server::load('metsis_solr');
    $server->set('backend', 'metsis_map_test');
    $server->set('backend_config', []);
    $server->save();
    $view = View::load('metsis_search');
    $displays = $view->get('display');
    $options = &$displays['default']['display_options'];
    // Keep the actual bbox plugin but remove unrelated result-backed facets.
    foreach ($options['filters'] as $id => $filter) {
      if (($filter['plugin_id'] ?? '') !== 'metsis_filter_bbox') {
        unset($options['filters'][$id]);
      }
      else {
        $options['filters'][$id]['expose']['map_height'] = 250;
      }
    }
    $options['header'] = [
      'metsis_map' => [
        'id' => 'metsis_map',
        'table' => 'views',
        'field' => 'metsis_map',
        'plugin_id' => 'metsis_map',
        'empty' => TRUE,
        'map_app_settings' => '{}',
        'map_height' => 400,
        'show_all_wms' => FALSE,
      ],
    ];
    $options['use_ajax'] = TRUE;
    $options['metsis_sidebar_width'] = '24rem';
    $view->set('display', $displays)->save();
    $this->drupalPlaceBlock('system_main_block');
  }

  /**
   * Tests initial rendering, tab resizing, AJAX, and the exposed-form block.
   */
  public function testMaps(): void {
    $this->drupalGet('metsis/search');
    $this->assertMap('.metsis-search-map .map-container', 400);
    $this->assertMap('.bbox-map-filter-container', 250);
    $width = $this->getSession()->evaluateScript('document.querySelector(".metsis-filters").getBoundingClientRect().width');
    $rem = $this->getSession()->evaluateScript('parseFloat(getComputedStyle(document.documentElement).fontSize)');
    self::assertEqualsWithDelta(24 * $rem, $width, 1);

    $this->getSession()->getPage()->find('css', '.bbox-form-tabs [aria-controls="panel-coords"]')->click();
    $this->getSession()->getPage()->find('css', '.bbox-form-tabs [aria-controls="panel-map"]')->click();
    $this->assertMap('.bbox-map-filter-container', 250);
    $this->getSession()->getPage()->find('css', '.metsis-filters input[type="submit"]')->click();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertMap('.bbox-map-filter-container', 250);
    $this->assertMap('.metsis-search-map .map-container', 400);

    $this->drupalPlaceBlock('metsis_search_exposed_form', [
      'id' => 'map_filter_block',
      'region' => 'sidebar_first',
      'shown_filters' => ['bbox'],
    ]);
    $this->drupalGet('metsis/search');
    self::assertCount(2, $this->getSession()->getPage()->findAll('css', '.bbox-map-filter-container'));
    $this->assertMap('#block-map-filter-block .bbox-map-filter-container', 250);
    $this->assertMap('.metsis-filters .bbox-map-filter-container', 250);
  }

  /**
   * Checks the rendered canvas, not merely a map-shaped empty container.
   */
  private function assertMap(string $selector, int $height): void {
    $element = $this->assertSession()->waitForElementVisible('css', $selector);
    self::assertNotNull($element);
    $selector_json = json_encode($selector, JSON_THROW_ON_ERROR);
    $this->getSession()->executeScript("document.querySelector($selector_json).scrollIntoView(); document.querySelector($selector_json).dispatchEvent(new Event('pointerenter'));");
    $script = "const e = document.querySelector($selector_json); const c = e?.querySelector('canvas'); return !!c && c.width > 100 && c.height >= $height - 1 && Math.abs(e.getBoundingClientRect().height - $height) < 2;";
    $ready = $this->getSession()->wait(15000, "(function() { $script })()");
    $dimensions = $this->getSession()->evaluateScript("(function() { const e = document.querySelector($selector_json); return JSON.stringify({width: e?.getBoundingClientRect().width, height: e?.getBoundingClientRect().height, canvases: Array.from(e?.querySelectorAll('canvas') ?? [], c => ({width: c.width, height: c.height}))}); })()");
    self::assertTrue($ready, "Rendered map dimensions: $selector; expected height: $height; actual: $dimensions");
  }

}
