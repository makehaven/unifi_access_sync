<?php

namespace Drupal\unifi_access_sync\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;

/**
 * Manages synchronization between Drupal badge_request nodes and UniFi Access.
 */
class UnifiSyncManager {

  private EntityTypeManagerInterface $etm;
  private $cfg;
  private LoggerChannelInterface $log;
  protected QueueFactory $queueFactory;
  private UnifiApiService $api;

  /**
   * Static cache for UniFi users map. Keyed by email.
   *
   * @var array|null
   */
  private static ?array $userCache = NULL;

  /**
   * Resets the static user cache.
   *
   * Primarily used for testing to ensure clean state between runs.
   */
  public static function resetCache(): void {
    self::$userCache = NULL;
  }

  public function __construct(
    EntityTypeManagerInterface $etm,
    ConfigFactoryInterface $config_factory,
    LoggerChannelInterface $log,
    UnifiApiService $api,
    QueueFactory $queue_factory,
  ) {
    $this->etm = $etm;
    $this->cfg = $config_factory->get('unifi_access_sync.settings');
    $this->log = $log;
    $this->api = $api;
    $this->queueFactory = $queue_factory;
  }

  /**
   * Reconciles Drupal members with UniFi Access users.
   *
   * Safety valve: we detect "UniFi said empty while Drupal expects many users"
   * and refuse to enqueue anything in that case. Without this guard, a
   * transient API failure (expired token, network blip) causes listUsers()
   * to return empty, which makes every door-badged Drupal member look
   * "missing" from UniFi — and since cron runs hourly, the full member
   * roster gets re-queued each hour while the API is down. That's how a
   * half-million-item queue happens.
   */
  public function reconcile(): void {
    $door_tid = (int) $this->cfg->get('door_term_id');
    if (!$door_tid) {
      $this->log->warning('UniFi sync aborted: door_term_id is not configured.');
      return;
    }

    $should = $this->getShouldHaveAccessUserData();

    $fetch = $this->fetchUnifiUsers();
    if (!$fetch->ok) {
      $this->log->error(
        'UniFi sync aborted: listUsers failed (@reason). Not enqueuing anything. Will retry on next cron.',
        ['@reason' => $fetch->describe()]
      );
      return;
    }
    $have = $fetch->data ?? [];

    // Amplification safety valve: non-empty expectations + empty tenant view
    // is almost always a setup or bootstrap issue (wrong door_term_id, wrong
    // UniFi console, empty response body from a 2xx that should have returned
    // data). Skipping enqueue here keeps a handful-of-failures problem from
    // becoming a half-million-failures problem.
    if (!empty($should) && empty($have)) {
      $this->log->warning(
        'UniFi sync aborted: listUsers returned zero users while @n Drupal members expect access. '
        . 'Treating as setup/bootstrap issue. If UniFi is legitimately empty (fresh install), '
        . 'clear unifi_access_sync_queue and run drush unifi:sync after confirming the console has been provisioned.',
        ['@n' => count($should)]
      );
      return;
    }

    $queue = $this->queueFactory->get('unifi_access_sync_queue');

    foreach ($should as $email => $data) {
      if (!isset($have[$email])) {
        $this->log->notice('Queueing UniFi user creation for @e', ['@e' => $email]);
        $queue->createItem([
          'action' => 'create',
          'email' => $email,
          'user_data' => $data,
        ]);
      }
    }
    foreach ($have as $email => $user) {
      if (!isset($should[$email]) && !empty($user['id'])) {
        $this->log->notice('Queueing UniFi user deletion for @e', ['@e' => $email]);
        $queue->createItem([
          'action' => 'delete',
          'email' => $email,
          'user_id' => $user['id'],
        ]);
      }
    }
  }

  /**
   * Performs targeted add/remove for a single email.
   *
   * If listUsers fails, abort rather than assume "not present → create"
   * (same class of amplification risk as reconcile, though smaller scale
   * since this is per-event, not per-member-per-hour).
   */
  public function syncSingleByEmail(string $email, bool $should_have, array $user_data = []): void {
    $fetch = $this->fetchUnifiUsers();
    if (!$fetch->ok) {
      $this->log->error(
        'UniFi single-sync aborted for @e: listUsers failed (@reason). Not enqueuing.',
        ['@e' => $email, '@reason' => $fetch->describe()]
      );
      return;
    }
    $have = $fetch->data ?? [];
    $exists = isset($have[$email]);

    $queue = $this->queueFactory->get('unifi_access_sync_queue');

    if ($should_have && !$exists) {
      $this->log->notice('Queueing single UniFi user creation for @e', ['@e' => $email]);
      $queue->createItem([
        'action' => 'create',
        'email' => $email,
        'user_data' => $user_data,
      ]);
    }
    elseif (!$should_have && $exists && !empty($have[$email]['id'])) {
      $this->log->notice('Queueing single UniFi user deletion for @e', ['@e' => $email]);
      $queue->createItem([
        'action' => 'delete',
        'email' => $email,
        'user_id' => $have[$email]['id'],
      ]);
    }
  }

  /**
   * Builds list of user data that should have access from badge_request nodes.
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
   * Wraps the API call in a UnifiApiResult so callers can tell failure
   * from an empty-but-successful response. Caches the indexed map for the
   * life of a request so callers (reconcile + syncSingleByEmail)
   * can't trigger N API calls per request.
   *
   * @return \Drupal\unifi_access_sync\Service\UnifiApiResult
   *   On success, result->data is array<string, array> keyed by email.
   */
  private function fetchUnifiUsers(): UnifiApiResult {
    if (self::$userCache !== NULL) {
      return UnifiApiResult::success(data: self::$userCache);
    }
    $result = $this->api->listUsers();
    if (!$result->ok) {
      return $result;
    }
    $map = [];
    foreach ((array) $result->data as $u) {
      // UniFi Access local API typically nests email under 'profile'.
      $email = $u['email'] ?? ($u['profile']['email'] ?? NULL);
      if ($email) {
        $map[$email] = [
          'id' => $u['id'] ?? ($u['_id'] ?? NULL),
          'raw' => $u,
        ];
      }
    }
    self::$userCache = $map;
    return UnifiApiResult::success(data: $map);
  }

}
