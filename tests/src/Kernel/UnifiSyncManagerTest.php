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
use Drupal\user\Entity\Role;
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
   * The modules.
   *
   * @var array
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
   * The api mock.
   *
   * @var mixed
   */
  protected $apiMock;
  /**
   * The queue mock.
   *
   * @var mixed
   */
  protected $queueMock;
  /**
   * The queue factory mock.
   *
   * @var mixed
   */
  protected $queueFactoryMock;
  /**
   * The queued items.
   *
   * @var array
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

    // sync_enabled ships FALSE, so without this every test below would pass
    // for the wrong reason — reconcile() would early-return and queue nothing,
    // which is exactly what most of these assert. Tests that care about the
    // switch itself set it back to FALSE explicitly.
    $this->config('unifi_access_sync.settings')->set('sync_enabled', TRUE)->save();

    // Since 4300988 every write path also requires PANTHEON_ENVIRONMENT=live
    // (UnifiSyncManager::isLiveEnvironment()), so off-Pantheon — including
    // this test runner — reconcile() and syncSingleByEmail() return before
    // touching the queue. The API is mocked here, so pretending to be live is
    // safe; each test runs in its own process, so nothing leaks.
    putenv('PANTHEON_ENVIRONMENT=live');
    $_ENV['PANTHEON_ENVIRONMENT'] = 'live';

    // member_role ships as `member`: door access needs the badge AND a current
    // membership. The fixtures below create bare users to test everything
    // else, so the role check is switched off here and switched back on by
    // the tests that are about it.
    $this->config('unifi_access_sync.settings')->set('member_role', '')->save();
    Role::create(['id' => 'member', 'label' => 'Member'])->save();

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
   * Get sync manager.
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
   * Create field.
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
   * Reconcile() queues a create for a Drupal user missing from UniFi.
   */
  public function testReconcileQueuesCreateForMissingUser(): void {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();

    $this->container->get('config.factory')
      ->getEditable('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    $user = User::create([
      'status' => 1,
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

    $user = User::create(['status' => 1, 'name' => 'Test User', 'mail' => 'test@example.com']);
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
   * Reconcile() aborts cleanly when listUsers fails (API error, not empty).
   */
  public function testReconcileAbortsOnListUsersFailure(): void {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();

    $this->container->get('config.factory')
      ->getEditable('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    $user = User::create(['status' => 1, 'name' => 'Test User', 'mail' => 'test@example.com']);
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

    $user = User::create(['status' => 1, 'name' => 'No Email User']);
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
   * Reconcile() queues deletions for stale UniFi users once allowed.
   *
   * Even when $should is empty there is no amplification concern: deletes
   * are bounded by the current UniFi tenant size.
   */
  public function testReconcileRemoval(): void {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();

    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->set('allow_delete', TRUE)
      ->save();

    $this->apiMock->expects($this->once())
      ->method('listUsers')
      ->willReturn(UnifiApiResult::success(data: [
        ['id' => 'unifi_id_123', 'email' => 'extra@example.com', 'name' => 'Extra User'],
      ]));

    $this->getSyncManager()->reconcile();

    $this->assertCount(1, $this->queuedItems);
    $this->assertSame('deactivate', $this->queuedItems[0]['action']);
    $this->assertSame('extra@example.com', $this->queuedItems[0]['email']);
    $this->assertSame('unifi_id_123', $this->queuedItems[0]['user_id']);
  }

  /**
   * GetShouldHaveAccessUserData includes display-name fallback.
   */
  public function testGetShouldHaveAccessUserDataIncludesDisplayName(): void {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();

    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    $user = User::create([
      'status' => 1,
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
   * Deletions are only logged until allow_delete is switched on.
   */
  public function testReconcileRemovalGatedByDefault(): void {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();

    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();
    $this->assertFalse((bool) $this->config('unifi_access_sync.settings')->get('allow_delete'));

    $this->apiMock->expects($this->once())
      ->method('listUsers')
      ->willReturn(UnifiApiResult::success(data: [
        ['id' => 'unifi_id_123', 'email' => 'extra@example.com', 'name' => 'Extra User'],
      ]));

    $this->getSyncManager()->reconcile();

    $this->assertCount(0, $this->queuedItems, 'Nothing is queued while allow_delete is off.');
  }

  /**
   * Targeted add/remove behavior for a single email.
   */
  public function testSyncSingleByEmail(): void {
    $this->config('unifi_access_sync.settings')->set('allow_delete', TRUE)->save();
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
    $this->assertSame('deactivate', $this->queuedItems[1]['action']);
    $this->assertSame('remove@example.com', $this->queuedItems[1]['email']);
    $this->assertSame('u1', $this->queuedItems[1]['user_id']);
  }

  /**
   * SyncSingleByEmail aborts cleanly when listUsers fails.
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

  /**
   * The 2026-09-15 incident, reproduced: 23 present against a large roster.
   *
   * The old valve tested `empty($have)`. Live returned 23 users while Drupal
   * expected 3,309, so the valve never opened and every hourly cron re-queued
   * the whole roster — 340,234 log rows in 61 hours. A ratio test arrests it
   * on the first run. Scaled down here (40 expected, 5 present) because the
   * predicate is proportional, not absolute.
   */
  public function testReconcileValveFiresWhenTenantIsImplausiblySmall(): void {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();

    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    $expected_emails = [];
    for ($i = 1; $i <= 40; $i++) {
      $email = "member$i@example.com";
      $expected_emails[] = $email;
      $user = User::create(['status' => 1, 'name' => "Member $i", 'mail' => $email]);
      $user->save();
      Node::create([
        'type' => 'badge_request',
        'title' => "Request for Member $i",
        'field_badge_requested' => $door_term->id(),
        'field_badge_status' => 'active',
        'field_member_to_badge' => $user->id(),
      ])->save();
    }

    UnifiSyncManager::resetCache();

    // The console only knows about 5 of the 40 — well under the 50% floor.
    $present = [];
    for ($i = 1; $i <= 5; $i++) {
      $present[] = ['id' => "u$i", 'email' => "member$i@example.com"];
    }
    $this->apiMock->method('listUsers')
      ->willReturn(UnifiApiResult::success(data: $present));

    $this->getSyncManager()->reconcile();

    $this->assertCount(
      0,
      $this->queuedItems,
      'A tenant view holding far fewer users than expected must enqueue nothing.'
    );
  }

  /**
   * --force is the documented escape hatch for a genuinely empty console.
   *
   * Seeding a fresh or rebuilt console is a real operation and must remain
   * possible; it just has to be a deliberate human act rather than something
   * cron can stumble into.
   */
  public function testReconcileForceBypassesTheValve(): void {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();

    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    for ($i = 1; $i <= 10; $i++) {
      $user = User::create(['status' => 1, 'name' => "Member $i", 'mail' => "member$i@example.com"]);
      $user->save();
      Node::create([
        'type' => 'badge_request',
        'title' => "Request for Member $i",
        'field_badge_requested' => $door_term->id(),
        'field_badge_status' => 'active',
        'field_member_to_badge' => $user->id(),
      ])->save();
    }

    UnifiSyncManager::resetCache();

    $this->apiMock->method('listUsers')
      ->willReturn(UnifiApiResult::success(data: []));

    $this->getSyncManager()->reconcile(TRUE);

    $this->assertCount(10, $this->queuedItems, '--force must seed an empty console.');
    foreach ($this->queuedItems as $item) {
      $this->assertSame('create', $item['action']);
    }
  }

  /**
   * A tenant view at the floor is still acted on.
   *
   * The valve must not be so eager that ordinary growth trips it — half the
   * roster present is enough to believe the console is the right one.
   */
  public function testReconcileProceedsWhenTenantIsAtTheFloor(): void {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();

    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    for ($i = 1; $i <= 10; $i++) {
      $user = User::create(['status' => 1, 'name' => "Member $i", 'mail' => "member$i@example.com"]);
      $user->save();
      Node::create([
        'type' => 'badge_request',
        'title' => "Request for Member $i",
        'field_badge_requested' => $door_term->id(),
        'field_badge_status' => 'active',
        'field_member_to_badge' => $user->id(),
      ])->save();
    }

    UnifiSyncManager::resetCache();

    // Exactly half present: 5 of 10.
    $present = [];
    for ($i = 1; $i <= 5; $i++) {
      $present[] = ['id' => "u$i", 'email' => "member$i@example.com"];
    }
    $this->apiMock->method('listUsers')
      ->willReturn(UnifiApiResult::success(data: $present));

    $this->getSyncManager()->reconcile();

    $this->assertCount(5, $this->queuedItems, 'At the floor, the missing half should still be queued.');
  }

  /**
   * A user this module created is recognised on the next pass.
   *
   * API-created users come back with `email: ""` and the address in
   * `user_email`. The matcher used to read only `email`, so every user this
   * module created still looked missing on the next reconcile and was created
   * again — the loop could not have healed itself even once creates worked.
   */
  public function testReconcileMatchesUsersByUserEmail(): void {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();

    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    $user = User::create(['status' => 1, 'name' => 'Test User', 'mail' => 'test@example.com']);
    $user->save();
    Node::create([
      'type' => 'badge_request',
      'title' => 'Request for Test User',
      'field_badge_requested' => $door_term->id(),
      'field_badge_status' => 'active',
      'field_member_to_badge' => $user->id(),
    ])->save();

    UnifiSyncManager::resetCache();

    // Exactly what the console returns for a user this module created.
    $this->apiMock->method('listUsers')
      ->willReturn(UnifiApiResult::success(data: [
        [
          'id' => 'created_by_us',
          'email' => '',
          'user_email' => 'test@example.com',
          'first_name' => 'Test',
          'last_name' => 'User',
        ],
      ]));

    $this->getSyncManager()->reconcile();

    $this->assertCount(
      0,
      $this->queuedItems,
      'A user already present under user_email must not be created again.'
    );
  }

  /**
   * Address casing must not cause a duplicate create.
   *
   * Drupal stores whatever the member typed; the console echoes back its own
   * casing. Comparing them raw would make "Test@Example.com" look absent.
   */
  public function testReconcileMatchIsCaseInsensitive(): void {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();

    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    $user = User::create(['status' => 1, 'name' => 'Mixed Case', 'mail' => 'Mixed.Case@Example.com']);
    $user->save();
    Node::create([
      'type' => 'badge_request',
      'title' => 'Request for Mixed Case',
      'field_badge_requested' => $door_term->id(),
      'field_badge_status' => 'active',
      'field_member_to_badge' => $user->id(),
    ])->save();

    UnifiSyncManager::resetCache();

    $this->apiMock->method('listUsers')
      ->willReturn(UnifiApiResult::success(data: [
        ['id' => 'u1', 'user_email' => 'mixed.case@example.com'],
      ]));

    $this->getSyncManager()->reconcile();

    $this->assertCount(0, $this->queuedItems, 'Casing alone must not trigger a duplicate create.');
  }

  /**
   * The address sent to the API keeps the member's own casing.
   *
   * Matching is lowercased; what we transmit should not be.
   */
  public function testCreateCarriesTheMembersOwnAddressCasing(): void {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();

    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    $user = User::create(['status' => 1, 'name' => 'Mixed Case', 'mail' => 'Mixed.Case@Example.com']);
    $user->save();
    Node::create([
      'type' => 'badge_request',
      'title' => 'Request for Mixed Case',
      'field_badge_requested' => $door_term->id(),
      'field_badge_status' => 'active',
      'field_member_to_badge' => $user->id(),
    ])->save();

    UnifiSyncManager::resetCache();

    // Console holds an unrelated user, so the valve does not fire.
    $this->apiMock->method('listUsers')
      ->willReturn(UnifiApiResult::success(data: [
        ['id' => 'other', 'user_email' => 'someone.else@example.com'],
      ]));

    $this->getSyncManager()->reconcile();

    $this->assertCount(1, $this->queuedItems);
    $this->assertSame('Mixed.Case@Example.com', $this->queuedItems[0]['email']);
  }

  /**
   * The master switch stops reconcile before it touches the API at all.
   *
   * Distinct from the amplification valve: the valve asks whether the console
   * view can be trusted right now, this asks whether we want the module
   * talking to the door appliance at all. It ships off.
   */
  public function testReconcileDoesNothingWhileSwitchedOff(): void {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();

    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->set('sync_enabled', FALSE)
      ->save();

    $user = User::create(['status' => 1, 'name' => 'Test User', 'mail' => 'test@example.com']);
    $user->save();
    Node::create([
      'type' => 'badge_request',
      'title' => 'Request for Test User',
      'field_badge_requested' => $door_term->id(),
      'field_badge_status' => 'active',
      'field_member_to_badge' => $user->id(),
    ])->save();

    UnifiSyncManager::resetCache();

    // Not merely "queues nothing" — it must not even ask the console.
    $this->apiMock->expects($this->never())->method('listUsers');

    $this->getSyncManager()->reconcile();

    $this->assertCount(0, $this->queuedItems);
  }

  /**
   * --force must not override the master switch.
   *
   * --force bypasses the valve only. If someone has switched the sync off,
   * a Drush flag is not consent to start writing at the door.
   */
  public function testForceDoesNotOverrideTheMasterSwitch(): void {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();

    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->set('sync_enabled', FALSE)
      ->save();

    $user = User::create(['status' => 1, 'name' => 'Test User', 'mail' => 'test@example.com']);
    $user->save();
    Node::create([
      'type' => 'badge_request',
      'title' => 'Request for Test User',
      'field_badge_requested' => $door_term->id(),
      'field_badge_status' => 'active',
      'field_member_to_badge' => $user->id(),
    ])->save();

    UnifiSyncManager::resetCache();
    $this->apiMock->expects($this->never())->method('listUsers');

    $this->getSyncManager()->reconcile(TRUE);

    $this->assertCount(0, $this->queuedItems);
  }

  /**
   * The per-badge path is gated by the switch too.
   *
   * Missing this would leave a second way in: saving a badge_request would
   * still write to the console while the module is supposedly off.
   */
  public function testSyncSingleByEmailRespectsTheMasterSwitch(): void {
    $this->config('unifi_access_sync.settings')
      ->set('sync_enabled', FALSE)
      ->save();

    UnifiSyncManager::resetCache();
    $this->apiMock->expects($this->never())->method('listUsers');

    $this->getSyncManager()->syncSingleByEmail('someone@example.com', TRUE, []);

    $this->assertCount(0, $this->queuedItems);
  }

  /**
   * Status() answers the whole question read-only, for drush and the UI.
   */
  public function testStatusReportsTheValveWithoutActing(): void {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();

    $this->config('unifi_access_sync.settings')
      ->set('door_term_id', $door_term->id())
      ->save();

    for ($i = 1; $i <= 10; $i++) {
      $user = User::create(['status' => 1, 'name' => "Member $i", 'mail' => "member$i@example.com"]);
      $user->save();
      Node::create([
        'type' => 'badge_request',
        'title' => "Request for Member $i",
        'field_badge_requested' => $door_term->id(),
        'field_badge_status' => 'active',
        'field_member_to_badge' => $user->id(),
      ])->save();
    }

    UnifiSyncManager::resetCache();
    $this->apiMock->method('listUsers')
      ->willReturn(UnifiApiResult::success(data: [
        ['id' => 'u1', 'user_email' => 'member1@example.com'],
      ]));

    $status = $this->getSyncManager()->status();

    $this->assertTrue($status['enabled']);
    $this->assertTrue($status['reachable']);
    $this->assertSame(10, $status['expected']);
    $this->assertSame(1, $status['present']);
    $this->assertSame(5, $status['floor']);
    $this->assertSame(9, $status['missing']);
    $this->assertTrue($status['valve_would_block']);
    $this->assertCount(0, $this->queuedItems, 'status() must not enqueue anything.');
  }

  /**
   * Creates a door term, points config at it, and returns it.
   */
  protected function doorTerm(): Term {
    $door_term = Term::create(['name' => 'Main Door', 'vid' => 'badges']);
    $door_term->save();
    $this->config('unifi_access_sync.settings')->set('door_term_id', $door_term->id())->save();
    return $door_term;
  }

  /**
   * Creates a user with an active door badge.
   */
  protected function badgedUser(Term $door_term, string $email, array $values = []): User {
    $user = User::create($values + ['status' => 1, 'name' => $email, 'mail' => $email]);
    $user->save();
    Node::create([
      'type' => 'badge_request',
      'title' => "Request for $email",
      'field_badge_requested' => $door_term->id(),
      'field_badge_status' => 'active',
      'field_member_to_badge' => $user->id(),
    ])->save();
    return $user;
  }

  /**
   * The door badge is a qualification; the role is the membership.
   *
   * A badge alone named 3,314 accounts on 2026-09-21, ~2,470 of them former
   * members. Only badge + member role + not blocked should have access.
   */
  public function testShouldHaveAccessRequiresCurrentMembership(): void {
    $door_term = $this->doorTerm();
    $this->config('unifi_access_sync.settings')->set('member_role', 'member')->save();

    $this->badgedUser($door_term, 'current@example.com', ['roles' => ['member'], 'status' => 1]);
    $this->badgedUser($door_term, 'former@example.com', ['status' => 1]);
    $this->badgedUser($door_term, 'blocked@example.com', ['roles' => ['member'], 'status' => 0]);

    UnifiSyncManager::resetCache();
    $should = $this->getSyncManager()->getShouldHaveAccessUserData();

    $this->assertSame(['current@example.com'], array_keys($should));
  }

  /**
   * With member_role empty, the badge alone is enough (the pre-2026-09 rule).
   */
  public function testShouldHaveAccessWithoutRoleFilter(): void {
    $door_term = $this->doorTerm();
    $this->badgedUser($door_term, 'former@example.com');

    UnifiSyncManager::resetCache();
    $this->assertArrayHasKey('former@example.com', $this->getSyncManager()->getShouldHaveAccessUserData());
  }

  /**
   * A member the console holds as DEACTIVATED is restored, not re-created.
   *
   * On 2026-09-18 Pantheon dev mass-created ~1,224 real records that the
   * clean-up then deactivated; 372 were current members. A create would be
   * refused (CODE_ADMIN_EMAIL_EXIST) and they would stay locked out.
   */
  public function testReconcileQueuesReactivateForDeactivatedMember(): void {
    $door_term = $this->doorTerm();
    $this->badgedUser($door_term, 'sleeping@example.com');
    $this->badgedUser($door_term, 'awake@example.com');

    UnifiSyncManager::resetCache();
    $this->apiMock->method('listUsers')
      ->willReturn(UnifiApiResult::success(data: [
        ['id' => 'u_sleep', 'user_email' => 'sleeping@example.com', 'status' => 'DEACTIVATED'],
        ['id' => 'u_awake', 'user_email' => 'awake@example.com', 'status' => 'ACTIVE'],
      ]));

    // Two expected, one active: exactly at the 50% floor, so the valve lets
    // it through and the deactivated one is the only work.
    $this->getSyncManager()->reconcile();

    $this->assertCount(1, $this->queuedItems);
    $this->assertSame('reactivate', $this->queuedItems[0]['action']);
    $this->assertSame('sleeping@example.com', $this->queuedItems[0]['email']);
    $this->assertSame('u_sleep', $this->queuedItems[0]['user_id']);
  }

  /**
   * The valve counts ACTIVE records only.
   *
   * A console full of switched-off records is not a roster anyone can open
   * the door with, and must not be trusted as one.
   */
  public function testValveIgnoresDeactivatedRecords(): void {
    $door_term = $this->doorTerm();
    $present = [];
    for ($i = 1; $i <= 10; $i++) {
      $this->badgedUser($door_term, "member$i@example.com");
      $present[] = [
        'id' => "u$i",
        'user_email' => "member$i@example.com",
        'status' => $i <= 2 ? 'ACTIVE' : 'DEACTIVATED',
      ];
    }

    UnifiSyncManager::resetCache();
    $this->apiMock->method('listUsers')->willReturn(UnifiApiResult::success(data: $present));

    $this->getSyncManager()->reconcile();
    $this->assertCount(0, $this->queuedItems, 'Ten present but only two active is below the floor.');

    $this->getSyncManager()->reconcile(TRUE);
    $this->assertCount(8, $this->queuedItems, '--force restores the eight switched-off members.');
    $actions = array_unique(array_column($this->queuedItems, 'action'));
    $this->assertSame(['reactivate'], $actions);
  }

  /**
   * An extra that is already DEACTIVATED holds no access: nothing to revoke.
   *
   * Otherwise the 1,227 ex-member records would earn a "Would revoke" line
   * every hour, and with allow_delete on, a futile PUT each.
   */
  public function testReconcileLeavesDeactivatedExtrasAlone(): void {
    $door_term = $this->doorTerm();
    $this->badgedUser($door_term, 'member@example.com');
    $this->config('unifi_access_sync.settings')->set('allow_delete', TRUE)->save();

    UnifiSyncManager::resetCache();
    $this->apiMock->method('listUsers')
      ->willReturn(UnifiApiResult::success(data: [
        ['id' => 'u_member', 'user_email' => 'member@example.com', 'status' => 'ACTIVE'],
        ['id' => 'u_gone', 'user_email' => 'gone@example.com', 'status' => 'DEACTIVATED'],
        ['id' => 'u_stray', 'user_email' => 'stray@example.com', 'status' => 'ACTIVE'],
      ]));

    $this->getSyncManager()->reconcile();

    $this->assertCount(1, $this->queuedItems);
    $this->assertSame('deactivate', $this->queuedItems[0]['action']);
    $this->assertSame('stray@example.com', $this->queuedItems[0]['email']);
  }

  /**
   * Status() reports the active/deactivated split the seed decision needs.
   */
  public function testStatusSplitsActiveAndDeactivated(): void {
    $door_term = $this->doorTerm();
    $this->badgedUser($door_term, 'a@example.com');
    $this->badgedUser($door_term, 'b@example.com');
    $this->badgedUser($door_term, 'c@example.com');

    UnifiSyncManager::resetCache();
    $this->apiMock->method('listUsers')
      ->willReturn(UnifiApiResult::success(data: [
        ['id' => 'ua', 'user_email' => 'a@example.com', 'status' => 'ACTIVE'],
        ['id' => 'ub', 'user_email' => 'b@example.com', 'status' => 'DEACTIVATED'],
        ['id' => 'ux', 'user_email' => 'x@example.com', 'status' => 'ACTIVE'],
        ['id' => 'uy', 'user_email' => 'y@example.com', 'status' => 'DEACTIVATED'],
      ]));

    $s = $this->getSyncManager()->status();

    $this->assertSame(3, $s['expected']);
    $this->assertSame(2, $s['present'], 'ACTIVE records only');
    $this->assertSame(2, $s['present_deactivated']);
    $this->assertSame(1, $s['missing'], 'c has no record');
    $this->assertSame(1, $s['reactivate'], 'b is present but switched off');
    $this->assertSame(1, $s['extra'], 'x is active and not vouched for; y is already off');
    $this->assertFalse($s['valve_would_block'], '2 active of 3 expected meets the 50% floor');
  }

}
