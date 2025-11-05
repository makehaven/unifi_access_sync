<?php

namespace Drupal\unifi_access_sync\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;

class UnifiApiService {

  private ClientInterface $http;
  private $cfg;
  private LoggerChannelInterface $log;

  public function __construct(ClientInterface $http, ConfigFactoryInterface $config_factory, LoggerChannelInterface $log) {
    $this->http = $http;
    $this->cfg = $config_factory->get('unifi_access_sync.settings');
    $this->log = $log;
  }

  private function base(): string {
    return rtrim($this->cfg->get('api_host'), '/');
  }

  private function headers(): array {
    return [
      'Authorization' => 'Bearer ' . $this->cfg->get('api_token'),
      'Accept' => 'application/json',
      'Content-Type' => 'application/json',
    ];
  }

  private function verify(): bool {
    return (bool) $this->cfg->get('verify_ssl');
  }

  /** Users **/
  public function listUsers(): array {
    try {
      $res = $this->http->request('GET', $this->base() . '/api/v1/developer/users', [
        'headers' => $this->headers(),
        'verify' => $this->verify(),
        'timeout' => 20,
      ]);
      $json = json_decode($res->getBody()->getContents(), TRUE);
      return is_array($json) ? $json : [];
    } catch (\Throwable $e) {
      $this->log->error('UniFi listUsers error: @m', ['@m' => $e->getMessage()]);
      return [];
    }
  }

  public function createUser(array $payload): ?array {
    try {
      $res = $this->http->request('POST', $this->base() . '/api/v1/developer/users', [
        'headers' => $this->headers(),
        'verify' => $this->verify(),
        'json' => $payload,
        'timeout' => 20,
      ]);
      return json_decode($res->getBody()->getContents(), TRUE);
    } catch (\Throwable $e) {
      $this->log->error('UniFi createUser error: @m', ['@m' => $e->getMessage()]);
      return NULL;
    }
  }

  public function deleteUser(string $id): bool {
    try {
      $this->http->request('DELETE', $this->base() . '/api/v1/developer/users/' . $id, [
        'headers' => $this->headers(),
        'verify' => $this->verify(),
        'timeout' => 20,
      ]);
      return TRUE;
    } catch (\Throwable $e) {
      $this->log->error('UniFi deleteUser error: @m', ['@m' => $e->getMessage()]);
      return FALSE;
    }
  }

}
