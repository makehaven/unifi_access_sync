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

    // Amplification safety valve. An implausibly small tenant view is almost
    // always a setup, connectivity or API problem (wrong door_term_id, wrong
    // console, a 2xx error envelope, creates silently failing) rather than
    // 3,000 members genuinely needing to be added this hour. Refusing here
    // keeps a handful-of-failures problem from becoming a
    // hundreds-of-thousands-of-failures problem.
    // Record what this run saw, so hook_requirements() can report the valve
    // without making its own API call — a 20s timeout on the status report
    // page would be a poor trade for a number we already have here.
    $this->recordRun(count($should), count($have), !$this->tenantViewIsPlausible(count($should), count($have)));

    if (!$force && !$this->tenantViewIsPlausible(count($should), count($have))) {
      $this->log->error(
        'UniFi sync aborted: console reported @have users while @n Drupal members expect access '
        . '(below the @pct percent floor). Nothing was enqueued and nothing will be until this is resolved. '
        . 'Check that api_host points at the intended console, that listUsers is not returning an '
        . 'error envelope inside an HTTP 200, and that recent createUser calls actually succeeded. '
        . 'If the console really is meant to be this empty (fresh install, rebuilt console), run '
        . 'drush unifi:sync --force once to seed it.',
        [
          '@have' => count($have),
          '@n' => count($should),
          '@pct' => (int) round(self::MIN_PRESENT_RATIO * 100),
        ]
      );
      return;
    }

    $queue = $this->queueFactory->get('unifi_access_sync_queue');

    foreach ($should as $key => $data) {
      if (!isset($have[$key])) {
        $email = $data['email'] ?? $key;
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
    // The UniFi side is indexed lowercase; match on the same footing.
    $key = mb_strtolower($email);
    $exists = isset($have[$key]);

    $queue = $this->queueFactory->get('unifi_access_sync_queue');

    if ($should_have && !$exists) {
      $this->log->notice('Queueing single UniFi user creation for @e', ['@e' => $email]);
      $queue->createItem([
        'action' => 'create',
        'email' => $email,
        'user_data' => $user_data,
      ]);
    }
    elseif (!$should_have && $exists && !empty($have[$key]['id'])) {
      if (!$this->deletesAllowed()) {
        $this->log->notice('Would revoke UniFi access for @e — revocation is disabled (allow_delete).', ['@e' => $email]);
        return;
      }
      $this->log->notice('Queueing single UniFi access revocation for @e', ['@e' => $email]);
      $queue->createItem([
        'action' => 'deactivate',
        'email' => $email,
        'user_id' => $have[$key]['id'],
      ]);
    }
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
        // Keyed lowercase so it compares against the UniFi side, which is
        // also lowercased; `email` keeps the address as the member actually
        // has it, and that is what gets sent to the API.
        $result[mb_strtolower($email)] = [
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
   *   Keys: enabled, expected, present, floor, valve_would_block, reachable,
   *   error, missing, extra.
   */
  public function status(): array {
    $out = [
      'enabled' => $this->syncEnabled(),
      'live_env' => $this->isLiveEnvironment(),
      'writes_allowed' => $this->writesAllowed(),
      'expected' => 0,
      'present' => 0,
      'floor' => 0,
      'valve_would_block' => FALSE,
      'reachable' => FALSE,
      'error' => NULL,
      'missing' => 0,
      'extra' => 0,
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
    $out['present'] = count($have);
    $out['missing'] = count(array_diff_key($should, $have));
    $out['extra'] = count(array_diff_key($have, $should));
    $out['valve_would_block'] = !$this->tenantViewIsPlausible($out['expected'], $out['present']);

    return $out;
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
      foreach ($addresses as $email) {
        if (is_string($email) && $email !== '') {
          $map[mb_strtolower($email)] = ['id' => $id, 'raw' => $u];
        }
      }
    }
    self::$userCache = $map;
    return UnifiApiResult::success(data: $map);
  }

}
