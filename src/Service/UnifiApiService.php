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

  /**
   * The `code` value the Developer API uses to mean "this call worked".
   */
  private const ENVELOPE_SUCCESS = 'SUCCESS';

  /**
   * The `status` value that revokes a user's access at the door.
   */
  private const STATUS_DEACTIVATED = 'DEACTIVATED';

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  private ClientInterface $http;

  /**
   * The module settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  private $cfg;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private LoggerChannelInterface $log;

  /**
   * The Key repository, when the Key module is installed.
   *
   * @var \Drupal\key\KeyRepositoryInterface|null
   */
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
    $host = rtrim((string) $this->cfg->get('api_host'), '/');
    $prefix = trim((string) ($this->cfg->get('api_path_prefix') ?: '/api/v1/developer'), '/');
    return $host . '/' . $prefix;
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
    $token = $this->getToken();
    return [
      // Access accepts either header on port 12445; UniFi OS on 443 only
      // passes X-API-KEY through to Access, so both are sent.
      'Authorization' => 'Bearer ' . $token,
      'X-API-KEY' => $token,
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
   * Interprets a 2xx response body from the UniFi Access Developer API.
   *
   * **This API answers HTTP 200 for most of its errors.** A failed user
   * creation returns `200 {"code":"CODE_SYSTEM_ERROR","msg":"Server system
   * error."}`; a wrong path returns `200 {"code":404,"codeS":"CODE_NOT_FOUND",
   * ...}`. Trusting the status code alone is how 168,763 "created
   * successfully" log entries were written against a console that gained no
   * users at all (2026-09-15 → 09-17). Note `code` is a string on some errors
   * and an integer on others, so the comparison is strict against the literal
   * success value and everything else is a failure.
   *
   * @param int $status
   *   The HTTP status code (already known to be 2xx).
   * @param string $body
   *   The raw response body.
   * @param string $what
   *   Short label for log messages, e.g. "createUser".
   *
   * @return \Drupal\unifi_access_sync\Service\UnifiApiResult
   *   Success carries the unwrapped `data` member when the envelope has one.
   */
  private function decodeEnvelope(int $status, string $body, string $what): UnifiApiResult {
    // A 2xx with no body at all (204 No Content on delete, for instance) has
    // nothing to disagree with, so it stands as success.
    if (trim($body) === '') {
      return UnifiApiResult::success(NULL, $status);
    }

    $json = json_decode($body, TRUE);
    if (!is_array($json)) {
      $this->log->error('UniFi @w returned non-JSON. Body: @body', [
        '@w' => $what,
        '@body' => $this->trimForLog($body),
      ]);
      return UnifiApiResult::failure($what . ' returned non-JSON', $status, $this->trimForLog($body));
    }

    if (isset($json['code']) && $json['code'] !== self::ENVELOPE_SUCCESS) {
      $msg = (string) ($json['msg'] ?? $json['code']);
      $this->log->error('UniFi @w failed inside HTTP @s: @c @msg', [
        '@w' => $what,
        '@s' => $status,
        '@c' => $json['code'],
        '@msg' => $msg,
      ]);
      return UnifiApiResult::failure(
        $json['code'] . ': ' . $msg,
        $status,
        $this->trimForLog($body),
      );
    }

    return UnifiApiResult::success(
      array_key_exists('data', $json) ? $json['data'] : $json,
      $status,
    );
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
        $res = $this->http->request('GET', $this->base() . '/users', [
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

        $decoded = $this->decodeEnvelope($statusCode, (string) $res->getBody(), 'listUsers');
        if (!$decoded->ok) {
          return $decoded;
        }
        $users = is_array($decoded->data) ? $decoded->data : [];

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
      $res = $this->http->request('POST', $this->base() . '/users', [
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
      return $this->decodeEnvelope($statusCode, (string) $res->getBody(), 'createUser');
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
   * Revokes a user's access by deactivating them in UniFi Access.
   *
   * **Not a delete.** `DELETE /users/{id}` answers
   * `200 {"code":"CODE_SYSTEM_ERROR"}` on this console — it is not a
   * supported operation, and the previous status-only success check reported
   * those refusals as "deleted successfully". `PUT /users/{id}` with
   * `{"status":"DEACTIVATED"}` is the operation that actually works, and it
   * is the better one anyway: it revokes access at the door while preserving
   * the console's audit history for that person.
   */
  public function deactivateUser(string $id): UnifiApiResult {
    if (!$this->isConfigured()) {
      $this->log->warning('UniFi API not configured: missing api_host or token.');
      return UnifiApiResult::failure('UniFi API not configured (missing host or token).');
    }

    try {
      $res = $this->http->request('PUT', $this->base() . '/users/' . $id, [
        'headers' => $this->headers(),
        'verify' => $this->verify(),
        'json' => ['status' => self::STATUS_DEACTIVATED],
        'timeout' => 20,
      ]);

      $statusCode = $res->getStatusCode();
      if ($statusCode < 200 || $statusCode >= 300) {
        $body = $this->trimForLog((string) $res->getBody());
        $this->log->error('UniFi deactivateUser returned HTTP @code. Response: @body', [
          '@code' => $statusCode,
          '@body' => $body,
        ]);
        return UnifiApiResult::failure(
          errorMessage: 'deactivateUser non-2xx response',
          statusCode: $statusCode,
          responseBody: $body,
        );
      }
      // Same trap as createUser: a refused delete comes back 200 with an
      // error envelope. Reporting that as success would leave a revoked
      // member present in the console with their door access intact, which
      // is the failure direction that actually matters here.
      return $this->decodeEnvelope($statusCode, (string) $res->getBody(), 'deactivateUser');
    }
    catch (RequestException $e) {
      $response = $e->getResponse();
      $statusCode = $response?->getStatusCode();
      $body = $response ? $this->trimForLog((string) $response->getBody()) : NULL;
      $this->log->error('UniFi deactivateUser HTTP error @code: @m. Body: @body', [
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
      $this->log->error('UniFi deactivateUser exception: @m', ['@m' => $e->getMessage()]);
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

    // Flat, NOT nested under a `profile` key, and the email goes in
    // `user_email` rather than `email`. All three facts were established by
    // probing the live console on 2026-09-17:
    // - the old nested shape returned `{"code":"CODE_SYSTEM_ERROR"}`;
    // - flat with `email` returned `{"code":"CODE_PARAMS_INVALID"}`;
    // - flat with `user_email` returned `{"code":"SUCCESS"}`.
    // `email` is read-only on this endpoint — a created user comes back with
    // `email: ""` and the address in `user_email`. `user_email` is also
    // unique: a duplicate is refused with `CODE_ADMIN_EMAIL_EXIST`.
    return [
      'user_email' => $email,
      'first_name' => $first,
      'last_name' => $last,
    ];
  }

}
