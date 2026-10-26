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
    // Layout scenarios submit immediately; timing is covered separately below.
    $this->config('honeypot.settings')->set('time_limit', 0)->save();
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
   * Tests timed search protection survives AJAX and block presentation.
   */
  public function testHoneypotProtection(): void {
    $this->config('honeypot.settings')->set('time_limit', 5)->save();
    $this->drupalPlaceBlock('metsis_search_exposed_form', [
      'id' => 'protected_search',
      'region' => 'sidebar_first',
      'shown_filters' => ['search_api_fulltext'],
    ]);
    $this->drupalGet('metsis/search');
    $this->assertSession()->elementsCount('css', 'input[name="honeypot_time"]', 2);
    $this->assertSession()->elementsCount('css', 'input[name="url"]', 2);
    $this->assertSession()->pageTextNotContains('There was a problem with your form submission.');
    $store = $this->container->get('keyvalue.expirable')->get('honeypot_time_restriction');
    foreach ($this->getSession()->getPage()->findAll('css', 'input[name="honeypot_time"]') as $timer) {
      $store->set($timer->getValue(), time() - 3600);
    }
    $this->getSession()->getPage()->find('css', '.metsis-filters input[type="submit"]')->click();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->pageTextNotContains('There was a problem with your form submission.');
    $this->assertSession()->elementExists('css', '.metsis-filters input[name="honeypot_time"]');
    $this->assertSession()->elementExists('css', '.metsis-filters input[name="url"]');

    $this->getSession()->executeScript('document.querySelector(".metsis-filters input[name=url]").value = "spam";');
    $this->getSession()->getPage()->find('css', '.metsis-filters input[type="submit"]')->click();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->pageTextContains('There was a problem with your form submission.');
  }

  /**
   * Tests temporal predicates above date fields with configurable identifiers.
   */
  public function testTemporalLayouts(): void {
    $this->container->get('theme_installer')->install(['solo', 'olivero']);
    foreach (['solo', 'olivero', 'stark'] as $theme) {
      $this->config('system.theme')->set('default', $theme)->save();
      foreach ([FALSE, TRUE] as $predicate) {
        $view = View::load('metsis_search');
        $displays = $view->get('display');
        foreach ($displays['default']['display_options']['filters'] as &$filter) {
          if ($filter['plugin_id'] === 'metsis_filter_date_range') {
            $filter['expose']['identifier'] = 'custom_dates';
            $filter['expose']['operator_id'] = 'custom_date_predicate';
            $filter['expose']['use_operator'] = $predicate;
          }
        }
        unset($filter);
        $view->set('display', $displays)->save();
        $this->drupalGet('metsis/search', [
          'query' => [
            'custom_dates' => ['min' => '2020-01-01', 'max' => '2021-12-31'],
            'custom_date_predicate' => 'within',
          ],
        ]);
        $this->assertSession()->elementsCount('css', '.metsis-date-range-fieldset select', $predicate ? 1 : 0);
        $this->assertSession()->fieldValueEquals('custom_dates[min]', '2020-01-01');
        $this->assertSession()->fieldValueEquals('custom_dates[max]', '2021-12-31');
        if ($predicate) {
          $this->assertSession()->fieldValueEquals('custom_date_predicate', 'within');
        }
        foreach ([1440, 390, 320] as $width) {
          $this->getSession()->resizeWindow($width, 1000);
          $this->assertTemporalLayout("$theme/predicate=$predicate/$width");
        }
        $this->getSession()->getPage()->find('css', '.metsis-filters input[type="submit"]')->click();
        $this->assertSession()->assertWaitOnAjaxRequest();
        $this->assertTemporalLayout("$theme/after AJAX");
      }
    }
  }

  /**
   * Verifies temporal controls do not overlap and fill their available row.
   */
  private function assertTemporalLayout(string $context): void {
    $layout = $this->getSession()->evaluateScript(<<<'JS'
      (function () {
        const f = document.querySelector(".metsis-date-range-fieldset");
        const b = f.getBoundingClientRect();
        const fields = Array.from(f.querySelectorAll("input[type=date]"));
        const select = f.querySelector("select");
        const dates = f.querySelector(".metsis-date-range-fields").getBoundingClientRect();
        return {
          contained: fields.concat(select ? [select] : []).every(e => {
            const r = e.getBoundingClientRect();
            return r.width > 0 && r.left >= b.left && r.right <= b.right + 1 && r.bottom <= b.bottom + 1;
          }),
          above: !select || select.getBoundingClientRect().bottom <= Math.min(...fields.map(e => e.getBoundingClientRect().top)),
          fullWidth: !select || Math.abs(select.getBoundingClientRect().width - dates.width) < 2
        };
      })()
      JS);
    self::assertTrue($layout['contained'], "$context: temporal controls must remain inside the fieldset.");
    self::assertTrue($layout['above'], "$context: predicate must be above both dates.");
    self::assertTrue($layout['fullWidth'], "$context: predicate must fill the date row width.");
  }

  /**
   * Tests every bbox input mode with predicates, themes, and narrow layouts.
   */
  public function testBboxLayouts(): void {
    $this->container->get('theme_installer')->install(['solo', 'olivero']);
    foreach (['solo', 'olivero', 'stark'] as $theme) {
      $this->config('system.theme')->set('default', $theme)->save();
      foreach ([
        'map' => [TRUE, FALSE, FALSE],
        'coordinates' => [FALSE, FALSE, FALSE],
        'combined' => [TRUE, TRUE, FALSE],
        'tabs' => [TRUE, TRUE, TRUE],
      ] as $mode => [$map, $coords, $tabs]) {
        foreach ([FALSE, TRUE] as $predicate) {
          $view = View::load('metsis_search');
          $displays = $view->get('display');
          foreach ($displays['default']['display_options']['filters'] as &$filter) {
            if ($filter['plugin_id'] === 'metsis_filter_bbox') {
              $filter['expose']['map_input'] = $map;
              $filter['expose']['user_input'] = $coords;
              $filter['expose']['tabs_component'] = $tabs;
              $filter['expose']['use_operator'] = $predicate;
            }
          }
          unset($filter);
          $view->set('display', $displays)->save();
          $this->drupalGet('metsis/search');
          $this->assertSession()->elementsCount('css', '.metsis-bbox-fieldset', 1);
          $this->assertSession()->elementsCount('css', '.metsis-bbox-fieldset select', $predicate ? 1 : 0);
          $this->assertSession()->elementsCount('css', '.metsis-bbox-fieldset [data-metsis-bbox-coordinate]', 4);
          $this->assertSession()->elementsCount('css', '.metsis-bbox-fieldset input[type="hidden"]', $map && !$coords ? 4 : 0);
          $this->assertSession()->elementsCount('css', '.metsis-bbox-fieldset .bbox-form-tabs', $tabs ? 1 : 0);
          foreach ([1440, 390, 320] as $width) {
            $this->getSession()->resizeWindow($width, 1000);
            if ($map) {
              $this->assertMap('.bbox-map-filter-container', 250);
            }
            $this->assertBboxLayout("$theme/$mode/predicate=$predicate/$width");
            if ($tabs) {
              $this->getSession()->getPage()->find('css', '.bbox-form-tabs [aria-controls="panel-coords"]')->click();
              $this->assertSession()->elementsCount('css', '.bbox-form-tabs .tab-panel.is-active input[type="text"]', 4);
              $this->assertBboxLayout("$theme/coordinates tab/$width");
              $this->getSession()->getPage()->find('css', '.bbox-form-tabs [aria-controls="panel-map"]')->click();
              $this->assertMap('.bbox-map-filter-container', 250);
            }
          }
        }
      }
    }
  }

  /**
   * Checks actual child bounds and map stability after pointer movement.
   */
  private function assertBboxLayout(string $context): void {
    $layout = $this->getSession()->evaluateScript(<<<'JS'
      (function () {
        const fieldset = document.querySelector(".metsis-bbox-fieldset");
        const bounds = fieldset.getBoundingClientRect();
        const next = Array.from(fieldset.parentElement.querySelectorAll("fieldset")).find(e => e !== fieldset);
        const controls = Array.from(fieldset.querySelectorAll("select, input[type=text], .bbox-map-filter-container")).filter(e => e.getClientRects().length);
        return {
          contained: controls.every(e => {
            const r = e.getBoundingClientRect();
            return r.width > 0 && r.left >= bounds.left && r.right <= bounds.right + 1 && r.bottom <= bounds.bottom + 1;
          }),
          fullWidth: Math.abs(bounds.width - fieldset.parentElement.getBoundingClientRect().width) < 2,
          separated: !next || next.getBoundingClientRect().top >= bounds.bottom,
          width: bounds.width,
          mapWidth: fieldset.querySelector(".bbox-map-filter-container")?.getBoundingClientRect().width ?? 0
        };
      })()
      JS);
    self::assertTrue($layout['contained'], "$context: controls must stay inside the bbox fieldset.");
    self::assertTrue($layout['fullWidth'], "$context: bbox fieldset must fill the filter column.");
    self::assertTrue($layout['separated'], "$context: bbox must not overlap the next filter.");
    $map = $this->getSession()->getPage()->find('css', '.bbox-map-filter-container');
    if (!$map || !$map->isVisible()) {
      return;
    }
    foreach ([30, 80, 120] as $offset) {
      $this->getSession()->executeScript(<<<JS
        (function () {
          const map = document.querySelector(".bbox-map-filter-container");
          const r = map.getBoundingClientRect();
          map.querySelector(".ol-viewport").dispatchEvent(new PointerEvent("pointermove", {
            bubbles: true, clientX: r.left + $offset, clientY: r.top + 100, pointerType: "mouse"
          }));
        })();
        JS);
      $stable = $this->getSession()->evaluateScript('Math.abs(document.querySelector(".metsis-bbox-fieldset").getBoundingClientRect().width - ' . $layout['width'] . ') < 1 && Math.abs(document.querySelector(".bbox-map-filter-container").getBoundingClientRect().width - ' . $layout['mapWidth'] . ') < 1');
      self::assertTrue($stable, "$context: pointer coordinates must not resize the map or fieldset.");
    }
    $this->assertSession()->elementTextContains('css', '.bbox-map-coordinates', 'lon:');
  }

  /**
   * Tests summary removal for both Solr fields and renamed exposed inputs.
   */
  public function testBboxSummaryRemoval(): void {
    foreach (['bbox', 'geospatial_bounds3d'] as $field) {
      foreach ([[FALSE, TRUE], [TRUE, TRUE], [FALSE, FALSE], [TRUE, FALSE]] as [$user_input, $use_ajax]) {
        $view = View::load('metsis_search');
        $displays = $view->get('display');
        $options = &$displays['default']['display_options'];
        $options['use_ajax'] = $use_ajax;
        foreach ($options['filters'] as $id => $filter) {
          if ($filter['plugin_id'] === 'metsis_filter_bbox') {
            unset($options['filters'][$id]);
            $filter['id'] = $field;
            $filter['field'] = $field;
            $filter['expose']['identifier'] = 'custom_bounds';
            $filter['expose']['operator_id'] = 'custom_predicate';
            $filter['expose']['use_operator'] = TRUE;
            $filter['expose']['map_input'] = TRUE;
            $filter['expose']['user_input'] = $user_input;
            $options['filters'][$field] = $filter;
            break;
          }
        }
        $view->set('display', $displays)->save();
        $this->drupalGet('metsis/search', [
          'query' => [
            'text' => 'retained search',
            'custom_bounds' => ['minX' => '0', 'maxX' => '20', 'minY' => '0', 'maxY' => '70'],
            'custom_predicate' => 'within',
          ],
        ]);
        $selector = '.views-filters-summary a[data-remove-selector="custom_bounds:bbox"]';
        $this->assertSession()->elementsCount('css', $selector, 1);
        $this->assertSession()->elementTextContains('css', '.views-filters-summary', 'Within(0, 20, 0, 70)');
        $this->assertSession()->elementExists('css', $selector)->click();
        if ($use_ajax) {
          $this->assertSession()->assertWaitOnAjaxRequest();
        }
        $this->assertSession()->elementNotExists('css', $selector);
        foreach (['minX', 'maxX', 'minY', 'maxY'] as $coordinate) {
          $input = $this->assertSession()->elementExists('css', 'input[name="custom_bounds[' . $coordinate . ']"]');
          self::assertSame('', $input->getValue());
        }
        $this->assertSession()->fieldValueEquals('text', 'retained search');
        $this->assertSession()->fieldValueEquals('custom_predicate', 'within');
      }
    }
  }

  /**
   * Tests initial rendering, tab resizing, AJAX, and the exposed-form block.
   */
  public function testMaps(): void {
    $view = View::load('metsis_search');
    $displays = $view->get('display');
    $bbox_id = '';
    foreach ($displays['default']['display_options']['filters'] as $id => &$filter) {
      if ($filter['plugin_id'] === 'metsis_filter_bbox') {
        $bbox_id = $id;
        $filter['expose']['map_input'] = TRUE;
        $filter['expose']['user_input'] = TRUE;
        $filter['expose']['tabs_component'] = TRUE;
      }
    }
    unset($filter);
    $view->set('display', $displays)->save();
    $this->drupalGet('metsis/search');
    $this->assertMap('.metsis-search-map .map-container', 400);
    $this->assertMap('.bbox-map-filter-container', 250);

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
      'shown_filters' => [$bbox_id],
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
  }

}
