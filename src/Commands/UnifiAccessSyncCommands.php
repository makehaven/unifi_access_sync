<?php

namespace Drupal\unifi_access_sync\Commands;

use Drush\Commands\DrushCommands;
use Drupal\unifi_access_sync\Service\UnifiSyncManager;

/**
 * Drush commands for UniFi Access Sync.
 */
class UnifiAccessSyncCommands extends DrushCommands {

  /**
   * The sync manager service.
   *
   * @var \Drupal\unifi_access_sync\Service\UnifiSyncManager
   */
  protected UnifiSyncManager $mgr;

  public function __construct(UnifiSyncManager $mgr) {
    parent::__construct();
    $this->mgr = $mgr;
  }

  /**
   * Report what the UniFi sync would do right now, without doing any of it.
   *
   * Read-only. One API call, no queue writes. Exists so that "what is the
   * UniFi sync doing?" is one command with one answer, instead of reading
   * watchdog and inferring.
   *
   * @command unifi:status
   * @usage drush unifi:status
   *   Show the switch, the roster comparison and whether the valve would block.
   */
  public function status(): void {
    $s = $this->mgr->status();
    $o = $this->output();

    $o->writeln('Sync switched on:   ' . ($s['enabled'] ? 'yes' : 'NO (sync_enabled is false)'));
    $o->writeln('Environment:        ' . ($s['live_env'] ? 'live (writes permitted)' : 'NOT live — writes REFUSED whatever the switch says'));

    if ($s['error'] !== NULL) {
      $o->writeln('Console reachable:  NO');
      $o->writeln('Reason:             ' . $s['error']);
      return;
    }

    $o->writeln('Console reachable:  yes');
    $o->writeln(sprintf('Drupal expects:     %d door-badged CURRENT members (badge + member role)', $s['expected']));
    $o->writeln(sprintf('Console holds:      %d active  (+ %d deactivated, no door access)', $s['present'], $s['present_deactivated']));
    $o->writeln(sprintf('Valve floor (50%%):  %d active', $s['floor']));
    $o->writeln(sprintf('To create:          %d  (members with no console record)', $s['missing']));
    $o->writeln(sprintf('To reactivate:      %d  (members present but switched off)', $s['reactivate']));
    $o->writeln(sprintf('Reactivation held:  %d  (switched-off records that carry an email address; reactivate_emailed_records is off — on clears each address first)', $s['reactivate_held']));
    $o->writeln(sprintf('Extra (active):     %d  (active in the console, not a current door-badged member)', $s['extra']));
    $o->writeln(sprintf('To provision:       %s', $s['provisioning_enabled']
      ? $s['provision'] . '  (active members missing card / door policy / photo, not tried in the last day)'
      : 'off (provision_nfc_cards, access_policy_ids and provision_avatars all unset)'));
    $o->writeln('');

    if (!$s['enabled']) {
      $o->writeln('Nothing will happen: the sync is switched off.');
      $o->writeln('To turn it on:      drush cset unifi_access_sync.settings sync_enabled 1  (NOT `false`/`true` as words — `cset` writes them as STRINGS and "false" is truthy)');
    }
    if ($s['valve_would_block']) {
      $o->writeln('The valve WOULD BLOCK: the console holds too few users to trust.');
      $o->writeln('Seeding it is deliberate and writes once per member needing it:');
      $o->writeln(sprintf('                      drush unifi:sync --force   (~%d creates + %d reactivations)', $s['missing'], $s['reactivate']));
    }
    elseif ($s['enabled']) {
      $o->writeln(sprintf('Next cron run would queue %d create(s) and %d reactivation(s).', $s['missing'], $s['reactivate']));
    }
  }

