<?php

namespace Drupal\Tests\unifi_access_sync\Unit;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\unifi_access_sync\Plugin\QueueWorker\UnifiAccessSyncWorker;
use Drupal\unifi_access_sync\Service\UnifiApiService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for UniFi access sync queue worker.
 */
#[CoversClass(UnifiAccessSyncWorker::class)]
#[Group('unifi_access_sync')]
class UnifiAccessSyncWorkerTest extends UnitTestCase {

  public function testProcessItemCreate(): void {
    $api = $this->createMock(UnifiApiService::class);
    $logger = $this->createMock(LoggerChannelInterface::class);

    $api->expects($this->once())
      ->method('userPayloadForData')
      ->with('add@example.com', ['display_name' => 'Add User'])
      ->willReturn(['profile' => ['email' => 'add@example.com']]);

    $api->expects($this->once())
      ->method('createUser')
      ->with(['profile' => ['email' => 'add@example.com']])
      ->willReturn(['id' => 'new_id']);

    $logger->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('created successfully via queue'));

    $worker = new UnifiAccessSyncWorker([], 'unifi_access_sync_queue', [], $api, $logger);
    $worker->processItem([
      'action' => 'create',
      'email' => 'add@example.com',
      'user_data' => ['display_name' => 'Add User'],
    ]);
  }

  public function testProcessItemDelete(): void {
    $api = $this->createMock(UnifiApiService::class);
    $logger = $this->createMock(LoggerChannelInterface::class);

    $api->expects($this->once())
      ->method('deleteUser')
      ->with('u123')
      ->willReturn(TRUE);

    $logger->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('deleted successfully via queue'));

    $worker = new UnifiAccessSyncWorker([], 'unifi_access_sync_queue', [], $api, $logger);
    $worker->processItem([
      'action' => 'delete',
      'email' => 'remove@example.com',
      'user_id' => 'u123',
    ]);
  }

  public function testProcessItemInvalidData(): void {
    $api = $this->createMock(UnifiApiService::class);
    $logger = $this->createMock(LoggerChannelInterface::class);

    $api->expects($this->never())->method('createUser');
    $api->expects($this->never())->method('deleteUser');

    $logger->expects($this->once())
      ->method('error')
      ->with($this->stringContains('Missing action or email'));

    $worker = new UnifiAccessSyncWorker([], 'unifi_access_sync_queue', [], $api, $logger);
    $worker->processItem(['action' => 'create']);
  }

  public function testProcessItemUnknownAction(): void {
    $api = $this->createMock(UnifiApiService::class);
    $logger = $this->createMock(LoggerChannelInterface::class);

    $logger->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('Unknown UniFi sync action'));

    $worker = new UnifiAccessSyncWorker([], 'unifi_access_sync_queue', [], $api, $logger);
    $worker->processItem([
      'action' => 'nope',
      'email' => 'user@example.com',
    ]);
  }

}
