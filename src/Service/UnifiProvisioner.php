<?php

namespace Drupal\unifi_access_sync\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\image\Entity\ImageStyle;

/**
 * Gives a member's console record what it needs to open the door.
 *
 * A console user with a name is not yet a member at the intercom. Three more
 * things make them one, each behind its own setting and all OFF by default:
 *
 * - **Card** (`provision_nfc_cards`): the member's `field_card_serial_number`
 *   is byte-for-byte the `nfc_id` the UA-Intercom reports (verified
 *   2026-09-14), so the card they already carry is imported
 *   (`importNfcCard`, alias "drupal-{uid}") and bound to their record. A
 *   card already bound to someone else is never moved (`force_add` false).
 * - **Door** (`access_policy_ids`): the listed policies are ADDED to the
 *   member's direct policies. The API's PUT replaces the whole list, so the
 *   current list is read first and the union written; nothing a person added
 *   in the console is removed.
 * - **Photo** (`provision_avatars`): the member headshot
 *   (`profile.main.field_member_photo`) is uploaded only when the console
 *   record has no picture, so a picture set in the console is never
 *   overwritten and nothing is re-sent every hour.
 *
 * **Never a record with an email address.** UniFi mails its "Welcome to
 * UniFi Identity!" invitation to console users that carry an address, and the
 * console's auto-invite setting also fires when access is *granted* to such a
 * user. That invitation reached members on 2026-09-18. Records this module
 * creates carry no address; any record that does (console-UI staff, the
 * 09-18 leftovers) is skipped by every step here, with no setting to override
 * it. Provision those by hand in the console, knowingly.
 *
 * **Flood control.** reconcile() runs hourly for ~850 members. Deciding
 * whether a member needs work uses only what listUsers already returned plus
 * state, never a per-member API call, and a member whose provisioning was
 * attempted in the last day is skipped whatever the outcome. A failing step
 * therefore costs one attempt per member per day, not per hour.
 */
class UnifiProvisioner {

  /**
   * State key: per-uid record of what was provisioned and when it was tried.
   */
  private const STATE_KEY = 'unifi_access_sync.provision';

  /**
   * Seconds before a member whose provisioning was tried is looked at again.
   */
  public const RETRY_AFTER = 86400;

  /**
   * NFC index for this request (nfc_id => card row), or NULL until loaded.
   */
  private ?array $nfcIndex = NULL;

  /**
   * The module settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  private $cfg;

  public function __construct(
    private EntityTypeManagerInterface $etm,
    ConfigFactoryInterface $config_factory,
    private LoggerChannelInterface $log,
    private UnifiApiService $api,
    private StateInterface $state,
  ) {
    $this->cfg = $config_factory->get('unifi_access_sync.settings');
  }

  /**
   * Whether any provisioning step is switched on.
   */
  public function enabled(): bool {
    return $this->cardsEnabled() || $this->policyIds() || $this->avatarsEnabled();
  }

  /**
   * Whether member cards are imported and bound.
   */
  public function cardsEnabled(): bool {
    return (bool) $this->cfg->get('provision_nfc_cards');
  }

  /**
   * Whether member headshots are uploaded.
   */
  public function avatarsEnabled(): bool {
    return (bool) $this->cfg->get('provision_avatars');
  }

  /**
   * The access policy ids every member should hold (empty = do not assign).
   */
  public function policyIds(): array {
    $ids = array_map('strval', (array) ($this->cfg->get('access_policy_ids') ?? []));
    $ids = array_values(array_unique(array_filter(array_map('trim', $ids))));
    sort($ids);
    return $ids;
  }

