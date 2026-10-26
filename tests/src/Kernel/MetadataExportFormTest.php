<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\metsis_drupal\Form\MetadataExportForm;
use Drupal\metsis_drupal\Service\MetadataExportService;
use Drupal\metsis_drupal\Utility\MetsisSolrUtilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Kernel tests for MetadataExportForm.
 */
#[CoversClass(MetadataExportForm::class)]
#[Group('metsis_drupal')]
final class MetadataExportFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'search_api',
    'search_api_solr',
    'leaflet',
    'geofield',
    'honeypot',
    'metsis_drupal',
  ];

  /**
   * Form under test.
   *
   * @var \Drupal\metsis_drupal\Form\MetadataExportForm
   */
  protected MetadataExportForm $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create form instance manually without full DI.
    $config_factory = $this->container->get('config.factory');
    $metadata_export_service = new MetadataExportService(
      $config_factory,
      $this->container->get('entity_type.manager')
    );
    $this->form = new MetadataExportForm($metadata_export_service, $config_factory);
  }

  /**
   * Test form ID is correct.
   */
  #[Test]
  public function testGetFormId(): void {
    $this->assertSame('metsis_drupal.metadata_export.form', $this->form->getFormId());
  }

  /**
   * Test form builds without ID parameter.
   */
  #[Test]
  public function testBuildFormWithoutId(): void {
    $form_state = new FormState();
    $form = $this->form->buildForm([], $form_state, '');

    $this->assertArrayHasKey('export', $form);
    $this->assertSame('markup', $form['export']['#type']);
    $this->assertStringContainsString('Missing dataset identifier', (string) $form['export']['#markup']);
  }

  /**
   * Test form rejects invalid ID with special characters.
   */
  #[Test]
  public function testBuildFormInvalidIdWithSpecialChars(): void {
    $form_state = new FormState();
    $id = 'invalid@dataset#id';

    $form = $this->form->buildForm([], $form_state, $id);

    // Should show error markup.
    $this->assertArrayHasKey('export', $form);
    $this->assertSame('markup', $form['export']['#type']);
  }

  /**
   * Test form rejects invalid ID with slashes.
   */
  #[Test]
  public function testBuildFormInvalidIdWithSlashes(): void {
    $form_state = new FormState();
    $id = 'invalid/dataset/id';

    $form = $this->form->buildForm([], $form_state, $id);

    // Should show error markup.
    $this->assertArrayHasKey('export', $form);
    $this->assertSame('markup', $form['export']['#type']);
  }

  /**
   * Test form accepts valid IDs with allowed special characters.
   */
  #[Test]
  public function testBuildFormValidIdWithAllowedChars(): void {
    // Valid patterns: letters, numbers, dots, colons, hyphens, underscores.
    $valid_ids = [
      'simple-id',
      'id.with.dots',
      'id:with:colons',
      'id_with_underscores',
      'mixed-id.with:all_chars',
      'ABC123def',
    ];

    foreach ($valid_ids as $id) {
      $form_state = new FormState();
      $form_state->set('mmd', '<mmd />');
      $form_state->set('export_options', ['mmd' => 'MMD']);
      $form_state->set('default_export', 'mmd');
      $form = $this->form->buildForm([], $form_state, $id);

      $this->assertArrayHasKey('export', $form);
      $this->assertSame('fieldset', $form['export']['#type']);
      $this->assertSame(MetsisSolrUtilities::toSolrId($id), $form['export']['solr_id']['#default_value']);
      $this->assertSame('<mmd />', $form['export']['mmd']['#default_value']);
      $this->assertSame('select', $form['export']['list']['#type']);
    }
  }

  /**
   * Test submit handler with empty ID.
   */
  #[Test]
  public function testSubmitFormWithEmptyId(): void {
    $form = [];
    $form_state = new FormState();
    $form_state->setValues([
      'solr_id' => '',
      'list' => 'mmd',
    ]);

    // Mock messenger for error messages.
    $messenger = $this->container->get('messenger');

    $this->form->submitForm($form, $form_state);

    // Should have error message.
    $messages = $messenger->all();
    $this->assertNotEmpty($messages);
  }

  /**
   * Test submit handler with invalid ID.
   */
  #[Test]
  public function testSubmitFormWithInvalidId(): void {
    $form = [];
    $form_state = new FormState();
    $form_state->setValues([
      'solr_id' => 'invalid@id!',
      'list' => 'mmd',
    ]);

    $messenger = $this->container->get('messenger');

    $this->form->submitForm($form, $form_state);

    // Should have error message about invalid identifier.
    $messages = $messenger->all();
    $this->assertNotEmpty($messages);
    $error_messages = $messages['error'] ?? [];
    $this->assertNotEmpty($error_messages);
  }

  /**
   * Test submit handler with missing export type.
   */
  #[Test]
  public function testSubmitFormWithMissingExportType(): void {
    $form = [];
    $form_state = new FormState();
    $form_state->setValues([
      'solr_id' => 'valid-id',
      'list' => '',
    ]);

    $messenger = $this->container->get('messenger');

    $this->form->submitForm($form, $form_state);

    // Should have error message about missing parameters.
    $messages = $messenger->all();
    $this->assertNotEmpty($messages);
  }

  /**
   * Test form validates ID pattern with regex.
   */
  #[Test]
  public function testIdValidationPattern(): void {
    $invalid_ids = [
      'id with spaces',
      'id@with@at',
      'id#with#hash',
      'id&with&ampersand',
      'id$with$dollar',
      'id(with)parens',
      'id[with]brackets',
      'id{with}braces',
      'id<with>angles',
      'id"with"quotes',
      'id\'with\'apostrophes',
      'id\\with\\backslash',
      'id|with|pipe',
      'id?with?question',
      'id*with*star',
      'id+with+plus',
      'id=with=equals',
      '',
    ];

    $form_state = new FormState();

    foreach ($invalid_ids as $id) {
      $form = $this->form->buildForm([], $form_state, $id);

      // Invalid IDs should result in markup instead of full form.
      if ($id !== '') {
        $this->assertArrayHasKey('export', $form);
        $this->assertSame('markup', $form['export']['#type']);
      }
    }
  }

  /**
   * Test valid ID patterns are accepted.
   */
  #[Test]
  public function testValidIdPatterns(): void {
    $valid_ids = [
      'simple',
      'with-dash',
      'with_underscore',
      'with.dot',
      'with:colon',
      'MixedCase123',
      'complex-id.with:multiple_chars',
      'a',
    ];

    $form_state = new FormState();

    foreach ($valid_ids as $id) {
      $form_state = new FormState();
      $form_state->set('mmd', '<mmd />');
      $form_state->set('export_options', ['mmd' => 'MMD']);
      $form_state->set('default_export', 'mmd');
      $form = $this->form->buildForm([], $form_state, $id);

      $this->assertArrayHasKey('export', $form);
      $this->assertSame(MetsisSolrUtilities::toSolrId($id), $form['export']['solr_id']['#default_value']);
    }
  }

}
