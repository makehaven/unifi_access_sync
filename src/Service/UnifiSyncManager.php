<?php

namespace Drupal\unifi_access_sync\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Manages synchronization between Drupal badge_request nodes and UniFi Access.
 */
class UnifiSyncManager {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $etm;

  /**
   * The module configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  private $cfg;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private LoggerChannelInterface $log;

  /**
   * The UniFi API service.
   *
   * @var \Drupal\unifi_access_sync\Service\UnifiApiService
   */
  private UnifiApiService $api;

  /**
   * Static cache for UniFi users list.
   *
   * @var array|null
   */
  private static ?array $userCache = NULL;

  /**
   * Resets the static user cache.
   *
   * Primarily used for testing to ensure clean state between test runs.
   */
  public static function resetCache(): void {
    self::$userCache = NULL;
  }

  public function __construct(
    EntityTypeManagerInterface $etm,
    ConfigFactoryInterface $config_factory,
    LoggerChannelInterface $log,
    UnifiApiService $api,
  ) {
    $this->etm = $etm;
    $this->cfg = $config_factory->get('unifi_access_sync.settings');
    $this->log = $log;
    $this->api = $api;
  }

  /**
   * Reconciles Drupal members with UniFi Access users.
   *
   * Public entry point for cron and drush.
   */
  public function reconcile(): void {
    $door_tid = (int) $this->cfg->get('door_term_id');
    if (!$door_tid) {
      $this->log->warning('UniFi sync aborted: door_term_id is not configured.');
      return;
    }

    $should = $this->getShouldHaveAccessUserData();
    $have = $this->mapUnifiUsersByEmail();

    // Add missing.
    foreach ($should as $email => $data) {
      if (!isset($have[$email])) {
        $payload = $this->userPayloadForData($email, $data);
        $this->log->notice('Creating UniFi user @e', ['@e' => $email]);
        $this->api->createUser($payload);
      }
    }
    // Remove extras.
    foreach ($have as $email => $user) {
      if (!isset($should[$email]) && !empty($user['id'])) {
        $this->log->notice('Deleting UniFi user @e', ['@e' => $email]);
        $this->api->deleteUser($user['id']);
      }
    }
  }

  /**
   * Performs targeted add/remove for a single email.
   *
   * @param string $email
   *   The user's email address.
   * @param bool $should_have
   *   Whether the user should have access.
   * @param array $user_data
   *   Optional user data (first_name, last_name).
   */
  public function syncSingleByEmail(string $email, bool $should_have, array $user_data = []): void {
    $have = $this->mapUnifiUsersByEmail();
    $exists = isset($have[$email]);
    if ($should_have && !$exists) {
      $this->api->createUser($this->userPayloadForData($email, $user_data));
    }
    elseif (!$should_have && $exists && !empty($have[$email]['id'])) {
      $this->api->deleteUser($have[$email]['id']);
    }
  }

  /**
   * Builds list of user data that should have access from badge_request nodes.
   *
   * @return array
   *   Associative array of email => [first_name, last_name] for users with active Door badges.
   */
  public function getShouldHaveAccessUserData(): array {
    $door_tid = (int) $this->cfg->get('door_term_id');
    if (!$door_tid) {
      return [];
    }

    $q = $this->etm->getStorage('node')->getQuery()
      ->condition('type', 'badge_request')
      ->condition('field_badge_requested.target_id', $door_tid)
      ->condition('field_badge_status.value', 'active')
      ->accessCheck(FALSE);
    $nids = $q->execute();
    if (!$nids) {
      return [];
    }

    $nodes = $this->etm->getStorage('node')->loadMultiple($nids);
    $uids = [];
    foreach ($nodes as $n) {
      if ($n->hasField('field_member_to_badge') && !$n->get('field_member_to_badge')->isEmpty()) {
        $uid = (int) $n->get('field_member_to_badge')->target_id;
        if ($uid) {
          $uids[$uid] = TRUE;
        }
      }
    }
    if (!$uids) {
      return [];
    }

    $users = $this->etm->getStorage('user')->loadMultiple(array_keys($uids));
    $result = [];
    foreach ($users as $u) {
      $email = (string) $u->getEmail();
      if ($email) {
        $result[$email] = [
          'first_name' => (string) ($u->get('field_first_name')->value ?? ''),
          'last_name' => (string) ($u->get('field_last_name')->value ?? ''),
          'display_name' => (string) $u->getDisplayName(),
        ];
      }
    }
    return $result;
  }

  /**
   * Fetches UniFi users and indexes them by email.
   *
   * Uses static caching to prevent redundant API calls during the same request.
   *
   * @return array
   *   Associative array of email => user data.
   */
  private function mapUnifiUsersByEmail(): array {
    if (self::$userCache === NULL) {
      $list = $this->api->listUsers();
      $map = [];
      foreach ($list as $u) {
        // Note: UniFi Access local API typically nests email inside a 'profile' object.
        $email = $u['email'] ?? ($u['profile']['email'] ?? NULL);
        if ($email) {
          $map[$email] = [
            'id' => $u['id'] ?? ($u['_id'] ?? NULL),
            'raw' => $u,
          ];
        }
      }
      self::$userCache = $map;
    }
    return self::$userCache;
  }

  /**
   * Builds the API payload for creating a user.
   *
   * @param string $email
   *   The user's email address.
   * @param array $data
   *   The user's data (first_name, last_name, display_name).
   *
   * @return array
   *   The payload for the UniFi API.
   */
  private function userPayloadForData(string $email, array $data = []): array {
    // Note: The UniFi Access Developer API (local console) expects a 'profile' object
    // with 'first_name', 'last_name', and 'email'. Flat fields like 'name' are often
    // rejected or ignored by newer versions of the API.
    $first = $data['first_name'] ?? '';
    $last = $data['last_name'] ?? '';

    // Fallback if names are empty.
    if ($first === '' && $last === '') {
      $parts = explode(' ', $data['display_name'] ?? $email);
      $first = array_shift($parts);
      $last = implode(' ', $parts) ?: '.';
    }

    return [
      'profile' => [
        'email' => $email,
        'first_name' => $first,
        'last_name' => $last,
      ],
    ];
  }

}
