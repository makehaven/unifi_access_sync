<?php

namespace Drupal\Tests\unifi_access_sync\Unit;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\unifi_access_sync\Plugin\QueueWorker\UnifiAccessSyncWorker;
use Drupal\unifi_access_sync\Service\UnifiApiResult;
use Drupal\unifi_access_sync\Service\UnifiApiService;
use Drupal\unifi_access_sync\Service\UnifiSyncManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for UniFi access sync queue worker.
 */
#[CoversClass(UnifiAccessSyncWorker::class)]
#[Group('unifi_access_sync')]
class UnifiAccessSyncWorkerTest extends UnitTestCase {

  /**
   * Builds a worker whose environment gate is open.
   *
   * processItem() re-checks UnifiSyncManager::writesAllowed() before every
   * action (the master switch and the live-only rule), so a worker without a
   * manager cannot run at all. These tests are about the actions, so the
   * gate is held open here; the gate itself is covered in
   * testProcessItemDiscardedWhenWritesNotAllowed().
   */
  protected function worker(UnifiApiService $api, LoggerChannelInterface $logger, bool $writes_allowed = TRUE): UnifiAccessSyncWorker {
    $manager = $this->createMock(UnifiSyncManager::class);
    $manager->method('writesAllowed')->willReturn($writes_allowed);
    return new UnifiAccessSyncWorker([], 'unifi_access_sync_queue', [], $api, $logger, $manager);
  }

  /**
   * With the switch off (or off-live), queued work is discarded, not run.
   */
  public function testProcessItemDiscardedWhenWritesNotAllowed(): void {
    $api = $this->createMock(UnifiApiService::class);
    $logger = $this->createMock(LoggerChannelInterface::class);
    $api->expects($this->never())->method('createUser');
    $api->expects($this->never())->method('reactivateUser');
    $api->expects($this->never())->method('deactivateUser');
    $logger->expects($this->once())->method('warning')->with($this->stringContains('discarding'));

    $this->worker($api, $logger, FALSE)->processItem([
      'action' => 'create',
      'email' => 'add@example.com',
      'user_data' => [],
    ]);
  }

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
      ->willReturn(UnifiApiResult::success(data: ['id' => 'new_id'], statusCode: 201));

    $logger->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('created successfully via queue'));

    $worker = $this->worker($api, $logger);
    $worker->processItem([
      'action' => 'create',
      'email' => 'add@example.com',
      'user_data' => ['display_name' => 'Add User'],
    ]);
  }

  /**
   * A failed create should log with the API result's describe() detail.
   */
  public function testProcessItemCreateFailureLogsReason(): void {
    $api = $this->createMock(UnifiApiService::class);
    $logger = $this->createMock(LoggerChannelInterface::class);

    $api->expects($this->once())
      ->method('userPayloadForData')
      ->willReturn(['profile' => ['email' => 'add@example.com']]);

    $api->expects($this->once())
      ->method('createUser')
      ->willReturn(UnifiApiResult::failure(
        errorMessage: 'createUser non-2xx response',
        statusCode: 401,
        responseBody: 'Unauthorized',
      ));

    $logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Failed to create UniFi user'),
        $this->callback(static function (array $args): bool {
          $reason = $args['@reason'] ?? '';
          return str_contains($reason, 'HTTP 401')
            && str_contains($reason, 'createUser non-2xx response')
            && str_contains($reason, 'Unauthorized');
        })
      );

    $worker = $this->worker($api, $logger);
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
      ->method('deactivateUser')
      ->with('u123')
      ->willReturn(UnifiApiResult::success(statusCode: 204));

    $logger->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('revoked via queue'));

    $worker = $this->worker($api, $logger);
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
    $api->expects($this->never())->method('deactivateUser');

    $logger->expects($this->once())
      ->method('error')
      ->with($this->stringContains('Missing action or email'));

    $worker = $this->worker($api, $logger);
    $worker->processItem(['action' => 'create']);
  }

  public function testProcessItemUnknownAction(): void {
    $api = $this->createMock(UnifiApiService::class);
    $logger = $this->createMock(LoggerChannelInterface::class);

    $logger->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('Unknown UniFi sync action'));

    $worker = $this->worker($api, $logger);
    $worker->processItem([
      'action' => 'nope',
      'email' => 'user@example.com',
    ]);
  }


  /**
   * The new 'deactivate' action name drains as well as the legacy 'delete'.
   *
   * reconcile() now queues 'deactivate'; 'delete' stays accepted so anything
   * an older release left in the queue still drains.
   */
  public function testProcessItemDeactivateActionName(): void {
    $api = $this->createMock(UnifiApiService::class);
    $logger = $this->createMock(LoggerChannelInterface::class);

    $api->expects($this->once())
      ->method('deactivateUser')
      ->with('u123')
      ->willReturn(UnifiApiResult::success(statusCode: 200));

    $logger->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('revoked via queue'));

    $worker = $this->worker($api, $logger);
    $worker->processItem([
      'action' => 'deactivate',
      'email' => 'remove@example.com',
      'user_id' => 'u123',
    ]);
  }


  /**
   * 'reactivate' restores a member the console holds as DEACTIVATED.
   */
  public function testProcessItemReactivate(): void {
    $api = $this->createMock(UnifiApiService::class);
    $logger = $this->createMock(LoggerChannelInterface::class);

    $api->expects($this->once())
      ->method('reactivateUser')
      ->with('u456')
      ->willReturn(UnifiApiResult::success(statusCode: 200));
    $api->expects($this->never())->method('createUser');

    $logger->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('restored via queue'));

    $worker = $this->worker($api, $logger);
    $worker->processItem([
      'action' => 'reactivate',
      'email' => 'sleeping@example.com',
      'user_id' => 'u456',
    ]);
  }

  /**
   * A refused reactivation (error envelope inside a 200) is logged, not hidden.
   */
  public function testProcessItemReactivateFailureLogsReason(): void {
    $api = $this->createMock(UnifiApiService::class);
    $logger = $this->createMock(LoggerChannelInterface::class);

    $api->expects($this->once())
      ->method('reactivateUser')
      ->willReturn(UnifiApiResult::failure(errorMessage: 'reactivateUser: CODE_SYSTEM_ERROR', statusCode: 200));

    $logger->expects($this->once())
      ->method('error')
      ->with($this->stringContains('Failed to restore'), $this->callback(fn(array $c) => str_contains((string) $c['@reason'], 'CODE_SYSTEM_ERROR')));

    $worker = $this->worker($api, $logger);
    $worker->processItem([
      'action' => 'reactivate',
      'email' => 'sleeping@example.com',
      'user_id' => 'u456',
    ]);
  }

  /**
   * A reactivate without a console id cannot be performed.
   */
  public function testProcessItemReactivateMissingId(): void {
    $api = $this->createMock(UnifiApiService::class);
    $logger = $this->createMock(LoggerChannelInterface::class);
    $api->expects($this->never())->method('reactivateUser');
    $logger->expects($this->once())->method('error')->with($this->stringContains('Missing user ID'));

    $worker = $this->worker($api, $logger);
    $worker->processItem(['action' => 'reactivate', 'email' => 'x@example.com']);
  }

}