  /**
   * Plan, or perform, the sync for ONE named member — the pre-flight test.
   *
   * Prints exactly what would be sent. Creates never carry an email address
   * (that is what makes UniFi mail a "Welcome to UniFi Identity!"
   * invitation). Does not need sync_enabled — see UnifiSyncManager::syncOne()
   * — but only writes on live, and only with --execute.
   *
   * @command unifi:sync-one
   * @param string $email The member's Drupal account email.
   * @option execute Perform the one write (default: plan only).
   * @option clear-email If the console record carries an email address,
   *   clear it, re-read the record, and only then reactivate / provision.
   *   Records that keep an address (UniFi OS admins, SSO logins) are left alone.
   * @option reactivate-emailed Old name for --clear-email.
   * @usage drush unifi:sync-one someone@example.com
   *   Show what would be sent for this member. Writes nothing.
   * @usage drush unifi:sync-one someone@example.com --execute
   *   Create (or reactivate, if the record has no address) this one member.
   */
  public function syncOne(string $email, array $options = ['execute' => FALSE, 'clear-email' => FALSE, 'reactivate-emailed' => FALSE]): void {
    $r = $this->mgr->syncOne($email, (bool) $options['execute'], (bool) $options['clear-email'] || (bool) $options['reactivate-emailed']);
    $o = $this->output();
    $o->writeln('Action:   ' . $r['action'] . ($r['detail'] ? '  — ' . $r['detail'] : ''));
    if ($r['payload'] !== NULL) {
      $o->writeln('Payload:  ' . json_encode($r['payload'], JSON_UNESCAPED_SLASHES));
    }
    $o->writeln('Result:   ' . $r['reason']);
    foreach ($r['provision'] ?? [] as $step => $s) {
      $state = $s['ok'] === TRUE ? 'DONE' : ($s['ok'] === FALSE ? 'FAILED' : ($s['do'] ? 'would' : '-'));
      $o->writeln(sprintf('  %-6s %-7s %s', $step, $state, $s['detail']));
    }
    if (empty($r['provision']) && $r['action'] === 'create' && !$r['executed']) {
      $o->writeln('  (card / door / photo are planned once the record exists)');
    }
  }

  /**
   * List the console's access policies, to choose access_policy_ids.
   *
   * Read-only. Needs the token to carry the view:policy permission; a
   * CODE_UNAUTHORIZED answer means the token must be reissued with it.
   *
   * @command unifi:policies
   * @usage drush unifi:policies
   *   Print each policy's id, name and the doors or groups it covers.
   */
  public function policies(): void {
    $r = $this->mgr->accessPolicies();
    $o = $this->output();
    if (!$r->ok) {
      $o->writeln('Could not list policies: ' . $r->describe());
      $o->writeln('A CODE_UNAUTHORIZED here means the API token lacks view:policy.');
      return;
    }
    foreach ((array) $r->data as $p) {
      $resources = array_map(fn($x) => ($x['type'] ?? '?') . ':' . ($x['id'] ?? '?'), (array) ($p['resources'] ?? []));
      $o->writeln(sprintf('%s  %s  [%s]', $p['id'] ?? '?', $p['name'] ?? '?', implode(', ', $resources)));
    }
    $o->writeln('Set with: drush cset unifi_access_sync.settings access_policy_ids --input-format=yaml \'["<id>"]\'');
  }

  /**
   * Run a full reconcile of UniFi users with Drupal Door-eligible members.
   *
   * Without --force this behaves exactly like the hourly cron run, including
   * the amplification valve that refuses to enqueue when the console reports
   * implausibly few users. --force is for seeding a genuinely empty or
   * rebuilt console, and should only be used after confirming that api_host
   * points at the console you mean to fill.
   *
   * @command unifi:sync
   * @option force Bypass the amplification valve and enqueue every missing
   *   user regardless of how few the console currently reports.
   * @usage drush unifi:sync
   *   Normal reconcile, valve active.
   * @usage drush unifi:sync --force
   *   Seed a console that is legitimately empty or sparse.
   */
  public function sync(array $options = ['force' => FALSE]) : void {
    if (!$this->mgr->isLiveEnvironment()) {
      $this->output()->writeln('REFUSED: this is not the live environment.');
      $this->output()->writeln('Every environment runs on a clone of live\'s database, so it holds live\'s');
      $this->output()->writeln('UniFi credentials and can reach the REAL door appliance. On 2026-09-18');
      $this->output()->writeln('Pantheon dev created ~1,224 real users this way and emailed every member.');
      return;
    }
    if (!$this->mgr->syncEnabled()) {
      $this->output()->writeln('UniFi sync is switched off (sync_enabled is false); nothing to do.');
      $this->output()->writeln('Turn it on with: drush unifi:enable  (never `cset ... false`, which writes');
      $this->output()->writeln('the STRING "false" and is truthy).');
      return;
    }
    $force = (bool) $options['force'];
    if ($force) {
      $this->output()->writeln('Reconciling UniFi users (amplification valve BYPASSED)...');
    }
    else {
      $this->output()->writeln('Reconciling UniFi users...');
    }
    $this->mgr->reconcile($force);
    $this->output()->writeln('Done.');
  }

}
