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

    $form['use_key_module'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use Key module for API Token'),
      '#default_value' => $cfg->get('use_key_module') ?? FALSE,
    ];

    $form['api_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('UniFi Access API Token'),
      '#description' => $this->t('Developer token with user read/write permissions.'),
      '#default_value' => $cfg->get('api_token') ?? '',
      '#states' => [
        'visible' => [
          ':input[name="use_key_module"]' => ['checked' => FALSE],
        ],
        'required' => [
          ':input[name="use_key_module"]' => ['checked' => FALSE],
        ],
      ],
    ];

    if (\Drupal::moduleHandler()->moduleExists('key')) {
      $keys = \Drupal::service('key.repository')->getKeys();
      $options = [];
      foreach ($keys as $key) {
        $options[$key->id()] = $key->label();
      }

      $form['api_key_id'] = [
        '#type' => 'select',
        '#title' => $this->t('UniFi Access API Key'),
        '#description' => $this->t('Select the key containing the UniFi Access API Token.'),
        '#options' => $options,
        '#default_value' => $cfg->get('api_key_id') ?? '',
        '#empty_option' => $this->t('- Select a Key -'),
        '#states' => [
          'visible' => [
            ':input[name="use_key_module"]' => ['checked' => TRUE],
          ],
          'required' => [
            ':input[name="use_key_module"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }
    else {
      $form['use_key_module']['#disabled'] = TRUE;
      $form['use_key_module']['#description'] = $this->t('Install the Key module to use this feature.');
    }

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
      ->set('use_key_module', (bool) $form_state->getValue('use_key_module'))
      ->set('api_token', (string) $form_state->getValue('api_token'))
      ->set('api_key_id', (string) $form_state->getValue('api_key_id'))
      ->set('verify_ssl', (bool) $form_state->getValue('verify_ssl'))
      ->set('door_term_id', $door_term_id)
      ->save();

    parent::submitForm($form, $form_state);
  }
}
