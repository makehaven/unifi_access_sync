<?php

namespace Drupal\Tests\unifi_access_sync\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\unifi_access_sync\Service\UnifiSyncManager;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests module hooks for cron and entity changes.
 */
#[Group('unifi_access_sync')]
class UnifiAccessSyncHooksTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('system', ['sequences']);
    $this->installSchema('node', ['node_access']);
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
   * Tests cron throttle skips reconcile when called within an hour.
   */
  public function testCronThrottleSkipsWithinHour(): void {
    $mock = $this->getMockBuilder(UnifiSyncManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['reconcile'])
      ->getMock();
    $mock->expects($this->never())->method('reconcile');

    $this->container->set('unifi_access_sync.sync_manager', $mock);

    $now = \Drupal::time()->getRequestTime();
    \Drupal::state()->set('unifi_access_sync.last_cron', $now);

    \unifi_access_sync_cron();

    $this->assertSame($now, (int) \Drupal::state()->get('unifi_access_sync.last_cron'));
  }

  /**
   * Tests cron runs reconcile when threshold has passed.
   */
  public function testCronRunsAfterHour(): void {
    $mock = $this->getMockBuilder(UnifiSyncManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['reconcile'])
      ->getMock();
    $mock->expects($this->once())->method('reconcile');

    $this->container->set('unifi_access_sync.sync_manager', $mock);

    $now = \Drupal::time()->getRequestTime();
    $old = $now - 7200;
    \Drupal::state()->set('unifi_access_sync.last_cron', $old);

    \unifi_access_sync_cron();

    $updated = (int) \Drupal::state()->get('unifi_access_sync.last_cron');
    $this->assertGreaterThan($old, $updated);
  }

  /**
   * Tests entity insert queues targeted sync for active door badge requests.
   */
  public function testEntityInsertTriggersSyncSingle(): void {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();

    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    $user = User::create([
      'name' => 'Door User',
      'mail' => 'door@example.com',
      'field_first_name' => 'Door',
      'field_last_name' => 'User',
    ]);
    $user->save();

    $mock = $this->getMockBuilder(UnifiSyncManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['syncSingleByEmail'])
      ->getMock();

    $mock->expects($this->once())
      ->method('syncSingleByEmail')
      ->with(
        'door@example.com',
        TRUE,
        $this->callback(static fn(array $data): bool =>
          $data['first_name'] === 'Door' &&
          $data['last_name'] === 'User' &&
          $data['display_name'] === 'Door User'
        )
      );

    $this->container->set('unifi_access_sync.sync_manager', $mock);

    Node::create([
      'type' => 'badge_request',
      'title' => 'Door access request',
      'field_badge_requested' => $door_term->id(),
      'field_badge_status' => 'active',
      'field_member_to_badge' => $user->id(),
    ])->save();
  }

  /**
   * Tests entity update triggers sync only when it becomes a door request.
   */
  public function testEntityUpdateTriggersSyncWhenDoorTermMatches(): void {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();
    $other_term = Term::create(['name' => 'Other Badge', 'vid' => 'badges']);
    $other_term->save();

    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    $user = User::create([
      'name' => 'Update User',
      'mail' => 'update@example.com',
      'field_first_name' => 'Update',
      'field_last_name' => 'User',
    ]);
    $user->save();

    $mock = $this->getMockBuilder(UnifiSyncManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['syncSingleByEmail'])
      ->getMock();

    // Insert on non-door term should not call. Update to door term should call once.
    $mock->expects($this->once())
      ->method('syncSingleByEmail')
      ->with(
        'update@example.com',
        TRUE,
        $this->arrayHasKey('display_name')
      );

    $this->container->set('unifi_access_sync.sync_manager', $mock);

    $node = Node::create([
      'type' => 'badge_request',
      'title' => 'Initial non-door request',
      'field_badge_requested' => $other_term->id(),
      'field_badge_status' => 'active',
      'field_member_to_badge' => $user->id(),
    ]);
    $node->save();

    $node->set('field_badge_requested', $door_term->id());
    $node->save();
  }

}
