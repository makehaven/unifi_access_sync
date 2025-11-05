<?php

namespace Drupal\unifi_access_sync\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class UnifiSettingsForm extends ConfigFormBase {

  const CONFIG_NAME = 'unifi_access_sync.settings';

  public function getFormId() {
    return 'unifi_access_sync_settings_form';
  }

  protected function getEditableConfigNames() {
    return [self::CONFIG_NAME];
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $cfg = $this->config(self::CONFIG_NAME);

    $form['about'] = [
      '#type' => 'item',
      '#markup' => $this->t('Sync active "Door" members to UniFi for offline validation.'),
    ];

    $form['api_host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('UniFi Access API Host'),
      '#description' => $this->t('Example: https://192.168.1.2:12445'),
      '#default_value' => $cfg->get('api_host') ?? '',
      '#required' => TRUE,
    ];

    $form['api_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('UniFi Access API Token'),
      '#description' => $this->t('Developer token with user read/write permissions.'),
      '#default_value' => $cfg->get('api_token') ?? '',
      '#required' => TRUE,
    ];

    $form['verify_ssl'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Verify SSL certificates'),
      '#default_value' => (bool) $cfg->get('verify_ssl'),
    ];

    $form['door_term_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Door permission term ID (badges vocabulary)'),
      '#default_value' => $cfg->get('door_term_id') ?? 0,
      '#min' => 1,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $door_term_id = (int) $form_state->getValue('door_term_id');

    $this->configFactory()->getEditable(self::CONFIG_NAME)
      ->set('api_host', rtrim((string) $form_state->getValue('api_host'), '/'))
      ->set('api_token', (string) $form_state->getValue('api_token'))
      ->set('verify_ssl', (bool) $form_state->getValue('verify_ssl'))
      ->set('door_term_id', $door_term_id)
      ->save();

    parent::submitForm($form, $form_state);
  }
}
