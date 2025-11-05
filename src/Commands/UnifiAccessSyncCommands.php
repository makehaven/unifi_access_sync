<?php

namespace Drupal\unifi_access_sync\Commands;

use Drush\Commands\DrushCommands;
use Drupal\unifi_access_sync\Service\UnifiSyncManager;

class UnifiAccessSyncCommands extends DrushCommands {

  protected UnifiSyncManager $mgr;

  public function __construct(UnifiSyncManager $mgr) {
    parent::__construct();
    $this->mgr = $mgr;
  }

  /**
   * Run a full reconcile of UniFi users with Drupal Door-eligible members.
   *
   * @command unifi:sync
   */
  public function sync() : void {
    $this->output()->writeln('Reconciling UniFi users...');
    $this->mgr->reconcile();
    $this->output()->writeln('Done.');
  }
}
