<?php

namespace Drupal\Tests\unifi_access_sync\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\unifi_access_sync\Service\UnifiApiService;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the UniFi Access API service pagination.
 *
 * Tests the UniFi Access API service pagination.
 */
#[RunTestsInSeparateProcesses]
#[Group('unifi_access_sync')]
class UnifiApiServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'unifi_access_sync',
  ];

  /**
   * Tests pagination logic in listUsers.
   */
  public function testListUsersPagination() {
    $this->config('unifi_access_sync.settings')
      ->set('api_host', 'https://unifi.example.com')
      ->set('api_token', 'test-token')
      ->save();

    // Page 1 response: 50 users (full page)
    $page1_data = [];
    for ($i = 1; $i <= 50; $i++) {
      $page1_data[] = ['id' => "u$i", 'email' => "user$i@example.com"];
    }

    // Page 2 response: 10 users (partial page, indicates end)
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

    $users = $apiService->listUsers();

    $this->assertCount(60, $users);
    $this->assertEquals('user1@example.com', $users[0]['email']);
    $this->assertEquals('user60@example.com', $users[59]['email']);
  }

  /**
   * Tests Key module integration.
   */
  public function testKeyModuleIntegration() {
    // We need to enable the key module for this test.
    $this->enableModules(['key']);
    $this->installEntitySchema('key');

    $key_id = 'test_api_key';
    $key_value = 'key-from-module';

    // Mock KeyRepository.
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
    // Verify the token from the key module is used in the X-API-KEY header.
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
  public function testRequestOptions() {
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
   * Tests error handling in listUsers.
   */
  public function testListUsersErrorHandling() {
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

    $users = $apiService->listUsers();
    $this->assertSame([], $users);
  }

  /**
   * Tests createUser and deleteUser success and error handling.
   */
  public function testCreateAndDeleteUser() {
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
    $this->assertEquals('new_id', $result['id']);

    // Error create.
    $result = $apiService->createUser(['profile' => ['email' => 'error@example.com']]);
    $this->assertNull($result);

    // Success delete.
    $result = $apiService->deleteUser('new_id');
    $this->assertTrue($result);

    // Error delete.
    $result = $apiService->deleteUser('missing_id');
    $this->assertFalse($result);
  }

  /**
   * Ensures requests are skipped when configuration is missing.
   */
  public function testListUsersSkipsWhenNotConfigured() {
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');

    // No api_host or api_token set in config.
    $this->config('unifi_access_sync.settings')
      ->set('api_host', '')
      ->set('api_token', '')
      ->save();

    $apiService = new UnifiApiService(
      $client,
      $this->container->get('config.factory'),
      $this->container->get('logger.channel.unifi_access_sync')
    );

    $users = $apiService->listUsers();
    $this->assertSame([], $users);
    $this->assertNull($apiService->createUser(['profile' => ['email' => 'test@example.com']]));
    $this->assertFalse($apiService->deleteUser('abc123'));
  }

}