  /**
   * Cheap test, for reconcile(): might this ACTIVE record need provisioning?
   *
   * Uses only the listUsers row and state (no API call). Deliberately
   * conservative on cards: a record that already holds any card is left
   * alone, since the list shows only a display id and not the serial.
   *
   * @param array $raw
   *   The console row from listUsers.
   * @param int $uid
   *   The member's Drupal uid.
   * @param int|null $now
   *   The current time (tests); defaults to the request time.
   */
  public function needsWork(array $raw, int $uid, ?int $now = NULL): bool {
    if (!$this->enabled() || $uid <= 0 || self::carriesEmail($raw)) {
      return FALSE;
    }
    $now ??= \Drupal::time()->getRequestTime();
    $mine = $this->stateFor($uid);
    if (!empty($mine['tried']) && $now - (int) $mine['tried'] < self::RETRY_AFTER) {
      return FALSE;
    }
    // Cheap test (no console card listing per member): fewer cards on the
    // record than serials on file. cardStep() then works out which.
    if ($this->cardsEnabled() && count((array) ($raw['nfc_cards'] ?? [])) < count($this->cardSerials($uid))) {
      return TRUE;
    }
    if ($this->policyIds() && ($mine['policies'] ?? NULL) !== $this->policyIds()) {
      return TRUE;
    }
    if ($this->avatarsEnabled() && empty($raw['avatar_relative_path']) && empty($mine['avatar']) && $this->photoFile($uid) !== NULL) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Plans — and with $execute performs — provisioning for one record.
   *
   * @param string $user_id
   *   The console user id.
   * @param int $uid
   *   The member's Drupal uid.
   * @param array $raw
   *   The console row (nfc_cards, avatar_relative_path), as far as known.
   * @param bool $execute
   *   FALSE returns the plan only.
   *
   * @return array
   *   One entry per step (card, door, photo): ['do' => bool, 'detail' =>
   *   string, 'ok' => bool|null].
   */
  public function provision(string $user_id, int $uid, array $raw, bool $execute): array {
    if (self::carriesEmail($raw)) {
      $skip = [
        'do' => FALSE,
        'detail' => 'SKIPPED: this console record carries an email address; granting it anything can make UniFi send its invitation email',
        'ok' => NULL,
      ];
      return ['card' => $skip, 'door' => $skip, 'photo' => $skip];
    }
    $steps = [
      'card' => $this->cardStep($user_id, $uid, $raw, $execute),
      'door' => $this->doorStep($user_id, $uid, $execute),
      'photo' => $this->photoStep($user_id, $uid, $raw, $execute),
    ];
    if ($execute) {
      $mine = $this->stateFor($uid);
      $mine['tried'] = \Drupal::time()->getRequestTime();
      if (($steps['door']['ok'] ?? NULL) === TRUE) {
        $mine['policies'] = $this->policyIds();
      }
      if (($steps['photo']['ok'] ?? NULL) === TRUE) {
        $mine['avatar'] = TRUE;
      }
      $this->saveStateFor($uid, $mine);
      foreach ($steps as $name => $step) {
        if ($step['ok'] === FALSE) {
          $this->log->error('UniFi provisioning @s failed for uid @u (@id): @d', [
            '@s' => $name,
            '@u' => $uid,
            '@id' => $user_id,
            '@d' => $step['detail'],
          ]);
        }
        elseif ($step['ok'] === TRUE) {
          $this->log->notice('UniFi provisioning @s done for uid @u (@id): @d', [
            '@s' => $name,
            '@u' => $uid,
            '@id' => $user_id,
            '@d' => $step['detail'],
          ]);
        }
      }
    }
    return $steps;
  }

  /**
   * Whether a console row carries an email address (or is flagged as doing so).
   *
   * Unknown is treated as "carries one": a row we cannot see is not a row we
   * may grant access to.
   */
  public static function carriesEmail(array $raw): bool {
    if (!empty($raw['has_email'])) {
      return TRUE;
    }
    foreach (['user_email', 'email'] as $k) {
      if (!empty($raw[$k]) && trim((string) $raw[$k]) !== '') {
        return TRUE;
      }
    }
    return !empty($raw['profile']['email']);
  }

  /**
   * Card step: import each of the member's serials if needed, then bind it.
   *
   * field_card_serial_number holds several cards for some members (84 users,
   * 34 current members on live 2026-09-29), and the one they carry is not
   * necessarily the first, so every card on file is bound.
   */
  private function cardStep(string $user_id, int $uid, array $raw, bool $execute): array {
    if (!$this->cardsEnabled()) {
      return ['do' => FALSE, 'detail' => 'off (provision_nfc_cards)', 'ok' => NULL];
    }
    $serials = $this->cardSerials($uid);
    if (!$serials) {
      return ['do' => FALSE, 'detail' => 'no card serial on the member record', 'ok' => NULL];
    }
    $index = $this->nfcIndex();
    if ($index === NULL) {
      return ['do' => TRUE, 'detail' => 'could not list console cards', 'ok' => $execute ? FALSE : NULL];
    }
    $details = [];
    $do = FALSE;
    $failed = FALSE;
    $done = FALSE;
    foreach ($serials as $serial) {
      $one = $this->oneCard($user_id, $uid, $serial, $execute);
      $details[] = $one['detail'];
      $do = $do || $one['do'];
      $failed = $failed || $one['ok'] === FALSE;
      $done = $done || $one['ok'] === TRUE;
    }
    return [
      'do' => $do,
      'detail' => implode('; ', $details),
      'ok' => $failed ? FALSE : ($done ? TRUE : NULL),
    ];
  }

  /**
   * One serial of the card step.
   */
  private function oneCard(string $user_id, int $uid, string $serial, bool $execute): array {
    $card = $this->nfcIndex[$serial] ?? NULL;
    if ($card && (string) ($card['user_id'] ?? '') === $user_id) {
      return ['do' => FALSE, 'detail' => "card $serial already bound to this record", 'ok' => NULL];
    }
    if ($card && !empty($card['user_id'])) {
      return [
        'do' => FALSE,
        'detail' => "card $serial is bound to another console user (" . $card['user_id'] . '); not moved — check in the console',
        'ok' => $execute ? FALSE : NULL,
      ];
    }
    $alias = self::cardAlias($uid, $serial);
    $plan = $card ? "bind existing card $serial" : "import card $serial as $alias, then bind";
    if (!$execute) {
      return ['do' => TRUE, 'detail' => $plan, 'ok' => NULL];
    }
    $token = (string) ($card['token'] ?? '');
    if ($token === '') {
      $imported = $this->api->importNfcCard($serial, $alias);
      if (!$imported->ok) {
        return ['do' => TRUE, 'detail' => $plan . ' — import failed: ' . $imported->describe(), 'ok' => FALSE];
      }
      $token = (string) $imported->data;
      $this->nfcIndex[$serial] = ['nfc_id' => $serial, 'token' => $token, 'user_id' => ''];
    }
    $bound = $this->api->assignNfcCard($user_id, $token);
    if (!$bound->ok) {
      return ['do' => TRUE, 'detail' => $plan . ' — bind failed: ' . $bound->describe(), 'ok' => FALSE];
    }
    $this->nfcIndex[$serial]['user_id'] = $user_id;
    return ['do' => TRUE, 'detail' => $plan, 'ok' => TRUE];
  }

  /**
   * The console alias for an imported card: unique per card, names the member.
   *
   * Aliases must be unique, so a plain "drupal-{uid}" would clash with a
   * member's second card or a replacement card.
   */
  public static function cardAlias(int $uid, string $serial): string {
    return UnifiApiService::employeeNumberForUid($uid) . '-' . $serial;
  }

  /**
   * Door step: add the configured policies to the member's direct list.
   */
  private function doorStep(string $user_id, int $uid, bool $execute): array {
    $want = $this->policyIds();
    if (!$want) {
      return ['do' => FALSE, 'detail' => 'off (no access_policy_ids)', 'ok' => NULL];
    }
    $current = $this->api->getUserPolicyIds($user_id);
    if (!$current->ok) {
      return [
        'do' => TRUE,
        'detail' => 'could not read current policies: ' . $current->describe(),
        'ok' => $execute ? FALSE : NULL,
      ];
    }
    $have = (array) $current->data;
    $missing = array_values(array_diff($want, $have));
    if (!$missing) {
      if ($execute) {
        // Record it so reconcile stops asking.
        return ['do' => FALSE, 'detail' => 'already holds ' . implode(', ', $want), 'ok' => TRUE];
      }
      return ['do' => FALSE, 'detail' => 'already holds ' . implode(', ', $want), 'ok' => NULL];
    }
    $union = array_values(array_unique(array_merge($have, $want)));
    $plan = 'add ' . implode(', ', $missing) . ($have ? ' (keeping ' . implode(', ', $have) . ')' : '');
    if (!$execute) {
      return ['do' => TRUE, 'detail' => $plan, 'ok' => NULL];
    }
    $set = $this->api->setUserPolicies($user_id, $union);
    return ['do' => TRUE, 'detail' => $plan . ($set->ok ? '' : ' — failed: ' . $set->describe()), 'ok' => $set->ok];
  }

  /**
   * Photo step: upload the member headshot when the record has none.
   */
  private function photoStep(string $user_id, int $uid, array $raw, bool $execute): array {
    if (!$this->avatarsEnabled()) {
      return ['do' => FALSE, 'detail' => 'off (provision_avatars)', 'ok' => NULL];
    }
    if (!empty($raw['avatar_relative_path'])) {
      return ['do' => FALSE, 'detail' => 'record already has a picture; not replaced', 'ok' => NULL];
    }
    $file = $this->photoFile($uid);
    if ($file === NULL) {
      return ['do' => FALSE, 'detail' => 'member has no headshot', 'ok' => NULL];
    }
    if (!$execute) {
      return ['do' => TRUE, 'detail' => 'upload ' . $file['filename'] . ' (' . $file['size'] . ' bytes)', 'ok' => NULL];
    }
    $bytes = @file_get_contents($file['path']);
    if ($bytes === FALSE || $bytes === '') {
      return ['do' => TRUE, 'detail' => 'could not read ' . $file['path'], 'ok' => FALSE];
    }
    $up = $this->api->uploadAvatar($user_id, $bytes, $file['filename'], $file['mime']);
    return [
      'do' => TRUE,
      'detail' => 'upload ' . $file['filename'] . ($up->ok ? '' : ' — failed: ' . $up->describe()),
      'ok' => $up->ok,
    ];
  }

  /**
   * The member's first card serial, uppercase hex, or '' when they have none.
   */
  public function cardSerial(int $uid): string {
    return $this->cardSerials($uid)[0] ?? '';
  }

  /**
   * Every card serial on file for the member, uppercase hex, in field order.
   *
   * The user field (all values) first; the main profile only when the user
   * field holds none — the access box export's resolution, but not stopping
   * at the first value. Values that could not come from a reader (not 4-10
   * bytes of hex) are dropped.
   */
  public function cardSerials(int $uid): array {
    $values = [];
    $user = $this->etm->getStorage('user')->load($uid);
    if ($user && $user->hasField('field_card_serial_number')) {
      foreach ($user->get('field_card_serial_number') as $item) {
        $values[] = (string) $item->value;
      }
    }
    $serials = self::normalizeSerials($values);
    if (!$serials && ($profile = $this->mainProfile($uid)) && $profile->hasField('field_card_serial_number')) {
      $values = [];
      foreach ($profile->get('field_card_serial_number') as $item) {
        $values[] = (string) $item->value;
      }
      $serials = self::normalizeSerials($values);
    }
    return $serials;
  }

  /**
   * Uppercase, strip separators, drop impossible values and duplicates.
   */
  public static function normalizeSerials(array $values): array {
    $out = [];
    foreach ($values as $value) {
      $value = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) $value) ?? '');
      if (strlen($value) >= 8 && strlen($value) <= 20 && strlen($value) % 2 === 0) {
        $out[$value] = $value;
      }
    }
    return array_values($out);
  }

  /**
   * The member headshot as a file to upload, or NULL.
   *
   * The 'large' image style derivative when it can be made (small, square
   * enough for the intercom), else the original. JPEG and PNG only.
   *
   * @return array|null
   *   Keys: path (local filesystem), filename, mime, size.
   */
  public function photoFile(int $uid): ?array {
    $profile = $this->mainProfile($uid);
    if (!$profile || !$profile->hasField('field_member_photo') || $profile->get('field_member_photo')->isEmpty()) {
      return NULL;
    }
    $file = $profile->get('field_member_photo')->entity;
    if (!$file) {
      return NULL;
    }
    $uri = $file->getFileUri();
    $style = class_exists(ImageStyle::class) ? ImageStyle::load('large') : NULL;
    if ($style) {
      $derivative = $style->buildUri($uri);
      if (file_exists($derivative) || $style->createDerivative($uri, $derivative)) {
        $uri = $derivative;
      }
    }
    $path = \Drupal::service('file_system')->realpath($uri) ?: $uri;
    if (!is_readable($path)) {
      return NULL;
    }
    $mime = mime_content_type($path) ?: '';
    if (!in_array($mime, ['image/jpeg', 'image/png'], TRUE)) {
      return NULL;
    }
    return [
      'path' => $path,
      'filename' => 'member-' . $uid . ($mime === 'image/png' ? '.png' : '.jpg'),
      'mime' => $mime,
      'size' => (int) filesize($path),
    ];
  }

  /**
   * The member's main profile, or NULL.
   */
  private function mainProfile(int $uid): ?object {
    if (!$this->etm->hasDefinition('profile')) {
      return NULL;
    }
    $profiles = $this->etm->getStorage('profile')->loadByProperties([
      'uid' => $uid,
      'type' => 'main',
    ]);
    return $profiles ? reset($profiles) : NULL;
  }

  /**
   * Console cards keyed by uppercase nfc_id, loaded once per request.
   */
  private function nfcIndex(): ?array {
    if ($this->nfcIndex !== NULL) {
      return $this->nfcIndex;
    }
    $result = $this->api->listNfcCards();
    if (!$result->ok) {
      return NULL;
    }
    $this->nfcIndex = [];
    foreach ((array) $result->data as $row) {
      $id = strtoupper((string) ($row['nfc_id'] ?? ''));
      if ($id !== '') {
        $this->nfcIndex[$id] = $row;
      }
    }
    return $this->nfcIndex;
  }

  /**
   * This member's provisioning record from state.
   */
  private function stateFor(int $uid): array {
    $all = (array) $this->state->get(self::STATE_KEY, []);
    return (array) ($all[$uid] ?? []);
  }

  /**
   * Saves this member's provisioning record.
   */
  private function saveStateFor(int $uid, array $record): void {
    $all = (array) $this->state->get(self::STATE_KEY, []);
    $all[$uid] = $record;
    $this->state->set(self::STATE_KEY, $all);
  }

}
