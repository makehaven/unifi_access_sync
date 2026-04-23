<?php

namespace Drupal\unifi_access_sync\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\key\KeyRepositoryInterface;

/**
 * HTTP client for the UniFi Access Developer API.
 *
 * Every public call returns a UnifiApiResult so callers can distinguish
 * failure from a legitimately empty response, and so failure reasons
 * (HTTP status + response body + exception message) are preserved for
 * operators rather than collapsed to NULL.
 */
class UnifiApiService {

  private ClientInterface $http;
  private $cfg;
  private LoggerChannelInterface $log;
  private ?KeyRepositoryInterface $keyRepo;

  public function __construct(
    ClientInterface $http,
    ConfigFactoryInterface $config_factory,
    LoggerChannelInterface $log,
    ?KeyRepositoryInterface $key_repo = NULL,
  ) {
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
   *
   * The UniFi Access console "Integrations" tab issues keys using an
   * X-API-KEY header rather than a standard Bearer token. Network-app
   * keys return 401 if used here.
   */
  private function headers(): array {
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
   * Checks whether host and token are both set.
   */
  private function isConfigured(): bool {
    $host = trim((string) $this->cfg->get('api_host'));
    $token = trim((string) $this->getToken());
    return $host !== '' && $token !== '';
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
   * Lists all users from UniFi Access, paginated.
   *
   * On success, result->data is an array of user rows (possibly empty if
   * the tenant is genuinely empty). On failure, result->ok is FALSE and
   * the HTTP status, error message, and response body are populated.
   */
  public function listUsers(): UnifiApiResult {
    if (!$this->isConfigured()) {
      $this->log->warning('UniFi API not configured: missing api_host or token.');
      return UnifiApiResult::failure('UniFi API not configured (missing host or token).');
    }

    $all_users = [];
    $page = 1;
    $pageSize = 50;

    try {
      do {
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
          $body = $this->trimForLog((string) $res->getBody());
          $this->log->error('UniFi listUsers returned HTTP @code. Response: @body', [
            '@code' => $statusCode,
            '@body' => $body,
          ]);
          return UnifiApiResult::failure(
            errorMessage: 'listUsers non-2xx response',
            statusCode: $statusCode,
            responseBody: $body,
          );
        }

        $json = json_decode($res->getBody()->getContents(), TRUE);
        $users = [];
        if (isset($json['data']) && is_array($json['data'])) {
          $users = $json['data'];
        }
        elseif (is_array($json)) {
          $users = $json;
        }

        if (empty($users)) {
          break;
        }
        $all_users = array_merge($all_users, $users);
        if (count($users) < $pageSize) {
          break;
        }
        $page++;
      } while (TRUE);

      return UnifiApiResult::success(data: $all_users, statusCode: 200);
    }
    catch (RequestException $e) {
      $response = $e->getResponse();
      $statusCode = $response?->getStatusCode();
      $body = $response ? $this->trimForLog((string) $response->getBody()) : NULL;
      $this->log->error('UniFi listUsers HTTP error @code: @m. Body: @body', [
        '@code' => $statusCode ?? 'n/a',
        '@m' => $e->getMessage(),
        '@body' => $body ?? '',
      ]);
      return UnifiApiResult::failure(
        errorMessage: $e->getMessage(),
        statusCode: $statusCode,
        responseBody: $body,
      );
    }
    catch (\Throwable $e) {
      $this->log->error('UniFi listUsers exception: @m', ['@m' => $e->getMessage()]);
      return UnifiApiResult::failure(errorMessage: 'Exception: ' . $e->getMessage());
    }
  }

  /**
   * Creates a user in UniFi Access.
   */
  public function createUser(array $payload): UnifiApiResult {
    if (!$this->isConfigured()) {
      $this->log->warning('UniFi API not configured: missing api_host or token.');
      return UnifiApiResult::failure('UniFi API not configured (missing host or token).');
    }

    try {
      $res = $this->http->request('POST', $this->base() . '/proxy/access/integration/v1/developer/users', [
        'headers' => $this->headers(),
        'verify' => $this->verify(),
        'json' => $payload,
        'timeout' => 20,
      ]);

      $statusCode = $res->getStatusCode();
      if ($statusCode < 200 || $statusCode >= 300) {
        $body = $this->trimForLog((string) $res->getBody());
        $this->log->error('UniFi createUser returned HTTP @code. Response: @body', [
          '@code' => $statusCode,
          '@body' => $body,
        ]);
        return UnifiApiResult::failure(
          errorMessage: 'createUser non-2xx response',
          statusCode: $statusCode,
          responseBody: $body,
        );
      }
      return UnifiApiResult::success(
        data: json_decode($res->getBody()->getContents(), TRUE),
        statusCode: $statusCode,
      );
    }
    catch (RequestException $e) {
      $response = $e->getResponse();
      $statusCode = $response?->getStatusCode();
      $body = $response ? $this->trimForLog((string) $response->getBody()) : NULL;
      $this->log->error('UniFi createUser HTTP error @code: @m. Body: @body', [
        '@code' => $statusCode ?? 'n/a',
        '@m' => $e->getMessage(),
        '@body' => $body ?? '',
      ]);
      return UnifiApiResult::failure(
        errorMessage: $e->getMessage(),
        statusCode: $statusCode,
        responseBody: $body,
      );
    }
    catch (\Throwable $e) {
      $this->log->error('UniFi createUser exception: @m', ['@m' => $e->getMessage()]);
      return UnifiApiResult::failure(errorMessage: 'Exception: ' . $e->getMessage());
    }
  }

  /**
   * Deletes a user from UniFi Access.
   */
  public function deleteUser(string $id): UnifiApiResult {
    if (!$this->isConfigured()) {
      $this->log->warning('UniFi API not configured: missing api_host or token.');
      return UnifiApiResult::failure('UniFi API not configured (missing host or token).');
    }

    try {
      $res = $this->http->request('DELETE', $this->base() . '/proxy/access/integration/v1/developer/users/' . $id, [
        'headers' => $this->headers(),
        'verify' => $this->verify(),
        'timeout' => 20,
      ]);

      $statusCode = $res->getStatusCode();
      if ($statusCode < 200 || $statusCode >= 300) {
        $body = $this->trimForLog((string) $res->getBody());
        $this->log->error('UniFi deleteUser returned HTTP @code. Response: @body', [
          '@code' => $statusCode,
          '@body' => $body,
        ]);
        return UnifiApiResult::failure(
          errorMessage: 'deleteUser non-2xx response',
          statusCode: $statusCode,
          responseBody: $body,
        );
      }
      return UnifiApiResult::success(statusCode: $statusCode);
    }
    catch (RequestException $e) {
      $response = $e->getResponse();
      $statusCode = $response?->getStatusCode();
      $body = $response ? $this->trimForLog((string) $response->getBody()) : NULL;
      $this->log->error('UniFi deleteUser HTTP error @code: @m. Body: @body', [
        '@code' => $statusCode ?? 'n/a',
        '@m' => $e->getMessage(),
        '@body' => $body ?? '',
      ]);
      return UnifiApiResult::failure(
        errorMessage: $e->getMessage(),
        statusCode: $statusCode,
        responseBody: $body,
      );
    }
    catch (\Throwable $e) {
      $this->log->error('UniFi deleteUser exception: @m', ['@m' => $e->getMessage()]);
      return UnifiApiResult::failure(errorMessage: 'Exception: ' . $e->getMessage());
    }
  }

  /**
   * Builds the API payload for creating a user.
   *
   * The UniFi Access Developer API (local console) expects a 'profile'
   * object with 'first_name', 'last_name', and 'email'. Flat fields like
   * 'name' are often rejected or ignored by newer API versions.
   */
  public function userPayloadForData(string $email, array $data = []): array {
    $first = $data['first_name'] ?? '';
    $last = $data['last_name'] ?? '';

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
