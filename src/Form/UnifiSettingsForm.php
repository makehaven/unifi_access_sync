<?php

namespace Drupal\unifi_access_sync\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\unifi_access_sync\Service\UnifiApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for UniFi Access Sync settings.
 */
class UnifiSettingsForm extends ConfigFormBase {

  const CONFIG_NAME = 'unifi_access_sync.settings';

  /**
   * The UniFi API service.
   *
   * @var \Drupal\unifi_access_sync\Service\UnifiApiService
   */
  protected UnifiApiService $api;

  /**
   * Constructs a new UnifiSettingsForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\unifi_access_sync\Service\UnifiApiService $api
   *   The UniFi API service.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config
   *   The typed config manager.
   */
  public function __construct($config_factory, UnifiApiService $api, $typed_config = NULL) {
    parent::__construct($config_factory, $typed_config);
    $this->api = $api;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('unifi_access_sync.api'),
      $container->get('config.typed')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'unifi_access_sync_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [self::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $cfg = $this->config(self::CONFIG_NAME);

    $form['about'] = [
      '#type' => 'details',
      '#title' => $this->t('Setup Guide: UniFi Access Console'),
      '#open' => TRUE,
      '#markup' => '<ol>' .
        '<li>' . $this->t('Log in to your UniFi Console (e.g., UDM Pro / UNVR).') . '</li>' .
        '<li>' . $this->t('Open the <strong>UniFi Access</strong> application.') . '</li>' .
        '<li>' . $this->t('Go to <strong>Settings</strong> &rarr; <strong>Control Plane</strong> &rarr; <strong>Integrations</strong>.') . '</li>' .
        '<li>' . $this->t('Click <strong>Add New API Token</strong> (or similar) to generate your <strong>X-API-KEY</strong>.') . '</li>' .
        '<li>' . $this->t('Copy the token and paste it into the <strong>API Token</strong> field below.') . '</li>' .
        '<li>' . $this->t('Note the <strong>API Host</strong> URL provided in the console (usually the IP of your console).') . '</li>' .
      '</ol>' .
      '<p><strong>' . $this->t('Cloud Hosting Tip:') . '</strong> ' .
      $this->t('If this Drupal site is in the cloud and your UniFi console is on a local network, they cannot talk to each other by default. We recommend using a <strong>Cloudflare Tunnel</strong> or <strong>Tailscale</strong> to securely expose the UniFi API to this server without opening risky firewall ports.') . '</p>',
    ];

    $form['api_host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('UniFi Access API Host'),
      '#description' => $this->t('Example: https://192.168.1.1. Note: This module uses the <strong>/proxy/access/integration/v1/developer/</strong> path as specified in the console Integrations tab.'),
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
      '#description' => $this->t('Developer token. This will be sent as the <strong>X-API-KEY</strong> header.'),
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

    $form['actions']['test'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test API Connection'),
      '#limit_validation_errors' => [],
      '#submit' => ['::testApiConnection'],
      '#button_type' => 'secondary',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Tests the API connection by attempting to list users.
   */
  public function testApiConnection(array &$form, FormStateInterface $form_state) {
    // Note: This uses the CURRENTLY SAVED configuration.
    $users = $this->api->listUsers();
    if (!empty($users)) {
      $this->messenger()->addStatus($this->t('Successfully connected to UniFi! Found @count users.', [
        '@count' => count($users),
      ]));
    }
    else {
      $this->messenger()->addError($this->t('Failed to retrieve users. Check logs and verify API Host, Token, and SSL settings.'));
    }
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
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
