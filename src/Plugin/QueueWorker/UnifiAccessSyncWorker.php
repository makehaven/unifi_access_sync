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

  /**
   * The UniFi API service.
   *
   * @var \Drupal\unifi_access_sync\Service\UnifiApiService
   */
  protected UnifiApiService $api;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs a new UnifiAccessSyncWorker object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\unifi_access_sync\Service\UnifiApiService $unifi_api
   *   The UniFi API service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, UnifiApiService $unifi_api, LoggerChannelInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->api = $unifi_api;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
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
   */
  public function processItem($data) {
    if (!isset($data['action']) || !isset($data['email'])) {
      $this->logger->error('Invalid UniFi sync task data: Missing action or email. Data: @data', ['@data' => json_encode($data)]);
      return;
    }

    $action = $data['action'];
    $email = $data['email'];
    $user_data = $data['user_data'] ?? [];
    $user_id = $data['user_id'] ?? NULL; // Only needed for delete action.

    try {
      switch ($action) {
        case 'create':
          $payload = $this->api->userPayloadForData($email, $user_data);
          $result = $this->api->createUser($payload);
          if ($result) {
            $this->logger->notice('UniFi user @e created successfully via queue.', ['@e' => $email]);
          }
          else {
            $this->logger->error('Failed to create UniFi user @e via queue.', ['@e' => $email]);
            // If creation fails, we might want to re-queue the item or alert.
            // For now, just log.
          }
          break;

        case 'delete':
          if ($user_id) {
            $result = $this->api->deleteUser($user_id);
            if ($result) {
              $this->logger->notice('UniFi user @e (ID: @id) deleted successfully via queue.', ['@e' => $email, '@id' => $user_id]);
            }
            else {
              $this->logger->error('Failed to delete UniFi user @e (ID: @id) via queue.', ['@e' => $email, '@id' => $user_id]);
            }
          }
          else {
            $this->logger->error('Cannot delete UniFi user @e: Missing user ID.', ['@e' => $email]);
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
      // Re-throw the exception to make the Queue API aware of the failure,
      // potentially leading to item re-queueing depending on Queue API config.
      throw $e;
    }
  }

}
