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

/**
 * Tests the UniFi Access Sync Manager service.
 *
 * @group unifi_access_sync
 */
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
   */
  protected UnifiSyncManager $syncManager;

  /**
   * Mock of the API service.
   */
  protected $apiMock;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

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

    // Mock API service.
    $this->apiMock = $this->getMockBuilder(UnifiApiService::class)
      ->disableOriginalConstructor()
      ->getMock();

    $this->container->set('unifi_access_sync.api', $this->apiMock);
    $this->syncManager = $this->container->get('unifi_access_sync.sync_manager');
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
   * Tests the reconciliation logic.
   */
  public function testReconcile() {
    $door_term = Term::create([
      'name' => 'Main Door',
      'vid' => 'badges',
    ]);
    $door_term->save();

    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    // Create a user who should have access.
    $user = User::create([
      'name' => 'Test User',
      'mail' => 'test@example.com',
    ]);
    $user->save();

    // Create an active badge request for the user.
    $node = Node::create([
      'type' => 'badge_request',
      'title' => 'Request for Test User',
      'field_badge_requested' => $door_term->id(),
      'field_badge_status' => 'active',
      'field_member_to_badge' => $user->id(),
    ]);
    $node->save();

    // Scenario:
    // Drupal has test@example.com
    // UniFi is currently empty.
    // Expectation: test@example.com is created in UniFi.

    $this->apiMock->expects($this->once())
      ->method('listUsers')
      ->willReturn([]);

    $this->apiMock->expects($this->once())
      ->method('createUser')
      ->with($this->callback(function($payload) {
        return $payload['email'] === 'test@example.com';
      }));

    $this->syncManager->reconcile();
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
        ['id' => 'unifi_id_123', 'email' => 'extra@example.com', 'name' => 'Extra User']
      ]);

    $this->apiMock->expects($this->once())
      ->method('deleteUser')
      ->with('unifi_id_123');

    $this->syncManager->reconcile();
  }

}