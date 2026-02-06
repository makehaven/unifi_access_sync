<?php

namespace Drupal\Tests\unifi_access_sync\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\unifi_access_sync\Service\UnifiSyncManager;
use Drupal\unifi_access_sync\Service\UnifiApiService;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the UniFi Access Sync Manager service.
 *
 * @group unifi_access_sync
 */
#[RunTestsInSeparateProcesses]
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
   * The sync manager under test.
   *
   * @var \Drupal\unifi_access_sync\Service\UnifiSyncManager|null
   */
  protected ?UnifiSyncManager $syncManager = NULL;

  /**
   * Mock of the API service.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject|\Drupal\unifi_access_sync\Service\UnifiApiService
   */
  protected $apiMock;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Reset static cache to ensure clean state for each test.
    UnifiSyncManager::resetCache();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('system', ['sequences']);
    $this->installConfig(['system', 'user', 'node', 'unifi_access_sync']);

    // Create node type badge_request.
    NodeType::create([
      'type' => 'badge_request',
      'name' => 'Badge Request',
    ])->save();

    // Create vocabulary.
    Vocabulary::create([
      'vid' => 'badges',
      'name' => 'Badges',
    ])->save();

    // Create fields.
    $this->createField('node', 'badge_request', 'field_badge_requested', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('node', 'badge_request', 'field_badge_status', 'string');
    $this->createField('node', 'badge_request', 'field_member_to_badge', 'entity_reference', ['target_type' => 'user']);
    $this->createField('user', 'user', 'field_first_name', 'string');
    $this->createField('user', 'user', 'field_last_name', 'string');

    // Mock API service - inject into container.
    $this->apiMock = $this->getMockBuilder(UnifiApiService::class)
      ->disableOriginalConstructor()
      ->getMock();

    $this->container->set('unifi_access_sync.api', $this->apiMock);
    // Note: Don't get syncManager here - get it in each test after config is set,
    // because the container caches service instances with the config at creation time.
  }

  /**
   * Gets a fresh sync manager instance with current config.
   *
   * @return \Drupal\unifi_access_sync\Service\UnifiSyncManager
   *   The sync manager.
   */
  protected function getSyncManager(): UnifiSyncManager {
    // Create a new instance to pick up any config changes made in the test.
    return new UnifiSyncManager(
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->container->get('logger.channel.unifi_access_sync'),
      $this->apiMock
    );
  }

  /**
   * Helper to create a field.
   */
  protected function createField($entity_type, $bundle, $field_name, $type, $settings = []) {
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
   * Tests the reconciliation logic and verifies logging.
   */
  public function testReconcile() {
    $door_term = Term::create([
      'name' => 'Main Door',
      'vid' => 'badges',
    ]);
    $door_term->save();

    // Use getEditable via the config factory to properly invalidate cache.
    $this->container->get('config.factory')
      ->getEditable('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    // Create a user who should have access.
    $user = User::create([
      'name' => 'Test User',
      'mail' => 'test@example.com',
    ]);
    $user->save();

    // Create an active badge request for the user.
    // Note: This triggers hook_entity_insert which populates the static cache.
    $node = Node::create([
      'type' => 'badge_request',
      'title' => 'Request for Test User',
      'field_badge_requested' => $door_term->id(),
      'field_badge_status' => 'active',
      'field_member_to_badge' => $user->id(),
    ]);
    $node->save();

    // Reset cache after entity hooks have run.
    UnifiSyncManager::resetCache();

    // Scenario:
    // Drupal has test@example.com
    // UniFi is currently empty.
    // Expectation: test@example.com is created in UniFi.
    $this->apiMock->expects($this->once())
      ->method('listUsers')
      ->willReturn([]);

    $this->apiMock->expects($this->once())
      ->method('createUser')
      ->with($this->callback(function ($payload) {
        // New API uses nested profile structure.
        return isset($payload['profile']['email']) && $payload['profile']['email'] === 'test@example.com';
      }));

    $this->getSyncManager()->reconcile();
  }

  /**
   * Tests that users without an email address are skipped.
   */
  public function testReconcileSkipsUserWithoutEmail() {
    $door_term = Term::create([
      'name' => 'Main Door',
      'vid' => 'badges',
    ]);
    $door_term->save();

    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    // Create a user without an email.
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

    // Reset cache after entity hooks have run.
    UnifiSyncManager::resetCache();

    $this->apiMock->expects($this->once())
      ->method('listUsers')
      ->willReturn([]);

    $this->apiMock->expects($this->never())
      ->method('createUser');

    $this->getSyncManager()->reconcile();
  }

  /**
   * Tests that reconciliation is skipped if no door term ID is configured.
   */
  public function testReconcileSkipsWhenNoDoorTermIsSet() {
    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', NULL)
      ->save();

    $this->apiMock->expects($this->never())
      ->method('listUsers');

    $syncManager = $this->getSyncManager();
    $syncManager->reconcile();
    $this->assertSame([], $syncManager->getShouldHaveAccessUserData());
  }

  /**
   * Tests reconciliation when a user should be removed.
   */
  public function testReconcileRemoval() {
    $door_term = Term::create([
      'name' => 'Main Door',
      'vid' => 'badges',
    ]);
    $door_term->save();

    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    // Scenario:
    // Drupal has NO active users.
    // UniFi has extra@example.com.
    // Expectation: extra@example.com is deleted from UniFi.
    $this->apiMock->expects($this->once())
      ->method('listUsers')
      ->willReturn([
        ['id' => 'unifi_id_123', 'email' => 'extra@example.com', 'name' => 'Extra User'],
      ]);

    $this->apiMock->expects($this->once())
      ->method('deleteUser')
      ->with('unifi_id_123');

    $this->getSyncManager()->reconcile();
  }

  /**
   * Tests user data retrieval includes display name fallback.
   */
  public function testGetShouldHaveAccessUserDataIncludesDisplayName() {
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

    // Reset cache after entity hooks have run.
    UnifiSyncManager::resetCache();

    $userData = $this->getSyncManager()->getShouldHaveAccessUserData();
    $this->assertArrayHasKey('display@example.com', $userData);
    $this->assertIsArray($userData['display@example.com']);
    $this->assertSame('Display Name', $userData['display@example.com']['display_name']);
  }

  /**
   * Tests targeted add/remove behavior for a single email.
   */
  public function testSyncSingleByEmail() {
    $this->apiMock->expects($this->exactly(2))
      ->method('listUsers')
      ->willReturnOnConsecutiveCalls(
        [],
        [['id' => 'u1', 'email' => 'remove@example.com']]
      );

    $this->apiMock->expects($this->once())
      ->method('createUser')
      ->with($this->callback(function ($payload) {
        // New API uses nested profile structure.
        return isset($payload['profile']['email']) && $payload['profile']['email'] === 'add@example.com';
      }));

    $this->apiMock->expects($this->once())
      ->method('deleteUser')
      ->with('u1');

    $syncManager = $this->getSyncManager();

    // Third argument is now an array with user data.
    $syncManager->syncSingleByEmail('add@example.com', TRUE, [
      'first_name' => 'Add',
      'last_name' => 'User',
      'display_name' => 'Add User',
    ]);

    // Reset cache to simulate a new request for the removal test.
    UnifiSyncManager::resetCache();

    $syncManager->syncSingleByEmail('remove@example.com', FALSE);
  }

}
