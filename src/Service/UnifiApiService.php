<?php

namespace Drupal\unifi_access_sync\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;
use Drupal\key\KeyRepositoryInterface;

/**
 * HTTP client for the UniFi Access Developer API.
 */
class UnifiApiService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  private ClientInterface $http;

  /**
   * The module configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  private $cfg;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private LoggerChannelInterface $log;

  /**
   * The key repository service.
   *
   * @var \Drupal\key\KeyRepositoryInterface|null
   */
  private ?KeyRepositoryInterface $keyRepo;

  public function __construct(ClientInterface $http, ConfigFactoryInterface $config_factory, LoggerChannelInterface $log, ?KeyRepositoryInterface $key_repo = NULL) {
    $this->http = $http;
    $this->cfg = $config_factory->get('unifi_access_sync.settings');
    $this->log = $log;
    $this->keyRepo = $key_repo;
  }

  /**
   * Returns the base URL for the UniFi API.
   */
  private function base(): string {
    return rtrim($this->cfg->get('api_host'), '/');
  }

  /**
   * Retrieves the API token from config or Key module.
   */
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

  /**
   * Returns HTTP headers for API requests.
   */
  private function headers(): array {
    // Note: The UniFi Access local console "Integrations" tab specifies
    // X-API-KEY instead of the standard Bearer token.
    return [
      'X-API-KEY' => $this->getToken(),
      'Accept' => 'application/json',
      'Content-Type' => 'application/json',
    ];
  }

  /**
   * Returns the SSL verification setting.
   */
  private function verify(): bool {
    return (bool) $this->cfg->get('verify_ssl');
  }

  /**
   * Checks if the API is configured with host and token.
   */
  private function isConfigured(): bool {
    $host = trim((string) $this->cfg->get('api_host'));
    $token = trim((string) $this->getToken());
    return $host !== '' && $token !== '';
  }

  /**
   * Logs a warning when configuration is missing.
   */
  private function logMissingConfig(): void {
    $this->log->warning('UniFi API not configured: missing api_host or token.');
  }

  /**
   * Truncates response text before writing to logs.
   */
  private function trimForLog(string $value): string {
    $max = 500;
    if (strlen($value) <= $max) {
      return $value;
    }
    return substr($value, 0, $max) . '...';
  }

  /**
   * Lists all users from UniFi Access with pagination.
   *
   * @return array
   *   An array of user data from UniFi Access.
   */
  public function listUsers(): array {
    $all_users = [];
    $page = 1;
    $pageSize = 50;

    if (!$this->isConfigured()) {
      $this->logMissingConfig();
      return $all_users;
    }

    try {
      do {
        // Path adjusted to /proxy/access/integration/... as per console "Integrations" tab.
        $res = $this->http->request('GET', $this->base() . '/proxy/access/integration/v1/developer/users', [
          'headers' => $this->headers(),
          'verify' => $this->verify(),
          'query' => [
            'page_num' => $page,
            'page_size' => $pageSize,
          ],
          'timeout' => 20,
        ]);

        $statusCode = $res->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
          $this->log->error('UniFi listUsers API returned non-2xx status code @code. Response: @body', [
            '@code' => $statusCode,
            '@body' => $this->trimForLog((string) $res->getBody()),
          ]);
          return []; // Return empty array on error.
        }

        $json = json_decode($res->getBody()->getContents(), TRUE);

        // Some UniFi APIs return results in a 'data' key, others as a top-level array.
        // Based on common patterns, it often has { data: [...], total: X }.
        $users = [];
        if (isset($json['data']) && is_array($json['data'])) {
          $users = $json['data'];
        }
        elseif (is_array($json)) {
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
    }
    catch (\Throwable $e) {
      $this->log->error('UniFi listUsers error: @m', ['@m' => $e->getMessage()]);
      // Return what we have so far.
      return $all_users;
    }
  }

  /**
   * Creates a user in UniFi Access.
   *
   * @param array $payload
   *   The user data to send to the API.
   *
   * @return array|null
   *   The created user data, or NULL on failure.
   */
  public function createUser(array $payload): ?array {
    if (!$this->isConfigured()) {
      $this->logMissingConfig();
      return NULL;
    }

    try {
      // Path adjusted to /proxy/access/integration/... as per console "Integrations" tab.
      $res = $this->http->request('POST', $this->base() . '/proxy/access/integration/v1/developer/users', [
        'headers' => $this->headers(),
        'verify' => $this->verify(),
        'json' => $payload,
        'timeout' => 20,
      ]);

      $statusCode = $res->getStatusCode();
      if ($statusCode < 200 || $statusCode >= 300) {
        $this->log->error('UniFi createUser API returned non-2xx status code @code. Response: @body', [
          '@code' => $statusCode,
          '@body' => $this->trimForLog((string) $res->getBody()),
        ]);
        return NULL; // Return NULL on error.
      }

      return json_decode($res->getBody()->getContents(), TRUE);
    }
    catch (\Throwable $e) {
      $this->log->error('UniFi createUser error: @m', ['@m' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Deletes a user from UniFi Access.
   *
   * @param string $id
   *   The UniFi user ID.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function deleteUser(string $id): bool {
    if (!$this->isConfigured()) {
      $this->logMissingConfig();
      return FALSE;
    }

    try {
      // Path adjusted to /proxy/access/integration/... as per console "Integrations" tab.
      $res = $this->http->request('DELETE', $this->base() . '/proxy/access/integration/v1/developer/users/' . $id, [
        'headers' => $this->headers(),
        'verify' => $this->verify(),
        'timeout' => 20,
      ]);

      $statusCode = $res->getStatusCode();
      if ($statusCode < 200 || $statusCode >= 300) {
        $this->log->error('UniFi deleteUser API returned non-2xx status code @code. Response: @body', [
          '@code' => $statusCode,
          '@body' => $this->trimForLog((string) $res->getBody()),
        ]);
        return FALSE; // Return FALSE on error.
      }
      return TRUE;
    }
    catch (\Throwable $e) {
      $this->log->error('UniFi deleteUser error: @m', ['@m' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Builds the API payload for creating a user.
   *
   * @param string $email
   *   The user's email address.
   * @param array $data
   *   The user's data (first_name, last_name, display_name).
   *
   * @return array
   *   The payload for the UniFi API.
   */
  public function userPayloadForData(string $email, array $data = []): array {
    // Note: The UniFi Access Developer API (local console) expects a 'profile' object
    // with 'first_name', 'last_name', and 'email'. Flat fields like 'name' are often
    // rejected or ignored by newer versions of the API.
    $first = $data['first_name'] ?? '';
    $last = $data['last_name'] ?? '';

    // Fallback if names are empty.
    if ($first === '' && $last === '') {
      $parts = explode(' ', $data['display_name'] ?? $email);
      $first = array_shift($parts);
      $last = implode(' ', $parts) ?: '.';
    }

    return [
      'profile' => [
        'email' => $email,
        'first_name' => $first,
        'last_name' => $last,
      ],
    ];
  }

}
