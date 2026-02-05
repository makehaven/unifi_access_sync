<?php

namespace Drupal\Tests\unifi_access_sync\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\unifi_access_sync\Service\UnifiApiService;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;

/**
 * Tests the UniFi Access API service pagination.
 *
 * @group unifi_access_sync
 */
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

}
