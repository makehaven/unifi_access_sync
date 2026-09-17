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
