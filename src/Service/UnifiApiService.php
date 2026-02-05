<?php

namespace Drupal\unifi_access_sync\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;
use Drupal\key\KeyRepositoryInterface;

class UnifiApiService {

  private ClientInterface $http;
  private $cfg;
  private LoggerChannelInterface $log;
  private ?KeyRepositoryInterface $keyRepo;

  public function __construct(ClientInterface $http, ConfigFactoryInterface $config_factory, LoggerChannelInterface $log, ?KeyRepositoryInterface $key_repo = NULL) {
    $this->http = $http;
    $this->cfg = $config_factory->get('unifi_access_sync.settings');
    $this->log = $log;
    $this->keyRepo = $key_repo;
  }

  private function base(): string {
    return rtrim($this->cfg->get('api_host'), '/');
  }

  private function getToken(): string {
    if ($this->cfg->get('use_key_module') && $this->keyRepo) {
      $key_id = $this->cfg->get('api_key_id');
      if ($key_id) {
        $key = $this->keyRepo->getKey($key_id);
        if ($key) {
          return $key->getKeyValue();
        }
      }
    }
    return (string) $this->cfg->get('api_token');
  }

  private function headers(): array {
    return [
      'Authorization' => 'Bearer ' . $this->getToken(),
      'Accept' => 'application/json',
      'Content-Type' => 'application/json',
    ];
  }

  private function verify(): bool {
    return (bool) $this->cfg->get('verify_ssl');
  }

  /** Users **/
  public function listUsers(): array {
    $all_users = [];
    $page = 1;
    $pageSize = 50;

    try {
      do {
        $res = $this->http->request('GET', $this->base() . '/api/v1/developer/users', [
          'headers' => $this->headers(),
          'verify' => $this->verify(),
          'query' => [
            'page_num' => $page,
            'page_size' => $pageSize,
          ],
          'timeout' => 20,
        ]);
        $json = json_decode($res->getBody()->getContents(), TRUE);
        
        // Some UniFi APIs return results in a 'data' key, others as a top-level array.
        // Based on common patterns, it often has { data: [...], total: X }
        $users = [];
        if (isset($json['data']) && is_array($json['data'])) {
          $users = $json['data'];
        } elseif (is_array($json)) {
          // If it returned a top-level array, it might not be paginated the way we expect,
          // or we reached the end.
          $users = $json;
        }

        if (empty($users)) {
          break;
        }

        $all_users = array_merge($all_users, $users);
        
        // If we got fewer results than requested, we likely reached the end.
        if (count($users) < $pageSize) {
          break;
        }
        
        $page++;
      } while (TRUE);

      return $all_users;
    } catch (\Throwable $e) {
      $this->log->error('UniFi listUsers error: @m', ['@m' => $e->getMessage()]);
      return $all_users; // Return what we have so far
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
