<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\metsis_drupal\Form\MetsisSettingsForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Kernel tests for the METSIS settings form.
 */
#[CoversClass(MetsisSettingsForm::class)]
#[Group('metsis_drupal')]
final class MetsisSettingsFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'language',
    'views',
    'search_api',
    'search_api_solr',
    'search_api_solr_autocomplete',
    'facets',
    'facets_exposed_filters',
    'better_exposed_filters',
    'leaflet',
    'views_filters_summary',
    'views_ajax_history',
    'geofield',
    'honeypot',
    'metsis_drupal',
  ];

  /**
   * Form under test.
   *
   * @var \Drupal\metsis_drupal\Form\MetsisSettingsForm
   */
  protected MetsisSettingsForm $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('config.factory')->getEditable('metsis_drupal.settings')
      ->set('vocab_source_path', 'vendor/metno/mmd/thesauri/mmd-vocabulary.ttl')
      ->save();
    $this->form = MetsisSettingsForm::create($this->container);
  }

  /**
   * Tests the settings form build contains the expected sections.
   */
  #[Test]
  public function testBuildFormContainsExpectedSections(): void {
    $form_state = new FormState();
    $form = $this->form->buildForm([], $form_state);

    $this->assertArrayHasKey('metsis_vertical_tabs', $form);
    $this->assertSame('vertical_tabs', $form['metsis_vertical_tabs']['#type']);
    $this->assertArrayHasKey('solr_index', $form);
    $this->assertArrayHasKey('search_config', $form);
    $this->assertArrayHasKey('metadata_export', $form);
    $this->assertArrayHasKey('map_app', $form);
    $this->assertArrayHasKey('metsis_services', $form);
    $this->assertArrayHasKey('bokeh_plot_service_url', $form['metsis_services']);
    $this->assertSame('textfield', $form['metsis_services']['bokeh_plot_service_url']['#type']);
  }

  /**
   * Tests the override state disables the Bokeh URL field.
   */
  #[Test]
  public function testBokehPlotServiceUrlOverrideDisablesField(): void {
    $this->container->get('config.factory')->getEditable('metsis_drupal.settings')
      ->set('bokeh_plot_service_url', 'https://example.com/should-not-be-saved')
      ->save();

    $GLOBALS['config']['metsis_drupal.settings']['bokeh_plot_service_url'] = 'https://overridden-url.com';
    $this->container->get('config.factory')->reset('metsis_drupal.settings');

    $form = MetsisSettingsForm::create($this->container)->buildForm([], new FormState());

    $this->assertSame('https://overridden-url.com', $form['metsis_services']['bokeh_plot_service_url']['#default_value']);
    $this->assertTrue($form['metsis_services']['bokeh_plot_service_url']['#disabled']);

    unset($GLOBALS['config']['metsis_drupal.settings']['bokeh_plot_service_url']);
    $this->container->get('config.factory')->reset('metsis_drupal.settings');
  }

}
