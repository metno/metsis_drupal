<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\honeypot\HoneypotServiceInterface;
use Drupal\metsis_drupal\Utility\MetsisViewContext;
use Drupal\views\ViewExecutable;

/**
 * Protects METSIS search and metadata export forms.
 */
final class HoneypotHooks {

  /**
   * Constructs the form hooks.
   */
  public function __construct(
    private readonly HoneypotServiceInterface $honeypot,
  ) {}

  /**
   * Implements hook_form_alter().
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if ($form_id === 'views_exposed_form') {
      $view = $form_state->get('view');
      if (!$view instanceof ViewExecutable || !MetsisViewContext::isView($view)) {
        return;
      }
    }
    elseif ($form_id !== 'metsis_drupal.metadata_export.form') {
      return;
    }

    $this->honeypot->addFormProtection($form, $form_state, ['honeypot', 'time_restriction']);

    $input = $form_state->getUserInput();
    if ($form_id === 'views_exposed_form'
      && !isset($input['form_id'])
      && !isset($input['honeypot_time'])
      && isset($form['honeypot_time'])) {
      // Views always validates GET input, including unsubmitted deep links.
      $form['honeypot_time']['#element_validate'] = [];
    }
  }

}
