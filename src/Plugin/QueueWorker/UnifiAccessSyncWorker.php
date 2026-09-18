<?php

namespace Drupal\unifi_access_sync\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\unifi_access_sync\Service\UnifiApiService;
use Drupal\unifi_access_sync\Service\UnifiSyncManager;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Processes UniFi user synchronization tasks.
 *
 * @QueueWorker(
 *   id = "unifi_access_sync_queue",
 *   title = @Translation("UniFi Access Synchronization Queue"),
 *   cron = {"time" = 60}
 * )
 */
class UnifiAccessSyncWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  protected UnifiApiService $api;
  protected LoggerChannelInterface $logger;
  protected UnifiSyncManager $manager;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, UnifiApiService $unifi_api, LoggerChannelInterface $logger, UnifiSyncManager $manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->api = $unifi_api;
    $this->logger = $logger;
    $this->manager = $manager;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('unifi_access_sync.api'),
      $container->get('logger.channel.unifi_access_sync'),
      $container->get('unifi_access_sync.sync_manager')
    );
  }

  /**
   * {@inheritdoc}
   *
   * On API failure, we log the detailed reason and consume the item
   * (no exception thrown). The next reconcile() will re-enqueue if the
   * user still needs syncing, so we don't lose the intent — but we avoid
   * the "same failing item retried forever" loop that an exception would
   * cause. Unexpected exceptions (not API failures) are still rethrown
   * so Drupal's queue backend can handle them appropriately.
   */
  public function processItem($data) {
    // The master switch has to be re-checked HERE, not only where items are
    // enqueued. Gating the queue-in and not the queue-out is not a master
    // switch: anything already queued still fires, and queued work outlives
    // the decision to stop. On 2026-09-18 that is exactly what happened —
    // Pantheon dev drained a backlog and created ~1,224 real users on the
    // PRODUCTION console, emailing an invitation to each one, while
    // `sync_enabled` was off everywhere a human had thought to look.
    if (!$this->manager->writesAllowed()) {
      $this->logger->warning('UniFi sync is switched off or this is not the live environment; discarding a queued @a for @e.', [
        '@a' => $data['action'] ?? 'task',
        '@e' => $data['email'] ?? '(no email)',
      ]);
      // Discarded rather than re-thrown: an exception would leave the item in
      // place to fire the moment the switch flips, which is the surprise this
      // guard exists to prevent. reconcile() re-enqueues whatever is still
      // genuinely needed once the sync is deliberately turned on.
      return;
    }

    if (!isset($data['action']) || !isset($data['email'])) {
      $this->logger->error('Invalid UniFi sync task data: Missing action or email. Data: @data', ['@data' => json_encode($data)]);
      return;
    }

    $action = $data['action'];
    $email = $data['email'];
    $user_data = $data['user_data'] ?? [];
    $user_id = $data['user_id'] ?? NULL;

    try {
      switch ($action) {
        case 'create':
          $payload = $this->api->userPayloadForData($email, $user_data);
          $result = $this->api->createUser($payload);
          if ($result->ok) {
            $this->logger->notice('UniFi user @e created successfully via queue.', ['@e' => $email]);
          }
          else {
            $this->logger->error(
              'Failed to create UniFi user @e via queue: @reason',
              ['@e' => $email, '@reason' => $result->describe()]
            );
          }
          break;

        // 'delete' is the historical action name and is still accepted so
        // that any item queued by an older release drains correctly. The
        // operation itself is a deactivation — see
        // UnifiApiService::deactivateUser() for why a real delete is not
        // available on this console.
        case 'delete':
        case 'deactivate':
          if (!$user_id) {
            $this->logger->error('Cannot revoke UniFi access for @e: Missing user ID.', ['@e' => $email]);
            break;
          }
          $result = $this->api->deactivateUser($user_id);
          if ($result->ok) {
            $this->logger->notice('UniFi access for @e (ID: @id) revoked via queue.', [
              '@e' => $email,
              '@id' => $user_id,
            ]);
          }
          else {
            $this->logger->error(
              'Failed to revoke UniFi access for @e (ID: @id) via queue: @reason',
              [
                '@e' => $email,
                '@id' => $user_id,
                '@reason' => $result->describe(),
              ]
            );
          }
          break;

        default:
          $this->logger->warning('Unknown UniFi sync action "@action" for user @e.', [
            '@action' => $action,
            '@e' => $email,
          ]);
          break;
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Exception processing UniFi sync task for user @e: @message', [
        '@e' => $email,
        '@message' => $e->getMessage(),
      ]);
      // Unexpected — let the queue backend decide what to do (retry etc.).
      throw $e;
    }
  }

}
