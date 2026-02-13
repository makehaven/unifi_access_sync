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

  /**
   * {@inheritdoc}
   */
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

  /**
   * Mock of the API service.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject|\Drupal\unifi_access_sync\Service\UnifiApiService
   */
  protected $apiMock;

  /**
   * Mock queue backend.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject|\Drupal\Core\Queue\QueueInterface
   */
  protected $queueMock;

  /**
   * Mock queue factory.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject|\Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactoryMock;

  /**
   * Captured queue items from createItem() calls.
   *
   * @var array<int, array>
   */
  protected array $queuedItems = [];

  /**
   * {@inheritdoc}
   */
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

  /**
   * Gets a fresh sync manager instance with current config.
   */
  protected function getSyncManager(): UnifiSyncManager {
    return new UnifiSyncManager(
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->container->get('logger.channel.unifi_access_sync'),
      $this->apiMock,
      $this->queueFactoryMock
    );
  }

  /**
   * Helper to create a field.
   */
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
   * Tests reconciliation queues a create action for missing users.
   */
  public function testReconcile(): void {
    $door_term = Term::create([
      'name' => 'Main Door',
      'vid' => 'badges',
    ]);
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

    $this->apiMock->expects($this->once())
      ->method('listUsers')
      ->willReturn([]);

    $this->getSyncManager()->reconcile();

    $this->assertCount(1, $this->queuedItems);
    $this->assertSame('create', $this->queuedItems[0]['action']);
    $this->assertSame('test@example.com', $this->queuedItems[0]['email']);
    $this->assertSame('Test User', $this->queuedItems[0]['user_data']['display_name']);
  }

  /**
   * Tests that users without an email address are skipped.
   */
  public function testReconcileSkipsUserWithoutEmail(): void {
    $door_term = Term::create([
      'name' => 'Main Door',
      'vid' => 'badges',
    ]);
    $door_term->save();

    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    $user = User::create([
      'name' => 'No Email User',
    ]);
    $user->save();

    Node::create([
      'type' => 'badge_request',
      'title' => 'Request for No Email User',
      'field_badge_requested' => $door_term->id(),
      'field_badge_status' => 'active',
      'field_member_to_badge' => $user->id(),
    ])->save();

    UnifiSyncManager::resetCache();

    $this->apiMock->expects($this->once())
      ->method('listUsers')
      ->willReturn([]);

    $this->getSyncManager()->reconcile();
    $this->assertCount(0, $this->queuedItems);
  }

  /**
   * Tests reconciliation is skipped if no door term ID is configured.
   */
  public function testReconcileSkipsWhenNoDoorTermIsSet(): void {
    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', NULL)
      ->save();

    $this->apiMock->expects($this->never())
      ->method('listUsers');

    $sync_manager = $this->getSyncManager();
    $sync_manager->reconcile();
    $this->assertSame([], $sync_manager->getShouldHaveAccessUserData());
    $this->assertCount(0, $this->queuedItems);
  }

  /**
   * Tests reconciliation queues deletions for stale UniFi users.
   */
  public function testReconcileRemoval(): void {
    $door_term = Term::create([
      'name' => 'Main Door',
      'vid' => 'badges',
    ]);
    $door_term->save();

    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    $this->apiMock->expects($this->once())
      ->method('listUsers')
      ->willReturn([
        ['id' => 'unifi_id_123', 'email' => 'extra@example.com', 'name' => 'Extra User'],
      ]);

    $this->getSyncManager()->reconcile();

    $this->assertCount(1, $this->queuedItems);
    $this->assertSame('delete', $this->queuedItems[0]['action']);
    $this->assertSame('extra@example.com', $this->queuedItems[0]['email']);
    $this->assertSame('unifi_id_123', $this->queuedItems[0]['user_id']);
  }

  /**
   * Tests user data retrieval includes display name fallback.
   */
  public function testGetShouldHaveAccessUserDataIncludesDisplayName(): void {
    $door_term = Term::create([
      'name' => 'Main Door',
      'vid' => 'badges',
    ]);
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
   * Tests targeted add/remove behavior for a single email.
   */
  public function testSyncSingleByEmail(): void {
    $this->apiMock->expects($this->exactly(2))
      ->method('listUsers')
      ->willReturnOnConsecutiveCalls(
        [],
        [['id' => 'u1', 'email' => 'remove@example.com']]
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

}
