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
  public const STATUS_DEACTIVATED = 'DEACTIVATED';

  /**
   * The `status` value of a user who can use their credentials at the door.
   */
  public const STATUS_ACTIVE = 'ACTIVE';

  /**
   * Prefix of the `employee_number` this module stamps on records it creates.
   *
   * The record is created WITHOUT an email address (see userPayloadForData()),
   * so the email can no longer be the join key between a Drupal member and
   * their console record. `employee_number` = "drupal-{uid}" is. It is a
   * documented, optional, free-text field on POST /users (API reference 3.2)
   * that UniFi does not act on.
   */
  public const EMPLOYEE_NUMBER_PREFIX = 'drupal-';

  /**
   * Payload keys that must never reach the console on a write.
   *
   * Giving a console user an email address is what makes UniFi send the
   * "Welcome to UniFi Identity!" invitation (identity@ui.com). On 2026-09-18
   * every one of ~1,224 records created by Pantheon dev carried
   * `user_email`, and members received that invitation; Phil Bernstein
   * forwarded his to JR on 09-21. The Developer API has no parameter to
   * suppress it — the auto-invite is a console setting — so the only
   * guarantee this module can give is to never hand the console an address.
   */
  private const FORBIDDEN_WRITE_KEYS = ['user_email', 'email'];

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

    // The avatar upload answers in a second dialect, {"code":1,"codeS":
    // "SUCCESS"} (seen live 2026-09-29), where the numeric code is not an
    // error. Either field saying SUCCESS is success.
    $ok = ($json['code'] ?? NULL) === self::ENVELOPE_SUCCESS
      || ($json['codeS'] ?? NULL) === self::ENVELOPE_SUCCESS;
    if (isset($json['code']) && !$ok) {
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
    // Hard stop, not a convention: whoever builds the payload, an address
    // never leaves this module. See FORBIDDEN_WRITE_KEYS.
    $forbidden = array_intersect(array_keys($payload), self::FORBIDDEN_WRITE_KEYS);
    if ($forbidden) {
      $this->log->error('Refused to create a UniFi user with an email field (@k): an address on a console user makes UniFi email an Identity invitation.', [
        '@k' => implode(', ', $forbidden),
      ]);
      return UnifiApiResult::failure('Refused: payload carries ' . implode(', ', $forbidden) . ' (would trigger a UniFi Identity invitation email).');
    }
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
    return $this->setUserStatus($id, self::STATUS_DEACTIVATED, 'deactivateUser');
  }

  /**
   * Restores door access for a user the console already holds.
   *
   * The mirror of deactivateUser(): `PUT /users/{id}` with
   * `{"status":"ACTIVE"}`. Needed because on 2026-09-18 Pantheon dev
   * mass-created ~1,224 real records on the production console, which the
   * clean-up then deactivated — 372 of them current members. Re-creating
   * those would fail with CODE_ADMIN_EMAIL_EXIST; the record is there, it
   * just has to be switched back on. Verified against the live console on
   * 2026-09-21 (see the release record).
   */
  public function reactivateUser(string $id): UnifiApiResult {
    return $this->setUserStatus($id, self::STATUS_ACTIVE, 'reactivateUser');
  }

  /**
   * Sets a console user's status — the one write both revoke and restore use.
   *
   * @param string $id
   *   The console user id.
   * @param string $status
   *   STATUS_ACTIVE or STATUS_DEACTIVATED.
   * @param string $op
   *   Operation name for log lines and the envelope check.
   */
  private function setUserStatus(
    string $id,
    string $status,
    string $op,
  ): UnifiApiResult {
    if (!$this->isConfigured()) {
      $this->log->warning('UniFi API not configured: missing api_host or token.');
      return UnifiApiResult::failure('UniFi API not configured (missing host or token).');
    }

    try {
      $res = $this->http->request('PUT', $this->base() . '/users/' . $id, [
        'headers' => $this->headers(),
        'verify' => $this->verify(),
        // Status ONLY. Never add user_email/email here: this PUT is what
        // reactivates the records the 2026-09-18 mass-create left behind,
        // and setting an address on an existing user is the same trigger as
        // creating one with it.
        'json' => ['status' => $status],
        'timeout' => 20,
      ]);

      $statusCode = $res->getStatusCode();
      if ($statusCode < 200 || $statusCode >= 300) {
        $body = $this->trimForLog((string) $res->getBody());
        $this->log->error('UniFi @op returned HTTP @code. Response: @body', [
          '@op' => $op,
          '@code' => $statusCode,
          '@body' => $body,
        ]);
        return UnifiApiResult::failure(
          errorMessage: $op . ' non-2xx response',
          statusCode: $statusCode,
          responseBody: $body,
        );
      }
      // Same trap as createUser: a refused write comes back 200 with an
      // error envelope. Reporting that as success would leave a revoked
      // member present in the console with their door access intact — or a
      // restored member still locked out — and both are the failure
      // direction that actually matters here.
      return $this->decodeEnvelope($statusCode, (string) $res->getBody(), $op);
    }
    catch (RequestException $e) {
      $response = $e->getResponse();
      $statusCode = $response?->getStatusCode();
      $body = $response ? $this->trimForLog((string) $response->getBody()) : NULL;
      $this->log->error('UniFi @op HTTP error @code: @m. Body: @body', [
        '@op' => $op,
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
      $this->log->error('UniFi @op exception: @m', ['@op' => $op, '@m' => $e->getMessage()]);
      return UnifiApiResult::failure(errorMessage: 'Exception: ' . $e->getMessage());
    }
  }

  /**
   * Lists every NFC card the console knows, paginated.
   *
   * Each row carries `nfc_id` (uppercase hex, the serial a reader reports),
   * `token` (what binds a card to a user), `status` (pending|assigned) and
   * `user_id` (empty when unassigned). Permission key: view:credential.
   */
  public function listNfcCards(): UnifiApiResult {
    $all = [];
    $page = 1;
    $size = 200;
    do {
      $result = $this->call('GET', '/credentials/nfc_cards/tokens', [
        'query' => ['page_num' => $page, 'page_size' => $size],
      ], 'listNfcCards');
      if (!$result->ok) {
        return $result;
      }
      $rows = is_array($result->data) ? $result->data : [];
      $all = array_merge($all, $rows);
      $page++;
    } while (count($rows) >= $size);
    return UnifiApiResult::success(data: $all, statusCode: 200);
  }

  /**
   * Imports one third-party card serial; returns its token in ->data.
   *
   * API reference 6.x: a CSV upload of `nfc_id,alias` rows, answered with a
   * token per row, where an empty token means that row failed. Aliases must
   * be unique, so the alias is the member's employee_number
   * ("drupal-{uid}"). Permission key: edit:credential.
   */
  public function importNfcCard(string $nfc_id, string $alias): UnifiApiResult {
    $result = $this->call('POST', '/credentials/nfc_cards/import', [
      'multipart' => [
        [
          'name' => 'file',
          'contents' => strtoupper($nfc_id) . ',' . $alias . "\n",
          'filename' => 'nfc_cards.csv',
          'headers' => ['Content-Type' => 'text/csv'],
        ],
      ],
    ], 'importNfcCard');
    if (!$result->ok) {
      return $result;
    }
    foreach ((array) $result->data as $row) {
      if (strcasecmp((string) ($row['nfc_id'] ?? ''), $nfc_id) === 0 && !empty($row['token'])) {
        return UnifiApiResult::success(data: (string) $row['token'], statusCode: $result->statusCode);
      }
    }
    return UnifiApiResult::failure('importNfcCard: the console returned no token for ' . $nfc_id . ' (import refused for that row).', $result->statusCode);
  }

  /**
   * Binds an imported card (by token) to a console user.
   *
   * `force_add` is always FALSE: a card already bound to someone else is
   * refused rather than silently moved, because a member's serial landing on
   * the wrong record is a door-access fault to be looked at, not overwritten.
   * Permission key: edit:user.
   */
  public function assignNfcCard(string $user_id, string $token): UnifiApiResult {
    return $this->call('PUT', '/users/' . rawurlencode($user_id) . '/nfc_cards', [
      'json' => ['token' => $token, 'force_add' => FALSE],
    ], 'assignNfcCard');
  }

  /**
   * The access policy ids assigned directly to a user.
   *
   * `only_user_policies=true` so group-inherited policies are not mistaken
   * for direct ones (a later PUT replaces the direct list only).
   */
  public function getUserPolicyIds(string $user_id): UnifiApiResult {
    $result = $this->call('GET', '/users/' . rawurlencode($user_id) . '/access_policies', [
      'query' => ['only_user_policies' => 'true'],
    ], 'getUserPolicyIds');
    if (!$result->ok) {
      return $result;
    }
    $ids = [];
    foreach ((array) $result->data as $policy) {
      if (!empty($policy['id'])) {
        $ids[] = (string) $policy['id'];
      }
    }
    return UnifiApiResult::success(data: $ids, statusCode: $result->statusCode);
  }

  /**
   * Sets a user's direct access policies.
   *
   * **This REPLACES the user's list** (the reference's own example clears
   * every policy with an empty array). Callers must pass the union of what
   * the user already has and what they need — see UnifiProvisioner.
   * Refuses an empty list outright: that would lock the member out.
   */
  public function setUserPolicies(string $user_id, array $policy_ids): UnifiApiResult {
    $policy_ids = array_values(array_unique(array_filter(array_map('strval', $policy_ids))));
    if (!$policy_ids) {
      return UnifiApiResult::failure('Refused: an empty policy list would remove every policy from the user.');
    }
    return $this->call('PUT', '/users/' . rawurlencode($user_id) . '/access_policies', [
      'json' => ['access_policy_ids' => $policy_ids],
    ], 'setUserPolicies');
  }

  /**
   * Lists the console's access policies (for choosing access_policy_ids).
   *
   * Permission key: view:policy.
   */
  public function listAccessPolicies(): UnifiApiResult {
    return $this->call('GET', '/access_policies', [], 'listAccessPolicies');
  }

  /**
   * Uploads a user's profile picture (shown on the intercom at unlock).
   *
   * Supported only for local users, which is what this module creates.
   * Permission key: edit:user.
   */
  public function uploadAvatar(string $user_id, string $bytes, string $filename, string $mime): UnifiApiResult {
    return $this->call('POST', '/users/' . rawurlencode($user_id) . '/avatar', [
      'multipart' => [
        [
          'name' => 'file',
          'contents' => $bytes,
          'filename' => $filename,
          'headers' => ['Content-Type' => $mime],
        ],
      ],
    ], 'uploadAvatar');
  }

  /**
   * One request, with every failure shape reduced to a UnifiApiResult.
   *
   * Same handling as the user calls above: non-2xx, a transport error, or an
   * error envelope inside HTTP 200 are all failures (see decodeEnvelope()).
   * `json` bodies get the JSON content type; `multipart` bodies must not, so
   * the header is dropped for them.
   */
  private function call(string $method, string $path, array $options, string $what): UnifiApiResult {
    if (!$this->isConfigured()) {
      return UnifiApiResult::failure('UniFi API not configured (missing host or token).');
    }
    $headers = $this->headers();
    if (isset($options['multipart'])) {
      unset($headers['Content-Type']);
    }
    try {
      $res = $this->http->request($method, $this->base() . $path, $options + [
        'headers' => $headers,
        'verify' => $this->verify(),
        'timeout' => 20,
      ]);
      $status = $res->getStatusCode();
      if ($status < 200 || $status >= 300) {
        $body = $this->trimForLog((string) $res->getBody());
        $this->log->error('UniFi @w returned HTTP @code. Response: @body', [
          '@w' => $what,
          '@code' => $status,
          '@body' => $body,
        ]);
        return UnifiApiResult::failure($what . ' non-2xx response', $status, $body);
      }
      return $this->decodeEnvelope($status, (string) $res->getBody(), $what);
    }
    catch (RequestException $e) {
      $response = $e->getResponse();
      $status = $response?->getStatusCode();
      $body = $response ? $this->trimForLog((string) $response->getBody()) : NULL;
      $this->log->error('UniFi @w HTTP error @code: @m. Body: @body', [
        '@w' => $what,
        '@code' => $status ?? 'n/a',
        '@m' => $e->getMessage(),
        '@body' => $body ?? '',
      ]);
      return UnifiApiResult::failure($e->getMessage(), $status, $body);
    }
    catch (\Throwable $e) {
      $this->log->error('UniFi @w exception: @m', ['@w' => $what, '@m' => $e->getMessage()]);
      return UnifiApiResult::failure('Exception: ' . $e->getMessage());
    }
  }

  /**
   * Builds the API payload for creating a user — deliberately without email.
   *
   * Three facts, all measured on the live console (2026-09-17/18):
   * - the payload is flat (a nested `profile` returned CODE_SYSTEM_ERROR);
   * - `first_name` + `last_name` alone are sufficient for SUCCESS;
   * - a record created WITH `user_email` gets UniFi's "Welcome to UniFi
   *   Identity!" invitation mailed to that address by identity@ui.com. That
   *   is what members received on 2026-09-18.
   *
   * So no address is sent. The member is identified to the console by
   * `employee_number` = "drupal-{uid}" instead, which reconcile() matches on.
   * Door access comes from a credential (NFC card / PIN) plus an access
   * policy, neither of which needs an email; the only thing an email buys is
   * the UniFi Identity mobile app, and its invitation is exactly what must
   * not be sent.
   *
   * @param string $email
   *   The member's address. Used only to derive a name when the profile has
   *   none; it is NOT included in the payload.
   * @param array $data
   *   Member data from UnifiSyncManager: first_name, last_name, display_name
   *   and uid.
   */
  public function userPayloadForData(string $email, array $data = []): array {
    $first = $data['first_name'] ?? '';
    $last = $data['last_name'] ?? '';

    if ($first === '' && $last === '') {
      // The address is a last resort for a name, and only its local part.
      $fallback = $data['display_name'] ?? '';
      if ($fallback === '') {
        $fallback = strstr($email, '@', TRUE) ?: 'Member';
      }
      $parts = explode(' ', $fallback);
      $first = array_shift($parts);
      $last = implode(' ', $parts) ?: '.';
    }

    $payload = [
      'first_name' => $first,
      'last_name' => $last,
    ];
    $uid = (int) ($data['uid'] ?? 0);
    if ($uid > 0) {
      $payload['employee_number'] = self::employeeNumberForUid($uid);
    }
    return $payload;
  }

  /**
   * The employee_number this module gives the console record of a Drupal uid.
   */
  public static function employeeNumberForUid(int $uid): string {
    return self::EMPLOYEE_NUMBER_PREFIX . $uid;
  }

  /**
   * The Drupal uid a console employee_number points at, or NULL.
   */
  public static function uidFromEmployeeNumber(?string $employee_number): ?int {
    $employee_number = trim((string) $employee_number);
    if (!str_starts_with($employee_number, self::EMPLOYEE_NUMBER_PREFIX)) {
      return NULL;
    }
    $rest = substr($employee_number, strlen(self::EMPLOYEE_NUMBER_PREFIX));
    return ctype_digit($rest) && (int) $rest > 0 ? (int) $rest : NULL;
  }

}
