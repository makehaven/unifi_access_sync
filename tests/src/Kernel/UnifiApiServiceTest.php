<?php

namespace Drupal\Tests\unifi_access_sync\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\unifi_access_sync\Service\UnifiApiResult;
use Drupal\unifi_access_sync\Service\UnifiApiService;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the UniFi Access API service.
 *
 * API methods all return UnifiApiResult (success carries ->data, failure
 * carries ->statusCode / ->errorMessage / ->responseBody). These tests
 * assert on both the success and failure shapes.
 */
#[RunTestsInSeparateProcesses]
#[Group('unifi_access_sync')]
class UnifiApiServiceTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'unifi_access_sync',
  ];

  /**
   * Tests pagination logic in listUsers.
   */
  public function testListUsersPagination(): void {
    $this->config('unifi_access_sync.settings')
      ->set('api_host', 'https://unifi.example.com')
      ->set('api_token', 'test-token')
      ->save();

    $page1_data = [];
    for ($i = 1; $i <= 50; $i++) {
      $page1_data[] = ['id' => "u$i", 'email' => "user$i@example.com"];
    }
    $page2_data = [];
    for ($i = 51; $i <= 60; $i++) {
      $page2_data[] = ['id' => "u$i", 'email' => "user$i@example.com"];
    }

    $mock = new MockHandler([
      new Response(200, [], json_encode(['data' => $page1_data])),
      new Response(200, [], json_encode(['data' => $page2_data])),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $httpClient = new Client(['handler' => $handlerStack]);

    $apiService = new UnifiApiService(
      $httpClient,
      $this->container->get('config.factory'),
      $this->container->get('logger.channel.unifi_access_sync')
    );

    $result = $apiService->listUsers();

    $this->assertInstanceOf(UnifiApiResult::class, $result);
    $this->assertTrue($result->ok);
    $this->assertCount(60, $result->data);
    $this->assertEquals('user1@example.com', $result->data[0]['email']);
    $this->assertEquals('user60@example.com', $result->data[59]['email']);
  }

  /**
   * Tests Key module integration.
   */
  public function testKeyModuleIntegration(): void {
    $this->enableModules(['key']);
    $this->installEntitySchema('key');

    $key_id = 'test_api_key';
    $key_value = 'key-from-module';

    $keyMock = $this->getMockBuilder('Drupal\key\Entity\Key')
      ->disableOriginalConstructor()
      ->getMock();
    $keyMock->method('getKeyValue')->willReturn($key_value);

    $keyRepoMock = $this->getMockBuilder('Drupal\key\KeyRepository')
      ->disableOriginalConstructor()
      ->getMock();
    $keyRepoMock->method('getKey')->with($key_id)->willReturn($keyMock);

    $this->config('unifi_access_sync.settings')
      ->set('api_host', 'https://unifi.example.com')
      ->set('use_key_module', TRUE)
      ->set('api_key_id', $key_id)
      ->save();

    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->with('GET', $this->anything(), $this->callback(function ($options) use ($key_value) {
        return $options['headers']['X-API-KEY'] === $key_value;
      }))
      ->willReturn(new Response(200, [], json_encode(['data' => []])));

    $apiService = new UnifiApiService(
      $client,
      $this->container->get('config.factory'),
      $this->container->get('logger.channel.unifi_access_sync'),
      $keyRepoMock
    );

    $apiService->listUsers();
  }

  /**
   * Tests SSL verification and timeout options.
   */
  public function testRequestOptions(): void {
    $this->config('unifi_access_sync.settings')
      ->set('api_host', 'https://unifi.example.com')
      ->set('api_token', 'test-token')
      ->set('verify_ssl', FALSE)
      ->save();

    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->with('GET', $this->anything(), $this->callback(function ($options) {
        return $options['verify'] === FALSE && $options['timeout'] === 20;
      }))
      ->willReturn(new Response(200, [], json_encode(['data' => []])));

    $apiService = new UnifiApiService(
      $client,
      $this->container->get('config.factory'),
      $this->container->get('logger.channel.unifi_access_sync')
    );

    $apiService->listUsers();
  }

  /**
   * Tests that a non-2xx response surfaces as a failure result with detail.
   */
  public function testListUsersErrorHandling(): void {
    $this->config('unifi_access_sync.settings')
      ->set('api_host', 'https://unifi.example.com')
      ->set('api_token', 'test-token')
      ->save();

    $mock = new MockHandler([
      new Response(500, [], 'Internal Server Error'),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $httpClient = new Client(['handler' => $handlerStack]);

    $apiService = new UnifiApiService(
      $httpClient,
      $this->container->get('config.factory'),
      $this->container->get('logger.channel.unifi_access_sync')
    );

    $result = $apiService->listUsers();
    $this->assertFalse($result->ok);
    $this->assertSame(500, $result->statusCode);
    $this->assertSame('Internal Server Error', $result->responseBody);
  }

  /**
   * Tests createUser and deactivateUser success and error handling.
   */
  public function testCreateAndDeleteUser(): void {
    $this->config('unifi_access_sync.settings')
      ->set('api_host', 'https://unifi.example.com')
      ->set('api_token', 'test-token')
      ->save();

    $mock = new MockHandler([
      new Response(201, [], json_encode(['id' => 'new_id', 'email' => 'new@example.com'])),
      new Response(500, [], 'Error'),
      new Response(204, [], ''),
      new Response(404, [], 'Not Found'),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $httpClient = new Client(['handler' => $handlerStack]);

    $apiService = new UnifiApiService(
      $httpClient,
      $this->container->get('config.factory'),
      $this->container->get('logger.channel.unifi_access_sync')
    );

    // Success create.
    $result = $apiService->createUser(['user_email' => 'new@example.com']);
    $this->assertTrue($result->ok);
    $this->assertEquals('new_id', $result->data['id']);

    // Error create.
    $result = $apiService->createUser(['user_email' => 'error@example.com']);
    $this->assertFalse($result->ok);
    $this->assertSame(500, $result->statusCode);

    // Success delete.
    $result = $apiService->deactivateUser('new_id');
    $this->assertTrue($result->ok);

    // Error delete.
    $result = $apiService->deactivateUser('missing_id');
    $this->assertFalse($result->ok);
    $this->assertSame(404, $result->statusCode);
    $this->assertSame('Not Found', $result->responseBody);
  }

  /**
   * Missing host/token short-circuits all three calls with failure results.
   */
  public function testListUsersSkipsWhenNotConfigured(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');

    $this->config('unifi_access_sync.settings')
      ->set('api_host', '')
      ->set('api_token', '')
      ->save();

    $apiService = new UnifiApiService(
      $client,
      $this->container->get('config.factory'),
      $this->container->get('logger.channel.unifi_access_sync')
    );

    $this->assertFalse($apiService->listUsers()->ok);
    $this->assertFalse($apiService->createUser(['profile' => ['email' => 'test@example.com']])->ok);
    $this->assertFalse($apiService->deactivateUser('abc123')->ok);
  }


  /**
   * A 2xx carrying an error envelope is a failure, not a success.
   *
   * This is the exact response live returned 168,763 times between
   * 2026-09-15 and 09-17 while logging "created successfully": HTTP 200 with
   * {"code":"CODE_SYSTEM_ERROR","msg":"Server system error."}. Status-only
   * success checking is what made a total failure look like a working sync.
   */
  public function testCreateUserRejectsErrorEnvelopeInsideHttp200(): void {
    $this->config('unifi_access_sync.settings')
      ->set('api_host', 'https://unifi.example.com')
      ->set('api_token', 'test-token')
      ->save();

    $mock = new MockHandler([
      new Response(200, [], json_encode([
        'code' => 'CODE_SYSTEM_ERROR',
        'msg' => 'Server system error.',
      ])),
    ]);
    $apiService = new UnifiApiService(
      new Client(['handler' => HandlerStack::create($mock)]),
      $this->container->get('config.factory'),
      $this->container->get('logger.channel.unifi_access_sync')
    );

    $result = $apiService->createUser(['email' => 'nope@example.com']);

    $this->assertFalse($result->ok, 'HTTP 200 with an error code must not count as success.');
    $this->assertSame(200, $result->statusCode);
    $this->assertStringContainsString('CODE_SYSTEM_ERROR', $result->describe());
    $this->assertStringContainsString('Server system error.', $result->describe());
  }

  /**
   * The envelope's `code` is an integer on some errors and a string on others.
   *
   * Live returned {"code":404,"codeS":"CODE_NOT_FOUND",...} on 2026-09-14, so
   * the check has to catch a numeric code as well as a symbolic one.
   */
  public function testEnvelopeRejectsNumericErrorCode(): void {
    $this->config('unifi_access_sync.settings')
      ->set('api_host', 'https://unifi.example.com')
      ->set('api_token', 'test-token')
      ->save();

    $mock = new MockHandler([
      new Response(200, [], json_encode([
        'code' => 404,
        'codeS' => 'CODE_NOT_FOUND',
        'msg' => 'The API was not found.',
      ])),
    ]);
    $apiService = new UnifiApiService(
      new Client(['handler' => HandlerStack::create($mock)]),
      $this->container->get('config.factory'),
      $this->container->get('logger.channel.unifi_access_sync')
    );

    $result = $apiService->createUser(['email' => 'nope@example.com']);

    $this->assertFalse($result->ok);
    $this->assertStringContainsString('The API was not found.', $result->describe());
  }

  /**
   * A refused delete must not report success.
   *
   * This is the direction that matters: a silently-failed delete leaves a
   * revoked member present in the console with their door access intact.
   */
  public function testDeleteUserRejectsErrorEnvelopeInsideHttp200(): void {
    $this->config('unifi_access_sync.settings')
      ->set('api_host', 'https://unifi.example.com')
      ->set('api_token', 'test-token')
      ->save();

    $mock = new MockHandler([
      new Response(200, [], json_encode([
        'code' => 'CODE_OPERATION_FORBIDDEN',
        'msg' => 'Not allowed.',
      ])),
    ]);
    $apiService = new UnifiApiService(
      new Client(['handler' => HandlerStack::create($mock)]),
      $this->container->get('config.factory'),
      $this->container->get('logger.channel.unifi_access_sync')
    );

    $result = $apiService->deactivateUser('some_id');

    $this->assertFalse($result->ok, 'A refused delete must never report success.');
    $this->assertStringContainsString('CODE_OPERATION_FORBIDDEN', $result->describe());
  }

  /**
   * An explicit SUCCESS envelope unwraps `data` and still counts as success.
   */
  public function testEnvelopeUnwrapsDataOnSuccessCode(): void {
    $this->config('unifi_access_sync.settings')
      ->set('api_host', 'https://unifi.example.com')
      ->set('api_token', 'test-token')
      ->save();

    $mock = new MockHandler([
      new Response(200, [], json_encode([
        'code' => 'SUCCESS',
        'msg' => 'success',
        'data' => ['id' => 'created_id', 'email' => 'yes@example.com'],
      ])),
    ]);
    $apiService = new UnifiApiService(
      new Client(['handler' => HandlerStack::create($mock)]),
      $this->container->get('config.factory'),
      $this->container->get('logger.channel.unifi_access_sync')
    );

    $result = $apiService->createUser(['email' => 'yes@example.com']);

    $this->assertTrue($result->ok);
    $this->assertSame('created_id', $result->data['id']);
  }

  /**
   * listUsers must surface an error envelope rather than read it as "empty".
   *
   * An error envelope decoded as an empty user list is worse than a failure:
   * reconcile() would treat it as a legitimately empty console.
   */
  public function testListUsersRejectsErrorEnvelopeInsideHttp200(): void {
    $this->config('unifi_access_sync.settings')
      ->set('api_host', 'https://unifi.example.com')
      ->set('api_token', 'test-token')
      ->save();

    $mock = new MockHandler([
      new Response(200, [], json_encode([
        'code' => 'CODE_AUTH_FAILED',
        'msg' => 'Unauthorized.',
      ])),
    ]);
    $apiService = new UnifiApiService(
      new Client(['handler' => HandlerStack::create($mock)]),
      $this->container->get('config.factory'),
      $this->container->get('logger.channel.unifi_access_sync')
    );

    $result = $apiService->listUsers();

    $this->assertFalse($result->ok, 'An error envelope must not look like an empty tenant.');
    $this->assertStringContainsString('CODE_AUTH_FAILED', $result->describe());
  }

  /**
   * The create payload is flat, matching what listUsers returns.
   *
   * Established by probing the live console on 2026-09-17: nested returned
   * CODE_SYSTEM_ERROR, flat with `email` returned CODE_PARAMS_INVALID, and
   * flat with `user_email` returned SUCCESS.
   */
  public function testUserPayloadIsFlat(): void {
    $apiService = new UnifiApiService(
      new Client(),
      $this->container->get('config.factory'),
      $this->container->get('logger.channel.unifi_access_sync')
    );

    $payload = $apiService->userPayloadForData('someone@example.com', [
      'first_name' => 'Some',
      'last_name' => 'One',
      'display_name' => 'Some One',
    ]);

    $this->assertArrayNotHasKey('profile', $payload, 'Payload must not nest under profile.');
    $this->assertArrayNotHasKey('email', $payload, '`email` is read-only on create; the address goes in user_email.');
    $this->assertSame('someone@example.com', $payload['user_email']);
    $this->assertSame('Some', $payload['first_name']);
    $this->assertSame('One', $payload['last_name']);
  }

  /**
   * A 2xx with an empty body (204 No Content) is still a success.
   */
  public function testEmptyBodyOnTwoXxIsSuccess(): void {
    $this->config('unifi_access_sync.settings')
      ->set('api_host', 'https://unifi.example.com')
      ->set('api_token', 'test-token')
      ->save();

    $mock = new MockHandler([new Response(204, [], '')]);
    $apiService = new UnifiApiService(
      new Client(['handler' => HandlerStack::create($mock)]),
      $this->container->get('config.factory'),
      $this->container->get('logger.channel.unifi_access_sync')
    );

    $this->assertTrue($apiService->deactivateUser('some_id')->ok);
  }

}
