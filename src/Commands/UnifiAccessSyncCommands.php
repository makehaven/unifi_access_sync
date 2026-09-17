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

    if ($s['error'] !== NULL) {
      $o->writeln('Console reachable:  NO');
      $o->writeln('Reason:             ' . $s['error']);
      return;
    }

    $o->writeln('Console reachable:  yes');
    $o->writeln(sprintf('Drupal expects:     %d door-badged members', $s['expected']));
    $o->writeln(sprintf('Console holds:      %d', $s['present']));
    $o->writeln(sprintf('Valve floor (50%%):  %d', $s['floor']));
    $o->writeln(sprintf('Missing / extra:    %d / %d', $s['missing'], $s['extra']));
    $o->writeln('');

    if (!$s['enabled']) {
      $o->writeln('Nothing will happen: the sync is switched off.');
      $o->writeln('To turn it on:      drush cset unifi_access_sync.settings sync_enabled true');
    }
    if ($s['valve_would_block']) {
      $o->writeln('The valve WOULD BLOCK: the console holds too few users to trust.');
      $o->writeln('Seeding it is deliberate and writes once per missing member:');
      $o->writeln(sprintf('                      drush unifi:sync --force   (~%d creates)', $s['missing']));
    }
    elseif ($s['enabled']) {
      $o->writeln(sprintf('Next cron run would queue %d create(s).', $s['missing']));
    }
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
    if (!$this->mgr->syncEnabled()) {
      $this->output()->writeln('UniFi sync is switched off (sync_enabled is false); nothing to do.');
      $this->output()->writeln('Turn it on with: drush cset unifi_access_sync.settings sync_enabled true');
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
