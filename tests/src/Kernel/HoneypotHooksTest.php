<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\views\Entity\View;
use Drupal\views\ViewExecutable;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests hook discovery and real Honeypot validation without global protection.
 */
#[Group('metsis_drupal')]
final class HoneypotHooksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'views', 'search_api', 'search_api_solr',
    'leaflet', 'geofield', 'honeypot', 'metsis_drupal',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['honeypot']);
    $this->installSchema('honeypot', ['honeypot_user']);
  }

  /**
   * Tests the registered hooks attach working protections to both form IDs.
   */
  public function testProtectionAndValidation(): void {
    foreach (['views_exposed_form', 'metsis_drupal.metadata_export.form'] as $form_id) {
      $form = [
        '#id' => 'test-form',
        'actions' => ['submit' => ['#type' => 'submit']],
      ];
      $state = (new FormState())
        ->setUserInput(['form_id' => $form_id])
        ->setTriggeringElement(['#value' => 'Search']);
      if ($form_id === 'views_exposed_form') {
        $view = $this->createMock(ViewExecutable::class);
        $view->storage = new View(['id' => 'custom_search', 'base_table' => 'search_api_index_metsis'], 'view');
        $view->filter = [];
        $view->current_display = 'page_2';
        $state->set('view', $view);
      }
      $this->container->get('module_handler')->alter('form', $form, $state, $form_id);
      self::assertSame('textfield', $form['url']['#type']);
      self::assertSame('hidden', $form['honeypot_time']['#type']);
      self::assertSame(0, $form['honeypot_time']['#cache']['max-age']);
      self::assertContains('config:honeypot.settings', $form['#cache']['tags']);

      $state->setValues([
        'form_id' => $form_id,
        'honeypot_time' => $form['honeypot_time']['#default_value'],
      ]);
      $callback = $form['honeypot_time']['#element_validate'][0];
      $callback($form['honeypot_time'], $state, $form);
      self::assertTrue($state->hasAnyErrors(), 'Fast submissions are rejected.');
      $state->clearErrors();

      $store = $this->container->get('keyvalue.expirable')->get('honeypot_time_restriction');
      self::assertInstanceOf(KeyValueStoreExpirableInterface::class, $store);
      $store->setWithExpire(
        $state->getValue('honeypot_time'),
        $this->container->get('datetime.time')->getRequestTime() - 3600,
        86400,
      );
      $callback($form['honeypot_time'], $state, $form);
      self::assertFalse($state->hasAnyErrors(), 'Submissions after the time limit pass.');

      $callback = $form['url']['#element_validate'][0];
      $form['url']['#value'] = '';
      $callback($form['url'], $state, $form);
      self::assertFalse($state->hasAnyErrors());
      $form['url']['#value'] = 'spam';
      $callback($form['url'], $state, $form);
      self::assertTrue($state->hasAnyErrors(), 'Filled trap fields are rejected.');
      $state->clearErrors();
    }
  }

  /**
   * Tests the standard Honeypot bypass permission remains effective.
   */
  public function testBypass(): void {
    $account = $this->createMock(UserSession::class);
    $account->method('hasPermission')->with('bypass honeypot protection')->willReturn(TRUE);
    $this->container->get('current_user')->setAccount($account);
    $form = [];
    $state = new FormState();
    $form_id = 'metsis_drupal.metadata_export.form';
    $this->container->get('module_handler')->alter('form', $form, $state, $form_id);
    self::assertArrayNotHasKey('url', $form);
    self::assertArrayNotHasKey('honeypot_time', $form);
  }

}
