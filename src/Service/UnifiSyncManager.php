<?php

namespace Drupal\unifi_access_sync\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

class UnifiSyncManager {

  private EntityTypeManagerInterface $etm;
  private $cfg;
  private LoggerChannelInterface $log;
  private UnifiApiService $api;

  public function __construct(
    EntityTypeManagerInterface $etm,
    ConfigFactoryInterface $config_factory,
    LoggerChannelInterface $log,
    UnifiApiService $api
  ) {
    $this->etm = $etm;
    $this->cfg = $config_factory->get('unifi_access_sync.settings');
    $this->log = $log;
    $this->api = $api;
  }

  /** Public entry point for cron & drush. */
  public function reconcile(): void {
    $should = $this->getShouldHaveAccessEmails();
    $have = $this->mapUnifiUsersByEmail();

    // Add missing
    foreach ($should as $email => $name) {
      if (!isset($have[$email])) {
        $payload = $this->userPayloadForEmail($email, $name);
        $this->log->notice('Creating UniFi user @e', ['@e' => $email]);
        $this->api->createUser($payload);
      }
    }
    // Remove extras
    foreach ($have as $email => $user) {
      if (!isset($should[$email]) && !empty($user['id'])) {
        $this->log->notice('Deleting UniFi user @e', ['@e' => $email]);
        $this->api->deleteUser($user['id']);
      }
    }
  }

  /** Targeted add/remove for a single email. */
  public function syncSingleByEmail(string $email, bool $should_have, string $name = ''): void {
    $have = $this->mapUnifiUsersByEmail();
    $exists = isset($have[$email]);
    if ($should_have && !$exists) {
      $this->api->createUser($this->userPayloadForEmail($email, $name));
    } elseif (!$should_have && $exists && !empty($have[$email]['id'])) {
      $this->api->deleteUser($have[$email]['id']);
    }
  }

  /** Build Drupal "should-have" from badge_request nodes. */
  public function getShouldHaveAccessEmails(): array {
    $door_tid = (int) $this->cfg->get('door_term_id');
    if (!$door_tid) return [];

    $q = $this->etm->getStorage('node')->getQuery()
      ->condition('type', 'badge_request')
      ->condition('field_badge_requested.target_id', $door_tid)
      ->condition('field_badge_status.value', 'active')
      ->accessCheck(FALSE);
    $nids = $q->execute();
    if (!$nids) return [];

    $nodes = $this->etm->getStorage('node')->loadMultiple($nids);
    $uids = [];
    foreach ($nodes as $n) {
      if ($n->hasField('field_member_to_badge') && !$n->get('field_member_to_badge')->isEmpty()) {
        $uid = (int) $n->get('field_member_to_badge')->target_id;
        if ($uid) $uids[$uid] = TRUE;
      }
    }
    if (!$uids) return [];

    $users = $this->etm->getStorage('user')->loadMultiple(array_keys($uids));
    $result = [];
    foreach ($users as $u) {
      $email = (string) $u->getEmail();
      if ($email) {
        $name = trim(($u->get('field_first_name')->value ?? '') . ' ' . ($u->get('field_last_name')->value ?? ''));
        if ($name === '') $name = $u->getDisplayName();
        $result[$email] = $name;
      }
    }
    return $result;
  }

  private function mapUnifiUsersByEmail(): array {
    $list = $this->api->listUsers();
    $map = [];
    foreach ($list as $u) {
      $email = $u['email'] ?? NULL;
      if ($email) {
        $map[$email] = [
          'id' => $u['id'] ?? ($u['_id'] ?? NULL),
          'raw' => $u,
        ];
      }
    }
    return $map;
  }

  private function userPayloadForEmail(string $email, string $name = ''): array {
    return [
      'email' => $email,
      'name' => $name !== '' ? $name : $email,
      // Add any additional fields your console requires here.
    ];
  }
}
