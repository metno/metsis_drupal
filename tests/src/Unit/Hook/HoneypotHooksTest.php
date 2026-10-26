<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit\Hook;

use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\honeypot\HoneypotServiceInterface;
use Drupal\metsis_drupal\Hook\HoneypotHooks;
use Drupal\Tests\UnitTestCase;
use Drupal\views\Entity\View;
use Drupal\views\ViewExecutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests mandatory protection of METSIS forms.
 */
#[Group('metsis_drupal')]
final class HoneypotHooksTest extends UnitTestCase {

  /**
   * Tests index-based detection independent of View ID, display, and tags.
   */
  #[DataProvider('formProvider')]
  public function testFormProtection(string $form_id, ?string $base_table, bool $protected): void {
    $state = new FormState();
    if ($base_table !== NULL) {
      $view = $this->createMock(ViewExecutable::class);
      $view->storage = new View(['id' => 'custom_search', 'base_table' => $base_table], 'view');
      $view->current_display = 'page_2';
      $state->set('view', $view);
    }
    $honeypot = $this->createMock(HoneypotServiceInterface::class);
    $honeypot->expects($protected ? self::once() : self::never())
      ->method('addFormProtection')
      ->with([], $state, ['honeypot', 'time_restriction']);
    $form = [];
    (new HoneypotHooks($honeypot))->formAlter($form, $state, $form_id);
  }

  /**
   * Provides protected forms and unrelated forms.
   */
  public static function formProvider(): array {
    return [
      'custom index view' => ['views_exposed_form', 'search_api_index_metsis', TRUE],
      'another index' => ['views_exposed_form', 'search_api_index_other', FALSE],
      'node view' => ['views_exposed_form', 'node_field_data', FALSE],
      'missing view' => ['views_exposed_form', NULL, FALSE],
      'metadata export' => ['metsis_drupal.metadata_export.form', NULL, TRUE],
      'unrelated form' => ['user_login_form', NULL, FALSE],
    ];
  }

  /**
   * Tests initial GET navigation does not fail the submission time check.
   */
  #[DataProvider('inputProvider')]
  public function testSearchTimeValidation(array $input, bool $validate): void {
    $view = $this->createMock(ViewExecutable::class);
    $view->storage = new View(['base_table' => 'search_api_index_metsis'], 'view');
    $state = (new FormState())->set('view', $view)->setUserInput($input);
    $honeypot = $this->createMock(HoneypotServiceInterface::class);
    $callback = [$honeypot, 'validateTimeRestriction'];
    $honeypot->method('addFormProtection')->willReturnCallback(
      static function (array &$form, FormStateInterface $state, array $options) use ($callback): void {
        $form['honeypot_time'] = ['#type' => 'hidden', '#element_validate' => [$callback]];
      },
    );
    $form = [];
    (new HoneypotHooks($honeypot))->formAlter($form, $state, 'views_exposed_form');
    self::assertSame($validate ? [$callback] : [], $form['honeypot_time']['#element_validate']);
  }

  /**
   * Provides navigation and submitted exposed form input.
   */
  public static function inputProvider(): array {
    return [
      'initial load' => [[], FALSE],
      'deep link' => [['text' => 'weather'], FALSE],
      'form submission' => [['form_id' => 'views_exposed_form', 'honeypot_time' => 'token'], TRUE],
      'missing timer' => [['form_id' => 'views_exposed_form'], TRUE],
      'AJAX timer input' => [['honeypot_time' => 'token'], TRUE],
    ];
  }

}
