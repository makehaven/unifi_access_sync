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

  /**
   * Smallest share of expected members the console may hold before we refuse.
   *
   * If UniFi reports fewer than this fraction of the members Drupal expects,
   * reconcile treats the console view as untrustworthy and enqueues nothing.
   * See the amplification note on reconcile() for why an emptiness test was
   * not enough.
   */
  private const MIN_PRESENT_RATIO = 0.5;

  /**
   * The etm.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $etm;
  /**
   * The cfg.
   *
   * @var mixed
   */
  private $cfg;
  /**
   * The log.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private LoggerChannelInterface $log;
  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected QueueFactory $queueFactory;
  /**
   * The api.
   *
   * @var UnifiApiService
   */
  private UnifiApiService $api;

  /**
   * Card / door policy / photo provisioning for present members.
   *
   * Optional so older wiring (and tests) that construct the manager without
   * it keep working; with no provisioner nothing beyond create / reactivate /
   * deactivate happens, exactly as before.
   *
   * @var \Drupal\unifi_access_sync\Service\UnifiProvisioner|null
   */
  private ?UnifiProvisioner $provisioner;

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
    ?UnifiProvisioner $provisioner = NULL,
  ) {
    $this->provisioner = $provisioner;
    $this->etm = $etm;
    $this->cfg = $config_factory->get('unifi_access_sync.settings');
    $this->log = $log;
    $this->api = $api;
    $this->queueFactory = $queue_factory;
  }

  /**
   * Reconciles Drupal members with UniFi Access users.
   *
   * Safety valve: if UniFi reports implausibly few users relative to what
   * Drupal expects, we refuse to enqueue anything. Without this guard a bad
   * console view makes every door-badged member look "missing", and since
   * cron runs hourly the full roster gets re-queued every hour.
   *
   * **This guard previously tested for an empty list and that was not
   * enough.** Between 2026-09-15 and 09-17 the console answered 200 with 23
   * users while Drupal expected 3,309. Twenty-three is not zero, so the valve
   * never opened: every hour re-queued ~3,294 creates, producing 340,234 log
   * rows in 61 hours and ~170,000 futile writes at the door. A ratio test
   * catches that case and still catches the empty one.
   *
   * The valve is also the backstop for systematically failing creates — if
   * creates stop working for any reason, the console never fills, the ratio
   * stays low, and the second run arrests instead of looping forever.
   *
   * A genuinely empty or sparse console (first-ever sync, or a rebuilt
   * console) is a real case and is served by $force, which the operator
   * supplies through `drush unifi:sync --force` after confirming that the
   * console really is the one we mean to fill.
   *
   * @param bool $force
   *   TRUE to bypass the ratio valve. Never set from cron.
   */
  public function reconcile(bool $force = FALSE): void {
    if (!$this->writesAllowed()) {
      // Deliberately quiet: this is the configured "off", not a fault, and it
      // is checked hourly. The state is reported on the status report by
      // hook_requirements() and by `drush unifi:status`.
      return;
    }

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
    // Only ACTIVE records count as "the console holds this member". After the
    // 2026-09-18 dev mass-create and clean-up the console held 1,227
    // DEACTIVATED records; counting
    // those would let the valve trust a roster in which almost nobody can
    // actually open the door. Counted per RECORD, not per map key: a record
    // is indexed under each of its addresses and its drupal uid.
    $active = $this->activeOnly($this->uniqueRecords($have));

    // Amplification safety valve. An implausibly small tenant view is almost
    // always a setup, connectivity or API problem (wrong door_term_id, wrong
    // console, a 2xx error envelope, creates silently failing) rather than
    // 3,000 members genuinely needing to be added this hour. Refusing here
    // keeps a handful-of-failures problem from becoming a
    // hundreds-of-thousands-of-failures problem.
    // Record what this run saw, so hook_requirements() can report the valve
    // without making its own API call — a 20s timeout on the status report
    // page would be a poor trade for a number we already have here.
    $this->recordRun(count($should), count($active), !$this->tenantViewIsPlausible(count($should), count($active)));

    if (!$force && !$this->tenantViewIsPlausible(count($should), count($active))) {
      $this->log->error(
        'UniFi sync aborted: console reported @have active users while @n Drupal members expect access '
        . '(below the @pct percent floor). Nothing was enqueued and nothing will be until this is resolved. '
        . 'Check that api_host points at the intended console, that listUsers is not returning an '
        . 'error envelope inside an HTTP 200, and that recent createUser calls actually succeeded. '
        . 'If the console really is meant to be this empty (fresh install, rebuilt console), run '
        . 'drush unifi:sync --force once to seed it.',
        [
          '@have' => count($active),
          '@n' => count($should),
          '@pct' => (int) round(self::MIN_PRESENT_RATIO * 100),
        ]
      );
      return;
    }

    $queue = $this->queueFactory->get('unifi_access_sync_queue');

    $held = 0;
    $provisioning = 0;
    foreach ($should as $key => $data) {
      $email = $data['email'] ?? $key;
      $record = $this->findRecord($have, $key, $data);
      if ($record === NULL) {
        $this->log->notice('Queueing UniFi user creation for @e', ['@e' => $email]);
        $queue->createItem([
          'action' => 'create',
          'email' => $email,
          'user_data' => $data,
        ]);
      }
      elseif ($this->isActiveRecord($record) && !empty($record['id'])) {
        // Present and on: does it have the card, the door and the photo?
        // needsWork() reads only the listUsers row and state, so this costs
        // no API call per member, and it backs off for a day after trying.
        if ($this->provisioner && $this->provisioner->needsWork((array) ($record['raw'] ?? []), (int) ($data['uid'] ?? 0))) {
          $queue->createItem([
            'action' => 'provision',
            'email' => $email,
            'user_id' => $record['id'],
            'uid' => (int) $data['uid'],
            'raw' => self::provisionView((array) ($record['raw'] ?? [])),
          ]);
          $provisioning++;
        }
      }
      elseif (!$this->isActiveRecord($record) && !empty($record['id'])) {
        // Present but switched off. A create would be refused
        // (CODE_ADMIN_EMAIL_EXIST) and the member would stay locked out.
        if ($this->reactivationHeld($record)) {
          $held++;
          continue;
        }
        $this->log->notice('Queueing UniFi reactivation for @e', ['@e' => $email]);
        $queue->createItem([
          'action' => 'reactivate',
          'email' => $email,
          'user_id' => $record['id'],
        ]);
      }
    }
    if ($provisioning) {
      $this->log->notice('Queued UniFi provisioning (card / door policy / photo) for @n member(s).', ['@n' => $provisioning]);
    }
    if ($held) {
      // One line per run, not one per member: this is a standing decision,
      // and 368 notices an hour would bury everything else.
      $this->log->notice('Held @n UniFi reactivation(s): those console records carry an email address, and reactivate_emailed_records is off. See UnifiSyncManager::reactivationHeld().', ['@n' => $held]);
    }
    $vouched = $this->vouchedKeys($should);
    foreach ($this->uniqueRecords($have) as $user) {
      $email = $this->labelFor($user);
      // A record that is already DEACTIVATED holds no access; there is
      // nothing to revoke and nothing worth a log line every hour.
      if (!array_intersect_key(array_flip($user['keys']), $vouched) && !empty($user['id']) && $this->isActiveRecord($user)) {
        if (!$this->deletesAllowed()) {
          $this->log->notice('Would revoke UniFi access for @e (not door-badged in Drupal) — revocation is disabled (allow_delete).', ['@e' => $email]);
          continue;
        }
        $this->log->notice('Queueing UniFi access revocation for @e', ['@e' => $email]);
        $queue->createItem([
          'action' => 'deactivate',
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
    if (!$this->writesAllowed()) {
      return;
    }
    $fetch = $this->fetchUnifiUsers();
    if (!$fetch->ok) {
      $this->log->error(
        'UniFi single-sync aborted for @e: listUsers failed (@reason). Not enqueuing.',
        ['@e' => $email, '@reason' => $fetch->describe()]
      );
      return;
    }
    $have = $fetch->data ?? [];
    // The UniFi side is indexed lowercase; match on the same footing, then by
    // drupal uid — records this module creates carry no address at all.
    $key = mb_strtolower($email);
    $record = $this->findRecord($have, $key, $user_data);
    $exists = $record !== NULL;

    $queue = $this->queueFactory->get('unifi_access_sync_queue');

    if ($should_have && !$exists) {
      $this->log->notice('Queueing single UniFi user creation for @e', ['@e' => $email]);
      $queue->createItem([
        'action' => 'create',
        'email' => $email,
        'user_data' => $user_data,
      ]);
    }
    elseif ($should_have && $exists && !$this->isActiveRecord($record) && !empty($record['id'])) {
      if ($this->reactivationHeld($record)) {
        $this->log->notice('Held UniFi reactivation for @e: the console record carries an email address and reactivate_emailed_records is off.', ['@e' => $email]);
        return;
      }
      $this->log->notice('Queueing single UniFi reactivation for @e', ['@e' => $email]);
      $queue->createItem([
        'action' => 'reactivate',
        'email' => $email,
        'user_id' => $record['id'],
      ]);
    }
    elseif (!$should_have && $exists && !empty($record['id']) && $this->isActiveRecord($record)) {
      if (!$this->deletesAllowed()) {
        $this->log->notice('Would revoke UniFi access for @e — revocation is disabled (allow_delete).', ['@e' => $email]);
        return;
      }
      $this->log->notice('Queueing single UniFi access revocation for @e', ['@e' => $email]);
      $queue->createItem([
        'action' => 'deactivate',
        'email' => $email,
        'user_id' => $record['id'],
      ]);
    }
  }

  /**
   * Plans — and with $execute, performs — the sync for ONE named member.
   *
   * The safe way to prove a change at the door before turning the sync on:
   * one record, one write, performed immediately rather than queued, and
   * everything that would be sent is returned so the operator can see there
   * is no address in it.
   *
   * Deliberately does NOT require `sync_enabled`: flipping the switch to
   * test one record would also open the hourly reconcile and the badge
   * hooks, which is the opposite of a single-record test. Naming one member
   * and passing --execute is the consent. It DOES require the live
   * environment, like every other write — every other environment holds a
   * clone of live's credentials. It only acts on a current door-badged
   * member, so it cannot put a non-member on the console.
   *
   * @param string $email
   *   The member's Drupal account email.
   * @param bool $execute
   *   FALSE (default) plans only; TRUE performs the one write.
   * @param bool $reactivate_emailed
   *   TRUE to reactivate even a record that carries an email address — the
   *   one-record test that decides reactivate_emailed_records.
   *
   * @return array
   *   Keys: action (create|reactivate|none), reason, payload, executed, ok,
   *   detail.
   */
  public function syncOne(string $email, bool $execute = FALSE, bool $reactivate_emailed = FALSE): array {
    $out = [
      'action' => 'none',
      'reason' => '',
      'payload' => NULL,
      'executed' => FALSE,
      'ok' => NULL,
      'detail' => '',
      'provision' => [],
    ];
    $key = mb_strtolower(trim($email));
    $should = $this->getShouldHaveAccessUserData();
    if (!isset($should[$key])) {
      $out['reason'] = 'not a current door-badged member (door badge active + member role + unblocked); nothing to sync';
      return $out;
    }
    $data = $should[$key];

    $fetch = $this->fetchUnifiUsers();
    if (!$fetch->ok) {
      $out['reason'] = 'listUsers failed: ' . $fetch->describe();
      return $out;
    }
    $record = $this->findRecord($fetch->data ?? [], $key, $data);

    if ($record === NULL) {
      $out['action'] = 'create';
      $out['payload'] = $this->api->userPayloadForData($data['email'], $data);
    }
    elseif (!$this->isActiveRecord($record) && !empty($record['id'])) {
      $out['action'] = 'reactivate';
      $out['payload'] = ['status' => UnifiApiService::STATUS_ACTIVE];
      $out['detail'] = 'console record ' . $record['id'] . ($record['has_email'] ? ' (carries an email address)' : ' (no email address)');
      if ($this->reactivationHeld($record) && !$reactivate_emailed) {
        $out['reason'] = 'held: this record carries an email address; pass --reactivate-emailed to test it on this one member';
        return $out;
      }
    }
    else {
      $out['reason'] = 'already ACTIVE in the console (record ' . ($record['id'] ?? '?') . ')';
    }

    if (!$execute) {
      if ($out['action'] !== 'none') {
        $out['reason'] = 'plan only; pass --execute to perform this one write';
      }
      if ($record !== NULL && $this->isActiveRecord($record) && !empty($record['id'])) {
        $out['provision'] = $this->provisionPlan((string) $record['id'], $data, (array) ($record['raw'] ?? []), FALSE);
      }
      return $out;
    }
    if (!$this->isLiveEnvironment()) {
      $out['reason'] = 'REFUSED: not the live environment';
      return $out;
    }

    $user_id = (string) ($record['id'] ?? '');
    $raw = (array) ($record['raw'] ?? []);
    if ($out['action'] !== 'none') {
      $result = $out['action'] === 'create'
        ? $this->api->createUser($out['payload'])
        : $this->api->reactivateUser((string) $record['id']);
      self::resetCache();
      $out['executed'] = TRUE;
      $out['ok'] = $result->ok;
      $out['reason'] = $result->ok ? 'done' : 'FAILED: ' . $result->describe();
      $this->log->notice('unifi:sync-one @a for @e: @r', [
        '@a' => $out['action'],
        '@e' => $data['email'],
        '@r' => $out['reason'],
      ]);
      if (!$result->ok) {
        return $out;
      }
      if ($out['action'] === 'create') {
        // The create answer carries the new id; fall back to a fresh read.
        $user_id = is_array($result->data) ? (string) ($result->data['id'] ?? '') : '';
        if ($user_id === '') {
          $again = $this->fetchUnifiUsers();
          $user_id = (string) ($this->findRecord($again->data ?? [], $key, $data)['id'] ?? '');
        }
        $raw = [];
      }
    }
    if ($user_id !== '') {
      $out['provision'] = $this->provisionPlan($user_id, $data, $raw, TRUE);
    }
    return $out;
  }

  /**
   * The console's access policies (read-only; for `drush unifi:policies`).
   */
  public function accessPolicies(): UnifiApiResult {
    return $this->api->listAccessPolicies();
  }

  /**
   * Provisioning steps for one member, or [] when provisioning is off.
   */
  private function provisionPlan(string $user_id, array $data, array $raw, bool $execute): array {
    if (!$this->provisioner || !$this->provisioner->enabled()) {
      return [];
    }
    return $this->provisioner->provision($user_id, (int) ($data['uid'] ?? 0), $raw, $execute);
  }

  /**
   * Performs one queued provisioning item (called by the queue worker).
   */
  public function provisionQueued(array $item): array {
    if (!$this->provisioner || empty($item['user_id']) || empty($item['uid'])) {
      return [];
    }
    return $this->provisioner->provision((string) $item['user_id'], (int) $item['uid'], (array) ($item['raw'] ?? []), TRUE);
  }

  /**
   * The parts of a console row that provisioning reads, for a queue item.
   */
  private static function provisionView(array $raw): array {
    return [
      'nfc_cards' => $raw['nfc_cards'] ?? [],
      'avatar_relative_path' => $raw['avatar_relative_path'] ?? '',
      // Carried into the queue so the worker re-checks it: provisioning never
      // touches a record with an address (UnifiProvisioner::carriesEmail()).
      'has_email' => UnifiProvisioner::carriesEmail($raw),
    ];
  }

  /**
   * Whether reconcile may remove UniFi users that Drupal does not vouch for.
   *
   * Off by default: the console may hold staff, contractors and visitors
   * that were never Drupal door badges, and a first reconcile against a
   * populated console would otherwise delete them all.
   */
  public function deletesAllowed(): bool {
    return (bool) $this->cfg->get('allow_delete');
  }

  /**
   * Builds list of user data that should have access from badge_request nodes.
   *
   * Two conditions, and both are needed. The door badge records that a person
   * has been through orientation — it is a qualification and is never revoked
   * (JR, 2026-09-21), so on its own it names 3,314 people, of whom ~2,470 are
   * former members. Whether they are a member *now* is the role. So: door
   * badge active AND the member role AND the account not blocked. On
   * 2026-09-21 that was 848 people, not 3,314.
   *
   * The role name is `member_role` in settings and defaults to `member`.
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

    // NULL (key never seeded) means the default; an explicit empty string
    // means "no role check" — the two must not collapse into each other.
    $role = $this->cfg->get('member_role');
    $role = $role === NULL ? 'member' : trim((string) $role);
    $users = $this->etm->getStorage('user')->loadMultiple(array_keys($uids));
    $result = [];
    foreach ($users as $u) {
      // A blocked account or a lapsed membership keeps its badge (the
      // qualification) but not its door access.
      if (!$u->isActive() || ($role !== '' && !$u->hasRole($role))) {
        continue;
      }
      $email = (string) $u->getEmail();
      if ($email) {
        // Keyed lowercase so it compares against the UniFi side, which is
        // also lowercased; `email` keeps the address as the member actually
        // has it, and that is what gets sent to the API.
        $result[mb_strtolower($email)] = [
          'uid' => (int) $u->id(),
          'email' => $email,
          'first_name' => (string) ($u->get('field_first_name')->value ?? ''),
          'last_name' => (string) ($u->get('field_last_name')->value ?? ''),
          'display_name' => (string) $u->getDisplayName(),
        ];
      }
    }
    return $result;
  }

  /**
   * Stores the outcome of a reconcile for later reporting.
   *
   * State rather than config: this is an observation, not a setting, and it
   * must not travel between environments in a config export.
   */
  private function recordRun(int $expected, int $present, bool $blocked): void {
    \Drupal::state()->set('unifi_access_sync.last_run', [
      'time' => \Drupal::time()->getRequestTime(),
      'expected' => $expected,
      'present' => $present,
      'blocked' => $blocked,
    ]);
  }

  /**
   * Whether syncing to UniFi is switched on at all.
   *
   * Separate from, and checked before, the amplification valve. The valve
   * answers "is the console's view trustworthy right now"; this answers "do we
   * want this module talking to the door appliance at all". Shipped FALSE, in
   * the same shape as the sibling event_access_unifi module, so the module is
   * definitively inert until someone decides otherwise rather than relying on
   * the valve to keep refusing.
   */
  public function syncEnabled(): bool {
    return (bool) $this->cfg->get('sync_enabled');
  }

  /**
   * Whether this environment may write to the door appliance at all.
   *
   * `syncEnabled()` answers "has someone switched the sync on". This answers
   * the question nobody had asked: "is this environment even allowed to talk
   * to the PRODUCTION console?" Every non-live environment — Pantheon dev and
   * test, the preview sandbox, and any local site after `lando pull-db` —
   * runs on a clone of live's database, so it holds live's UniFi credentials
   * and can reach the real appliance. Pantheon runs cron on all of them.
   *
   * On 2026-09-18 Pantheon **dev** created ~1,224 real users on the production
   * console, emailing an invitation to each member, because its stale database
   * had never run the update hook that seeds `sync_enabled`. Reads are
   * harmless and stay allowed; writes are now refused unless this is live.
   *
   * Deliberately environment-derived rather than configurable: a config flag
   * would itself be cloned to every environment, which is the failure mode.
   */
  public function isLiveEnvironment(): bool {
    $env = $_ENV['PANTHEON_ENVIRONMENT'] ?? getenv('PANTHEON_ENVIRONMENT') ?: NULL;
    // Off-Pantheon (Lando, CI, a container) is never the live appliance's owner.
    if (!is_string($env) || $env === '') {
      return FALSE;
    }
    return $env === 'live';
  }

  /**
   * The single gate every write path must pass: switched on AND on live.
   */
  public function writesAllowed(): bool {
    return $this->syncEnabled() && $this->isLiveEnvironment();
  }

  /**
   * Describes what the sync would do right now, without doing any of it.
   *
   * Read-only: one listUsers call, no queue writes. This is what
   * `drush unifi:status` and hook_requirements() render, so that "what is the
   * UniFi sync doing?" has a single answer anyone — or any AI session — can
   * get in one command instead of reading watchdog.
   *
   * @return array
   *   Keys: enabled, live_env, writes_allowed, expected, present (ACTIVE
   *   console users), present_deactivated, floor, valve_would_block,
   *   reachable, error, missing (create needed), reactivate (present but
   *   DEACTIVATED), reactivate_held (DEACTIVATED records carrying an email
   *   address, held while reactivate_emailed_records is off), extra (ACTIVE
   *   in the console, not vouched for by Drupal).
   */
  public function status(): array {
    $out = [
      'enabled' => $this->syncEnabled(),
      'live_env' => $this->isLiveEnvironment(),
      'writes_allowed' => $this->writesAllowed(),
      'expected' => 0,
      'present' => 0,
      'present_deactivated' => 0,
      'floor' => 0,
      'valve_would_block' => FALSE,
      'reachable' => FALSE,
      'error' => NULL,
      'missing' => 0,
      'reactivate' => 0,
      'reactivate_held' => 0,
      'extra' => 0,
      'provision' => 0,
      'provisioning_enabled' => $this->provisioner?->enabled() ?? FALSE,
    ];

    $door_tid = (int) $this->cfg->get('door_term_id');
    if (!$door_tid) {
      $out['error'] = 'door_term_id is not configured.';
      return $out;
    }

    $should = $this->getShouldHaveAccessUserData();
    $out['expected'] = count($should);
    $out['floor'] = (int) ceil($out['expected'] * self::MIN_PRESENT_RATIO);

    $fetch = $this->fetchUnifiUsers();
    if (!$fetch->ok) {
      $out['error'] = $fetch->describe();
      return $out;
    }

    $have = $fetch->data ?? [];
    $out['reachable'] = TRUE;
    $records = $this->uniqueRecords($have);
    $active = $this->activeOnly($records);
    $out['present'] = count($active);
    $out['present_deactivated'] = count($records) - count($active);
    foreach ($should as $key => $data) {
      $record = $this->findRecord($have, $key, $data);
      if ($record === NULL) {
        $out['missing']++;
      }
      elseif (!$this->isActiveRecord($record)) {
        $this->reactivationHeld($record) ? $out['reactivate_held']++ : $out['reactivate']++;
      }
      elseif ($this->provisioner && $this->provisioner->needsWork((array) ($record['raw'] ?? []), (int) ($data['uid'] ?? 0))) {
        $out['provision']++;
      }
    }
    $vouched = $this->vouchedKeys($should);
    foreach ($active as $record) {
      if (!array_intersect_key(array_flip($record['keys']), $vouched)) {
        $out['extra']++;
      }
    }
    $out['valve_would_block'] = !$this->tenantViewIsPlausible($out['expected'], $out['present']);

    return $out;
  }

  /**
   * The map key under which a console record is indexed by drupal uid.
   */
  private static function uidKey(int $uid): string {
    return '#uid:' . $uid;
  }

  /**
   * Finds a member's console record: by address first, then by drupal uid.
   *
   * Address first so the records that already exist (console-UI users and
   * the 2026-09-18 mass-create, all of which carry an address) keep matching
   * exactly as before; uid second for the records this module now creates
   * without one. The uid match also survives a member changing their email.
   */
  private function findRecord(array $have, string $email_key, array $data): ?array {
    if (isset($have[$email_key])) {
      return $have[$email_key];
    }
    $uid = (int) ($data['uid'] ?? 0);
    if ($uid > 0 && isset($have[self::uidKey($uid)])) {
      return $have[self::uidKey($uid)];
    }
    return NULL;
  }

  /**
   * Every key under which a record would count as vouched for by Drupal.
   */
  private function vouchedKeys(array $should): array {
    $keys = [];
    foreach ($should as $key => $data) {
      $keys[$key] = TRUE;
      if (!empty($data['uid'])) {
        $keys[self::uidKey((int) $data['uid'])] = TRUE;
      }
    }
    return $keys;
  }

  /**
   * The indexed console map collapsed back to one entry per record.
   *
   * The map holds a record once per address and once per uid, so counting
   * or iterating its keys would count people twice.
   */
  private function uniqueRecords(array $have): array {
    $out = [];
    foreach ($have as $key => $entry) {
      $id = isset($entry['id']) && $entry['id'] !== '' ? 'id:' . $entry['id'] : 'key:' . $key;
      if (!isset($out[$id])) {
        $entry['keys'] = $entry['keys'] ?? [$key];
        $out[$id] = $entry;
      }
    }
    return $out;
  }

  /**
   * A human label for a record in log lines: its first address, else its key.
   */
  private function labelFor(array $record): string {
    return (string) ($record['keys'][0] ?? ($record['id'] ?? '(unknown)'));
  }

  /**
   * Whether reactivating this record is being held back.
   *
   * Reactivation sends `{"status":"ACTIVE"}` and nothing else, so it cannot
   * *give* a record an address. But the 368 current members switched off
   * since 2026-09-18 already carry one (the dev mass-create sent
   * `user_email`), and it has not been shown that flipping such a record back
   * to ACTIVE does not make UniFi (re)send its Identity invitation — those
   * invitations expired after 7 days. Until one record has been reactivated
   * and watched (`drush unifi:sync-one <email> --execute
   * --reactivate-emailed`), these are held. `reactivate_emailed_records`
   * TRUE releases them. Records this module creates carry no address and are
   * never held.
   */
  public function reactivationHeld(array $record): bool {
    return !empty($record['has_email']) && !$this->cfg->get('reactivate_emailed_records');
  }

  /**
   * Whether a console record has door access right now.
   *
   * Only an explicit DEACTIVATED counts as switched off. A record with no
   * `status` at all (an older fixture, or a console that stops sending the
   * field) is treated as active on purpose: the other reading would make
   * every member look like they need restoring and queue a PUT per member
   * per hour at the door — the same shape as the 2026-09-15 runaway. The
   * cost of this reading is that a genuinely deactivated member whose status
   * we cannot see stays deactivated, which is the status quo, not a new
   * write.
   */
  private function isActiveRecord(array $record): bool {
    return strtoupper((string) ($record['status'] ?? '')) !== UnifiApiService::STATUS_DEACTIVATED;
  }

  /**
   * The subset of an indexed console map whose records can open the door.
   */
  private function activeOnly(array $have): array {
    return array_filter($have, fn(array $u) => $this->isActiveRecord($u));
  }

  /**
   * Decides whether the console's user list is plausible enough to act on.
   *
   * Returns TRUE when nothing is expected (nothing to amplify), and otherwise
   * requires the console to hold at least MIN_PRESENT_RATIO of the expected
   * roster. Deliberately a ratio and not a count: the failure this prevents
   * is proportional to the roster size, so the threshold has to be too.
   *
   * @param int $expected
   *   How many members Drupal believes should have access.
   * @param int $present
   *   How many users the console returned.
   *
   * @return bool
   *   TRUE when it is safe to enqueue.
   */
  private function tenantViewIsPlausible(int $expected, int $present): bool {
    if ($expected <= 0) {
      return TRUE;
    }
    return $present >= (int) ceil($expected * self::MIN_PRESENT_RATIO);
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
      // A user carries TWO email fields and which one is populated depends on
      // how they were created. Users added in the console UI have `email`;
      // users created through this API have `email: ""` and the address in
      // `user_email`, because `email` is read-only on the create endpoint.
      //
      // Reading only `email` is why the 2026-09-15 loop could not have healed
      // itself even once creates started working: every user this module
      // created would still have looked missing on the next pass, and been
      // created again. Both keys are indexed, lowercased, so the comparison
      // against Drupal's addresses is case-insensitive on both sides.
      $id = $u['id'] ?? ($u['_id'] ?? NULL);
      $addresses = [
        $u['user_email'] ?? NULL,
        $u['email'] ?? NULL,
        $u['profile']['email'] ?? NULL,
      ];
      // Status travels with the record: a DEACTIVATED user is *present* in
      // the console but has no door access, and the two cases need different
      // actions (reactivate vs create) and different counting (the valve
      // must not trust a console full of switched-off records).
      $status = strtoupper((string) ($u['status'] ?? ''));
      $keys = [];
      foreach ($addresses as $email) {
        if (is_string($email) && $email !== '') {
          $keys[] = mb_strtolower($email);
        }
      }
      // Records this module creates carry no address (see
      // UnifiApiService::userPayloadForData()); they are found by the
      // "drupal-{uid}" employee_number instead.
      $uid = UnifiApiService::uidFromEmployeeNumber($u['employee_number'] ?? NULL);
      if ($uid !== NULL) {
        $keys[] = self::uidKey($uid);
      }
      $entry = [
        'id' => $id,
        'status' => $status,
        'has_email' => count(array_filter($keys, fn(string $k) => !str_starts_with($k, '#uid:'))) > 0,
        'keys' => array_values(array_unique($keys)),
        'raw' => $u,
      ];
      foreach ($entry['keys'] as $k) {
        $map[$k] = $entry;
      }
    }
    self::$userCache = $map;
    return UnifiApiResult::success(data: $map);
  }

}
