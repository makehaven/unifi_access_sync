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
   * Tests createUser and deleteUser success and error handling.
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
    $result = $apiService->createUser(['profile' => ['email' => 'new@example.com']]);
    $this->assertTrue($result->ok);
    $this->assertEquals('new_id', $result->data['id']);

    // Error create.
    $result = $apiService->createUser(['profile' => ['email' => 'error@example.com']]);
    $this->assertFalse($result->ok);
    $this->assertSame(500, $result->statusCode);

    // Success delete.
    $result = $apiService->deleteUser('new_id');
    $this->assertTrue($result->ok);

    // Error delete.
    $result = $apiService->deleteUser('missing_id');
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
    $this->assertFalse($apiService->deleteUser('abc123')->ok);
  }

}
