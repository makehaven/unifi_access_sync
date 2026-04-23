<?php

namespace Drupal\unifi_access_sync\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\unifi_access_sync\Service\UnifiApiService;
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

  public function __construct(array $configuration, $plugin_id, $plugin_definition, UnifiApiService $unifi_api, LoggerChannelInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->api = $unifi_api;
    $this->logger = $logger;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('unifi_access_sync.api'),
      $container->get('logger.channel.unifi_access_sync')
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

        case 'delete':
          if (!$user_id) {
            $this->logger->error('Cannot delete UniFi user @e: Missing user ID.', ['@e' => $email]);
            break;
          }
          $result = $this->api->deleteUser($user_id);
          if ($result->ok) {
            $this->logger->notice('UniFi user @e (ID: @id) deleted successfully via queue.', ['@e' => $email, '@id' => $user_id]);
          }
          else {
            $this->logger->error(
              'Failed to delete UniFi user @e (ID: @id) via queue: @reason',
              ['@e' => $email, '@id' => $user_id, '@reason' => $result->describe()]
            );
          }
          break;

        default:
          $this->logger->warning('Unknown UniFi sync action "@action" for user @e.', ['@action' => $action, '@e' => $email]);
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
