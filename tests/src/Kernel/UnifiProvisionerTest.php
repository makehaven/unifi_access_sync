<?php

namespace Drupal\Tests\unifi_access_sync\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\unifi_access_sync\Service\UnifiApiResult;
use Drupal\unifi_access_sync\Service\UnifiApiService;
use Drupal\unifi_access_sync\Service\UnifiProvisioner;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Card, door-policy and photo provisioning for console records.
 *
 * What matters is which way each case goes: a card bound to someone else is
 * never moved, policies are added and never replaced, a picture set in the
 * console is never overwritten, and a failure costs one try a day.
 */
#[RunTestsInSeparateProcesses]
#[Group('unifi_access_sync')]
class UnifiProvisionerTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'unifi_access_sync'];

  /**
   * The API double.
   *
   * @var \Drupal\unifi_access_sync\Service\UnifiApiService&\PHPUnit\Framework\MockObject\MockObject
   */
  private $api;

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['unifi_access_sync']);
    $this->api = $this->createMock(UnifiApiService::class);
  }

  /**
   * A provisioner whose member lookups are fixed (no user/profile fields).
   */
  private function provisioner(string $serial = '04ABCDEF123456', ?array $photo = NULL): UnifiProvisioner {
    $p = $this->getMockBuilder(UnifiProvisioner::class)
      ->setConstructorArgs([
        $this->container->get('entity_type.manager'),
        $this->container->get('config.factory'),
        $this->container->get('logger.channel.unifi_access_sync'),
        $this->api,
        $this->container->get('state'),
      ])
      ->onlyMethods(['cardSerial', 'photoFile'])
      ->getMock();
    $p->method('cardSerial')->willReturn($serial);
    $p->method('photoFile')->willReturn($photo);
    return $p;
  }

  private function settings(array $values): void {
    $cfg = $this->config('unifi_access_sync.settings');
    foreach ($values as $k => $v) {
      $cfg->set($k, $v);
    }
    $cfg->save();
  }

  public function testEverythingOffDoesNothing(): void {
    $p = $this->provisioner();
    $this->assertFalse($p->enabled());
    $this->assertFalse($p->needsWork([], 7));
    $this->api->expects($this->never())->method($this->anything());
    $steps = $p->provision('u-7', 7, [], TRUE);
    foreach ($steps as $step) {
      $this->assertFalse($step['do']);
    }
  }

  public function testNewCardIsImportedThenBound(): void {
    $this->settings(['provision_nfc_cards' => TRUE]);
    $this->api->method('listNfcCards')->willReturn(UnifiApiResult::success([]));
    $this->api->expects($this->once())->method('importNfcCard')
      ->with('04ABCDEF123456', 'drupal-7')
      ->willReturn(UnifiApiResult::success('tok-1'));
    $this->api->expects($this->once())->method('assignNfcCard')
      ->with('u-7', 'tok-1')
      ->willReturn(UnifiApiResult::success(NULL));
    $steps = $this->provisioner()->provision('u-7', 7, [], TRUE);
    $this->assertTrue($steps['card']['ok']);
  }

  public function testCardBoundElsewhereIsNeverMoved(): void {
    $this->settings(['provision_nfc_cards' => TRUE]);
    $this->api->method('listNfcCards')->willReturn(UnifiApiResult::success([
      ['nfc_id' => '04ABCDEF123456', 'token' => 'tok-1', 'user_id' => 'someone-else'],
    ]));
    $this->api->expects($this->never())->method('importNfcCard');
    $this->api->expects($this->never())->method('assignNfcCard');
    $steps = $this->provisioner()->provision('u-7', 7, [], TRUE);
    $this->assertFalse($steps['card']['ok']);
    $this->assertStringContainsString('not moved', $steps['card']['detail']);
  }

  public function testCardAlreadyBoundHereIsLeftAlone(): void {
    $this->settings(['provision_nfc_cards' => TRUE]);
    $this->api->method('listNfcCards')->willReturn(UnifiApiResult::success([
      ['nfc_id' => '04ABCDEF123456', 'token' => 'tok-1', 'user_id' => 'u-7'],
    ]));
    $this->api->expects($this->never())->method('assignNfcCard');
    $steps = $this->provisioner()->provision('u-7', 7, [], TRUE);
    $this->assertFalse($steps['card']['do']);
  }

  public function testPoliciesAreAddedNeverReplaced(): void {
    $this->settings(['access_policy_ids' => ['front-door']]);
    $this->api->method('getUserPolicyIds')->willReturn(UnifiApiResult::success(['staff-extra']));
    $this->api->expects($this->once())->method('setUserPolicies')
      ->with('u-7', ['staff-extra', 'front-door'])
      ->willReturn(UnifiApiResult::success(NULL));
    $steps = $this->provisioner()->provision('u-7', 7, [], TRUE);
    $this->assertTrue($steps['door']['ok']);
    // Recorded, so reconcile stops asking.
    $this->assertFalse($this->provisioner()->needsWork(['nfc_cards' => [['id' => '1']]], 7, time() + UnifiProvisioner::RETRY_AFTER + 1));
  }

  public function testPolicyAlreadyHeldWritesNothing(): void {
    $this->settings(['access_policy_ids' => ['front-door']]);
    $this->api->method('getUserPolicyIds')->willReturn(UnifiApiResult::success(['front-door']));
    $this->api->expects($this->never())->method('setUserPolicies');
    $steps = $this->provisioner()->provision('u-7', 7, [], TRUE);
    $this->assertFalse($steps['door']['do']);
  }

  public function testPhotoNeverReplacesAConsolePicture(): void {
    $this->settings(['provision_avatars' => TRUE]);
    $this->api->expects($this->never())->method('uploadAvatar');
    $photo = ['path' => __FILE__, 'filename' => 'm.jpg', 'mime' => 'image/jpeg', 'size' => 10];
    $steps = $this->provisioner('', $photo)->provision('u-7', 7, ['avatar_relative_path' => '/avatars/x.png'], TRUE);
    $this->assertFalse($steps['photo']['do']);
    $this->assertStringContainsString('not replaced', $steps['photo']['detail']);
  }

  public function testPhotoUploadedWhenRecordHasNone(): void {
    $this->settings(['provision_avatars' => TRUE]);
    $photo = ['path' => __FILE__, 'filename' => 'member-7.jpg', 'mime' => 'image/jpeg', 'size' => 10];
    $this->api->expects($this->once())->method('uploadAvatar')
      ->with('u-7', $this->isString(), 'member-7.jpg', 'image/jpeg')
      ->willReturn(UnifiApiResult::success(NULL));
    $steps = $this->provisioner('', $photo)->provision('u-7', 7, [], TRUE);
    $this->assertTrue($steps['photo']['ok']);
  }

  public function testPlanOnlyWritesNothing(): void {
    $this->settings(['provision_nfc_cards' => TRUE, 'access_policy_ids' => ['front-door'], 'provision_avatars' => TRUE]);
    $this->api->method('listNfcCards')->willReturn(UnifiApiResult::success([]));
    $this->api->method('getUserPolicyIds')->willReturn(UnifiApiResult::success([]));
    foreach (['importNfcCard', 'assignNfcCard', 'setUserPolicies', 'uploadAvatar'] as $write) {
      $this->api->expects($this->never())->method($write);
    }
    $photo = ['path' => __FILE__, 'filename' => 'm.jpg', 'mime' => 'image/jpeg', 'size' => 10];
    $steps = $this->provisioner('04ABCDEF123456', $photo)->provision('u-7', 7, [], FALSE);
    $this->assertTrue($steps['card']['do']);
    $this->assertTrue($steps['door']['do']);
    $this->assertTrue($steps['photo']['do']);
  }

  public function testAFailureBacksOffForADay(): void {
    $this->settings(['provision_nfc_cards' => TRUE]);
    $this->api->method('listNfcCards')->willReturn(UnifiApiResult::failure('console down'));
    $p = $this->provisioner();
    $this->assertTrue($p->needsWork([], 7, time()));
    $p->provision('u-7', 7, [], TRUE);
    $this->assertFalse($p->needsWork([], 7, time() + 3600), 'tried an hour ago: skip');
    $this->assertTrue($p->needsWork([], 7, time() + UnifiProvisioner::RETRY_AFTER + 1), 'a day later: try again');
  }

  /**
   * The 2026-09-18 lesson: never grant anything to a record with an address.
   */
  public function testARecordWithAnEmailIsNeverTouched(): void {
    $this->settings(['provision_nfc_cards' => TRUE, 'access_policy_ids' => ['front-door'], 'provision_avatars' => TRUE]);
    $this->api->expects($this->never())->method($this->anything());
    $photo = ['path' => __FILE__, 'filename' => 'm.jpg', 'mime' => 'image/jpeg', 'size' => 10];
    $p = $this->provisioner('04ABCDEF123456', $photo);
    foreach ([['user_email' => 'a@b.org'], ['email' => 'a@b.org'], ['has_email' => TRUE], ['profile' => ['email' => 'a@b.org']]] as $raw) {
      $this->assertFalse($p->needsWork($raw, 7), json_encode($raw));
      $steps = $p->provision('u-7', 7, $raw, TRUE);
      foreach ($steps as $step) {
        $this->assertFalse($step['do']);
        $this->assertStringContainsString('email address', $step['detail']);
      }
    }
  }

  public function testNeedsWorkIgnoresRecordsThatHaveACard(): void {
    $this->settings(['provision_nfc_cards' => TRUE]);
    $this->assertFalse($this->provisioner()->needsWork(['nfc_cards' => [['id' => '100001']]], 7));
    $this->assertFalse($this->provisioner('')->needsWork([], 7), 'no serial on file: nothing to do');
  }

  /**
   * The HTTP shapes of the new calls, against a mock console.
   */
  public function testApiRequestShapes(): void {
    $this->config('unifi_access_sync.settings')
      ->set('api_host', 'https://unifi.example.com')
      ->set('api_token', 't')
      ->save();
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
      new Response(200, [], json_encode(['code' => 'SUCCESS', 'data' => [['nfc_id' => '04ABCDEF123456', 'alias' => 'drupal-7', 'token' => 'tok-9']]])),
      new Response(200, [], json_encode(['code' => 'SUCCESS', 'data' => [['nfc_id' => '04ABCDEF123456', 'alias' => 'drupal-7', 'token' => '']]])),
      new Response(200, [], json_encode(['code' => 'SUCCESS', 'msg' => 'success'])),
      new Response(200, [], json_encode(['code' => 'CODE_PARAMS_INVALID', 'msg' => 'nope'])),
      new Response(200, [], json_encode(['code' => 1, 'codeS' => 'SUCCESS', 'msg' => 'success', 'data' => ['url' => '/avatar/1.png']])),
      new Response(200, [], json_encode(['code' => 1, 'codeS' => 'CODE_PARAMS_INVALID', 'msg' => 'bad image'])),
    ]));
    $stack->push(Middleware::history($history));
    $api = new UnifiApiService(
      new Client(['handler' => $stack]),
      $this->container->get('config.factory'),
      $this->container->get('logger.channel.unifi_access_sync'),
    );

    $ok = $api->importNfcCard('04abcdef123456', 'drupal-7');
    $this->assertTrue($ok->ok);
    $this->assertSame('tok-9', $ok->data);
    $body = (string) $history[0]['request']->getBody();
    $this->assertStringContainsString('04ABCDEF123456,drupal-7', $body);
    $this->assertStringContainsString('multipart/form-data', $history[0]['request']->getHeaderLine('Content-Type'));

    $this->assertFalse($api->importNfcCard('04ABCDEF123456', 'drupal-7')->ok, 'an empty token means that row failed');

    $this->assertTrue($api->assignNfcCard('u-7', 'tok-9')->ok);
    $this->assertSame(['token' => 'tok-9', 'force_add' => FALSE], json_decode((string) $history[2]['request']->getBody(), TRUE));
    $this->assertSame('PUT', $history[2]['request']->getMethod());

    $this->assertFalse($api->setUserPolicies('u-7', ['front-door'])->ok, 'an error envelope inside HTTP 200 is a failure');
    $this->assertFalse($api->setUserPolicies('u-7', [])->ok, 'an empty list would strip every policy');
    $this->assertCount(4, $history, 'the empty policy list never reached the console');

    // The avatar endpoint's envelope: numeric code, word in codeS.
    $photo = $api->uploadAvatar('u-7', 'png-bytes', 'member-7.png', 'image/png');
    $this->assertTrue($photo->ok, 'code 1 + codeS SUCCESS is success');
    $this->assertSame(['url' => '/avatar/1.png'], $photo->data);
    $this->assertFalse($api->uploadAvatar('u-7', 'png-bytes', 'member-7.png', 'image/png')->ok, 'codeS carrying an error is a failure');
  }

}
