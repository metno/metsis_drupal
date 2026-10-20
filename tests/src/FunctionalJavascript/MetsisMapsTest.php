<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\Core\Config\FileStorage;
use Drupal\search_api\Entity\Index;
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
    // Exercise field discovery without relying on a previously populated cache.
    $this->container->get('cache.discovery')->delete('solr_field_definitions:metsis');
    $definitions = Index::load('metsis')->getDatasource('solr_document')->getPropertyDefinitions();
    self::assertArrayHasKey('bbox', $definitions);
    self::assertSame('solr_bbox', $definitions['bbox']->getDataType());
    $view = View::load('metsis_search');
    $displays = $view->get('display');
    $options = &$displays['default']['display_options'];
    // Keep search/spatial/temporal controls, not result-backed facets.
    $filter_plugins = ['search_api_fulltext', 'metsis_filter_bbox', 'metsis_filter_date_range'];
    foreach ($options['filters'] as $id => $filter) {
      if (!in_array($filter['plugin_id'] ?? '', $filter_plugins, TRUE)) {
        unset($options['filters'][$id]);
      }
      elseif ($filter['plugin_id'] === 'metsis_filter_bbox') {
        $options['filters'][$id]['expose']['map_height'] = 250;
      }
    }
    $options['header'] = [
      'views_filters_summary' => $options['header']['views_filters_summary'],
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
   * Tests actual result markup in narrow theme containers and mobile viewports.
   */
  public function testResponsiveRows(): void {
    $this->container->get('state')->set('metsis_map_test.responsive_rows', TRUE);
    $this->container->get('theme_installer')->install(['olivero', 'claro']);
    foreach (['stark', 'olivero', 'claro'] as $theme) {
      $this->config('system.theme')->set('default', $theme)->save();
      $this->getSession()->resizeWindow(1440, 1000);
      $this->drupalGet('metsis/search', ['query' => ['text' => str_repeat('UnbrokenSearch', 5)]]);
      $this->assertSession()->elementExists('css', 'section.metsis-search-main-content[aria-label="Search results"]');
      $this->assertSession()->elementsCount('css', '.metsis-search-row', 2);
      $this->assertSession()->elementTextContains('css', '.views-filters-summary', str_repeat('UnbrokenSearch', 5));
      $this->assertMap('.metsis-search-map .map-container', 400);
      foreach ([1440, 1024, 820, 769, 768, 390, 320] as $width) {
        $this->getSession()->resizeWindow($width, 1000);
        // Hidden tooltips must not contribute stale desktop coordinates.
        $this->getSession()->executeScript('document.querySelectorAll(".metsis-map-tooltip").forEach(e => e.style.left = "2000px");');
        $fits = $this->getSession()->wait(5000, <<<'JS'
          (function () {
            const results = document.querySelector(".metsis-search-main-content");
            const search = document.querySelector(".metsis-search-text__input").getBoundingClientRect();
            const button = document.querySelector(".metsis-search-box__button").getBoundingClientRect();
            return document.documentElement.scrollWidth <= innerWidth + 1
              && results.scrollWidth <= results.clientWidth + 1
              && button.top >= search.top && button.bottom <= search.bottom
              && Array.from(document.querySelectorAll(".metsis-abstract-wrapper")).every(e => e.getBoundingClientRect().width > 100)
              && Array.from(document.querySelectorAll(".metsis-filters input[type=date]")).every(e => {
                const fieldset = e.closest("fieldset").getBoundingClientRect();
                const input = e.getBoundingClientRect();
                return input.width > 0 && input.left >= fieldset.left && input.right <= fieldset.right;
              });
          })()
          JS);
        self::assertTrue($fits, "$theme at $width px must fit the viewport and retain usable abstracts.");
        if ($width <= 820) {
          $stacked = $this->getSession()->evaluateScript('document.querySelector(".metsis-search-main-content").getBoundingClientRect().top >= document.querySelector(".metsis-filters").getBoundingClientRect().bottom');
          self::assertTrue($stacked, "$theme at $width px must stack results below filters.");
        }
      }
      $this->getSession()->getPage()->find('css', '.metsis-abstract-toggle')->click();
      $this->assertSession()->elementAttributeContains('css', '.metsis-abstract-toggle', 'aria-expanded', 'true');
      $this->getSession()->getPage()->find('css', '.metsis-abstract-toggle')->click();
      $this->assertSession()->elementAttributeContains('css', '.metsis-abstract-toggle', 'aria-expanded', 'false');
      $this->assertMap('.bbox-map-filter-container', 250);
    }
  }

  /**
   * Checks the rendered canvas, not merely a map-shaped empty container.
   */
  private function assertMap(string $selector, int $height): void {
    $element = $this->assertSession()->waitForElementVisible('css', $selector);
    self::assertNotNull($element, "Map viewport not visible: $selector. Page text: " . $this->getSession()->getPage()->getText());
    $selector_json = json_encode($selector, JSON_THROW_ON_ERROR);
    $this->getSession()->executeScript("document.querySelector($selector_json).scrollIntoView(); document.querySelector($selector_json).dispatchEvent(new Event('pointerenter'));");
    $script = "const e = document.querySelector($selector_json); const c = e?.querySelector('canvas'); return !!c && c.width > 100 && c.height >= $height - 1 && Math.abs(e.getBoundingClientRect().height - $height) < 2;";
    $ready = $this->getSession()->wait(15000, "(function() { $script })()");
    $dimensions = $this->getSession()->evaluateScript("(function() { const e = document.querySelector($selector_json); return JSON.stringify({width: e?.getBoundingClientRect().width, height: e?.getBoundingClientRect().height, canvases: Array.from(e?.querySelectorAll('canvas') ?? [], c => ({width: c.width, height: c.height}))}); })()");
    self::assertTrue($ready, "Rendered map dimensions: $selector; expected height: $height; actual: $dimensions");
  }

}
