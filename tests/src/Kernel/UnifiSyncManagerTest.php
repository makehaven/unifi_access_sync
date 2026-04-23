<?php

namespace Drupal\Tests\unifi_access_sync\Kernel;

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\unifi_access_sync\Service\UnifiApiResult;
use Drupal\unifi_access_sync\Service\UnifiApiService;
use Drupal\unifi_access_sync\Service\UnifiSyncManager;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the UniFi Access Sync Manager service.
 */
#[RunTestsInSeparateProcesses]
#[Group('unifi_access_sync')]
class UnifiSyncManagerTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'taxonomy',
    'options',
    'unifi_access_sync',
  ];

  protected $apiMock;
  protected $queueMock;
  protected $queueFactoryMock;
  protected array $queuedItems = [];

  protected function setUp(): void {
    parent::setUp();

    UnifiSyncManager::resetCache();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('system', ['sequences']);
    $this->installConfig(['system', 'user', 'node', 'unifi_access_sync']);

    NodeType::create([
      'type' => 'badge_request',
      'name' => 'Badge Request',
    ])->save();

    Vocabulary::create([
      'vid' => 'badges',
      'name' => 'Badges',
    ])->save();

    $this->createField('node', 'badge_request', 'field_badge_requested', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('node', 'badge_request', 'field_badge_status', 'string');
    $this->createField('node', 'badge_request', 'field_member_to_badge', 'entity_reference', ['target_type' => 'user']);
    $this->createField('user', 'user', 'field_first_name', 'string');
    $this->createField('user', 'user', 'field_last_name', 'string');

    $this->apiMock = $this->getMockBuilder(UnifiApiService::class)
      ->disableOriginalConstructor()
      ->getMock();

    $this->queueMock = $this->createMock(QueueInterface::class);
    $this->queueMock->method('createItem')
      ->willReturnCallback(function (array $data): void {
        $this->queuedItems[] = $data;
      });

    $this->queueFactoryMock = $this->createMock(QueueFactory::class);
    $this->queueFactoryMock->method('get')
      ->with('unifi_access_sync_queue')
      ->willReturn($this->queueMock);

    $this->container->set('unifi_access_sync.api', $this->apiMock);
  }

  protected function getSyncManager(): UnifiSyncManager {
    return new UnifiSyncManager(
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->container->get('logger.channel.unifi_access_sync'),
      $this->apiMock,
      $this->queueFactoryMock
    );
  }

  protected function createField($entity_type, $bundle, $field_name, $type, $settings = []): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'type' => $type,
      'settings' => $settings,
    ])->save();
    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
    ])->save();
  }

  /**
   * reconcile() queues a create when a Drupal user is missing from a
   * non-empty UniFi tenant.
   */
  public function testReconcileQueuesCreateForMissingUser(): void {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();

    $this->container->get('config.factory')
      ->getEditable('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    $user = User::create([
      'name' => 'Test User',
      'mail' => 'test@example.com',
    ]);
    $user->save();

    Node::create([
      'type' => 'badge_request',
      'title' => 'Request for Test User',
      'field_badge_requested' => $door_term->id(),
      'field_badge_status' => 'active',
      'field_member_to_badge' => $user->id(),
    ])->save();

    UnifiSyncManager::resetCache();

    // Tenant has an unrelated user (no id), so the safety valve doesn't fire
    // on empty $have, and the delete loop has nothing to queue (skips rows
    // without an id). Only the create for test@example.com should land.
    $this->apiMock->method('listUsers')
      ->willReturn(UnifiApiResult::success(data: [
        ['email' => 'placeholder@example.com'],
      ]));

    $this->getSyncManager()->reconcile();

    $this->assertCount(1, $this->queuedItems);
    $this->assertSame('create', $this->queuedItems[0]['action']);
    $this->assertSame('test@example.com', $this->queuedItems[0]['email']);
    $this->assertSame('Test User', $this->queuedItems[0]['user_data']['display_name']);
  }

  /**
   * Safety valve: non-empty expectations + empty tenant view = no enqueue.
   *
   * This is the amplification guard. Without it, a transient listUsers
   * failure returning empty would cause every door-badged Drupal member
   * to be re-queued each hour.
   */
  public function testReconcileSafetyValveOnEmptyUnifi(): void {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();

    $this->container->get('config.factory')
      ->getEditable('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    $user = User::create(['name' => 'Test User', 'mail' => 'test@example.com']);
    $user->save();
    Node::create([
      'type' => 'badge_request',
      'title' => 'Request for Test User',
      'field_badge_requested' => $door_term->id(),
      'field_badge_status' => 'active',
      'field_member_to_badge' => $user->id(),
    ])->save();

    UnifiSyncManager::resetCache();

    // listUsers succeeds but returns zero users. With a non-empty $should,
    // reconcile should abort rather than enqueue creation for every member.
    $this->apiMock->expects($this->once())
      ->method('listUsers')
      ->willReturn(UnifiApiResult::success(data: []));

    $this->getSyncManager()->reconcile();

    $this->assertCount(0, $this->queuedItems, 'Safety valve must prevent mass enqueue on empty UniFi tenant.');
  }

  /**
   * reconcile() aborts cleanly when listUsers fails (API error, not empty).
   */
  public function testReconcileAbortsOnListUsersFailure(): void {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();

    $this->container->get('config.factory')
      ->getEditable('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    $user = User::create(['name' => 'Test User', 'mail' => 'test@example.com']);
    $user->save();
    Node::create([
      'type' => 'badge_request',
      'title' => 'Request for Test User',
      'field_badge_requested' => $door_term->id(),
      'field_badge_status' => 'active',
      'field_member_to_badge' => $user->id(),
    ])->save();

    UnifiSyncManager::resetCache();

    $this->apiMock->expects($this->once())
      ->method('listUsers')
      ->willReturn(UnifiApiResult::failure(
        errorMessage: 'simulated upstream failure',
        statusCode: 500,
      ));

    $this->getSyncManager()->reconcile();

    $this->assertCount(0, $this->queuedItems);
  }

  /**
   * A user without an email is skipped; no enqueue.
   */
  public function testReconcileSkipsUserWithoutEmail(): void {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();

    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    $user = User::create(['name' => 'No Email User']);
    $user->save();
    Node::create([
      'type' => 'badge_request',
      'title' => 'Request for No Email User',
      'field_badge_requested' => $door_term->id(),
      'field_badge_status' => 'active',
      'field_member_to_badge' => $user->id(),
    ])->save();

    UnifiSyncManager::resetCache();

    // $should ends up empty, $have is also empty; both loops no-op.
    $this->apiMock->expects($this->once())
      ->method('listUsers')
      ->willReturn(UnifiApiResult::success(data: []));

    $this->getSyncManager()->reconcile();
    $this->assertCount(0, $this->queuedItems);
  }

  /**
   * Reconcile is skipped when door_term_id is not configured.
   */
  public function testReconcileSkipsWhenNoDoorTermIsSet(): void {
    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', NULL)
      ->save();

    $this->apiMock->expects($this->never())->method('listUsers');

    $sync_manager = $this->getSyncManager();
    $sync_manager->reconcile();
    $this->assertSame([], $sync_manager->getShouldHaveAccessUserData());
    $this->assertCount(0, $this->queuedItems);
  }

  /**
   * reconcile() queues deletions for stale UniFi users even when
   * $should is empty (no amplification concern — deletes are bounded
   * by the current UniFi tenant size).
   */
  public function testReconcileRemoval(): void {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();

    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    $this->apiMock->expects($this->once())
      ->method('listUsers')
      ->willReturn(UnifiApiResult::success(data: [
        ['id' => 'unifi_id_123', 'email' => 'extra@example.com', 'name' => 'Extra User'],
      ]));

    $this->getSyncManager()->reconcile();

    $this->assertCount(1, $this->queuedItems);
    $this->assertSame('delete', $this->queuedItems[0]['action']);
    $this->assertSame('extra@example.com', $this->queuedItems[0]['email']);
    $this->assertSame('unifi_id_123', $this->queuedItems[0]['user_id']);
  }

  /**
   * getShouldHaveAccessUserData includes display-name fallback.
   */
  public function testGetShouldHaveAccessUserDataIncludesDisplayName(): void {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();

    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    $user = User::create([
      'name' => 'Display Name',
      'mail' => 'display@example.com',
    ]);
    $user->save();

    Node::create([
      'type' => 'badge_request',
      'title' => 'Request for Display Name',
      'field_badge_requested' => $door_term->id(),
      'field_badge_status' => 'active',
      'field_member_to_badge' => $user->id(),
    ])->save();

    UnifiSyncManager::resetCache();

    $user_data = $this->getSyncManager()->getShouldHaveAccessUserData();
    $this->assertArrayHasKey('display@example.com', $user_data);
    $this->assertIsArray($user_data['display@example.com']);
    $this->assertSame('Display Name', $user_data['display@example.com']['display_name']);
  }

  /**
   * Targeted add/remove behavior for a single email.
   */
  public function testSyncSingleByEmail(): void {
    $this->apiMock->expects($this->exactly(2))
      ->method('listUsers')
      ->willReturnOnConsecutiveCalls(
        UnifiApiResult::success(data: []),
        UnifiApiResult::success(data: [
          ['id' => 'u1', 'email' => 'remove@example.com'],
        ])
      );

    $sync_manager = $this->getSyncManager();

    $sync_manager->syncSingleByEmail('add@example.com', TRUE, [
      'first_name' => 'Add',
      'last_name' => 'User',
      'display_name' => 'Add User',
    ]);

    $this->assertCount(1, $this->queuedItems);
    $this->assertSame('create', $this->queuedItems[0]['action']);
    $this->assertSame('add@example.com', $this->queuedItems[0]['email']);

    UnifiSyncManager::resetCache();

    $sync_manager->syncSingleByEmail('remove@example.com', FALSE);

    $this->assertCount(2, $this->queuedItems);
    $this->assertSame('delete', $this->queuedItems[1]['action']);
    $this->assertSame('remove@example.com', $this->queuedItems[1]['email']);
    $this->assertSame('u1', $this->queuedItems[1]['user_id']);
  }

  /**
   * syncSingleByEmail aborts cleanly when listUsers fails.
   */
  public function testSyncSingleByEmailAbortsOnListUsersFailure(): void {
    UnifiSyncManager::resetCache();

    $this->apiMock->expects($this->once())
      ->method('listUsers')
      ->willReturn(UnifiApiResult::failure(
        errorMessage: 'upstream 500',
        statusCode: 500,
      ));

    $this->getSyncManager()->syncSingleByEmail('add@example.com', TRUE, [
      'display_name' => 'Add User',
    ]);

    $this->assertCount(0, $this->queuedItems);
  }

}
