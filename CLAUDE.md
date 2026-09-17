# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Module Purpose

Syncs Drupal members with active "Door" badge permissions to UniFi Access via its Developer API. This ensures door controllers have a synced user list for offline access validation even when Drupal is unavailable.

## Commands

```bash
# Enable module
drush en unifi_access_sync -y && drush cr

# Manual sync (full reconciliation)
drush unifi:sync

# Run kernel tests
phpunit -c core web/modules/custom/unifi_access_sync/tests/src/Kernel/

# Run a specific test
phpunit -c core web/modules/custom/unifi_access_sync/tests/src/Kernel/UnifiSyncManagerTest.php
```

## Architecture

### Services

| Service | Class | Purpose |
|---------|-------|---------|
| `unifi_access_sync.api` | `UnifiApiService` | Low-level HTTP client for UniFi Access Developer API (`{api_path_prefix}/users`, default `/api/v1/developer`) |
| `unifi_access_sync.sync_manager` | `UnifiSyncManager` | Orchestrates reconciliation between Drupal badge_request nodes and UniFi users |

### Data Flow

1. **Source of Truth**: Drupal `badge_request` nodes where:
   - `field_badge_requested` matches configured Door Term ID
   - `field_badge_status` = `'active'`
   - `field_member_to_badge` references a user with an email

2. **Sync Triggers**:
   - **Cron**: Full reconcile throttled to once/hour
   - **Entity hooks**: `hook_entity_insert`/`hook_entity_update` on `badge_request` for targeted add/remove
   - **Drush**: `drush unifi:sync` for manual full reconcile

3. **Reconciliation Logic** (`UnifiSyncManager::reconcile()`):
   - Compares eligible Drupal emails (`getShouldHaveAccessEmails()`) against UniFi users (`listUsers()`)
   - Creates missing users; deletes extras only when `allow_delete` is TRUE (otherwise logs them)

### Configuration

Settings stored in `unifi_access_sync.settings`, configured via `/admin/config/system/unifi-access-sync`:

- `api_host`: UniFi console URL (e.g., `https://<console-ip>:12445`)
- `api_token`: Access API token (sent as both `Authorization: Bearer` and `X-API-KEY`; port 12445 takes either, UniFi OS on 443 only forwards X-API-KEY)
- `use_key_module` / `api_key_id`: Optional Key module integration for secure token storage
- `verify_ssl`: Disable for self-signed certs
- `door_term_id`: Taxonomy term ID representing Door access
- `allow_delete`: FALSE by default — reconcile logs "Would delete" instead of queueing deletions until this is on (guards the console's non-Drupal users)

### Field Dependencies

The module expects these fields on `badge_request` nodes:
- `field_badge_requested` → entity reference to `badges` vocabulary
- `field_badge_status` → string with value `'active'` for eligibility
- `field_member_to_badge` → entity reference to user

Users need `field_first_name` and `field_last_name` for name extraction (falls back to display name).

## Testing

Kernel tests mock `UnifiApiService` to test sync logic without network calls. Key test coverage:
- `testReconcile`: User creation when missing from UniFi
- `testReconcileRemoval`: User deletion when no longer eligible
- `testSyncSingleByEmail`: Targeted add/remove operations
- Edge cases: missing email, missing door_term_id config

## What the Developer API actually does (measured 2026-09-17, do not re-guess)

These were established by probing the live console. Every one of them was
previously wrong in this module and each caused a silent failure.

**1. Errors arrive inside HTTP 200.** The envelope is `{code, msg, data}` and
`code === 'SUCCESS'` is the only success. A failed create returns
`200 {"code":"CODE_SYSTEM_ERROR","msg":"Server system error."}`; a bad path
returns `200 {"code":404,"codeS":"CODE_NOT_FOUND",...}` — note `code` is a
string in one and an integer in the other. `UnifiApiService::decodeEnvelope()`
is the single place this is handled; route every new call through it.
**Checking the HTTP status alone is what produced 168,763 "created
successfully" log entries against a console that gained nothing.**

**2. The create payload is flat, and the email field is `user_email`.**

| shape | result |
|---|---|
| `{"profile":{"email":...}}` | `CODE_SYSTEM_ERROR` |
| `{"email":...,"first_name":...,"last_name":...}` | `CODE_PARAMS_INVALID` |
| `{"user_email":...,"first_name":...,"last_name":...}` | `SUCCESS` |

`first_name` and `last_name` alone are sufficient. `email` is **read-only** on
create — a created user comes back with `email: ""` and the address in
`user_email`. `user_email` is unique; a duplicate is refused with
`CODE_ADMIN_EMAIL_EXIST`.

**3. Users must be matched on `user_email` OR `email`.** Which field is
populated depends on how the user was made: console UI sets `email`, this API
sets `user_email`. Matching on `email` alone means every user this module
creates looks missing forever and is created again on the next pass — the loop
could not have healed itself even after creates started working.

**4. There is no delete.** `DELETE /users/{id}` returns
`200 {"code":"CODE_SYSTEM_ERROR"}`. Revocation is `PUT /users/{id}` with
`{"status":"DEACTIVATED"}`, which is what `deactivateUser()` does. This is the
failure direction that matters most: the old status-only check reported those
refusals as "deleted successfully", so a revoked member would have kept their
door access with a log line saying otherwise.

## Two independent controls — do not conflate them

`sync_enabled` (config, **ships FALSE**) answers *do we want this module talking
to the door appliance at all*. `MIN_PRESENT_RATIO` (the valve) answers *is the
console's view trustworthy right now*. The switch is checked first, before any
API call, in both `reconcile()` and `syncSingleByEmail()`.

`drush unifi:sync --force` bypasses **the valve only**. A Drush flag is not
consent to start writing at the door, so it will not override the switch — that
is a config change someone makes deliberately.

`drush unifi:status` answers the whole question read-only in one command: the
switch, expected vs present, the valve floor, and exactly how many creates
turning it on would cause. Prefer it over reading watchdog, and run it before
flipping anything.

`hook_requirements()` reports the switch and the valve on the status report,
reading the last reconcile's recorded result from state rather than calling the
API — a 20s timeout on a page load is not worth a number `reconcile()` already
computed. It is silent when healthy.

## The amplification valve

`reconcile()` refuses to enqueue anything when the console holds less than
`UnifiSyncManager::MIN_PRESENT_RATIO` (50%) of the members Drupal expects.

The earlier guard tested `empty($have)` and that was not enough: on 2026-09-15
the console answered with 23 users while Drupal expected 3,309, which is not
zero, so nothing stopped the hourly re-queue of the whole roster — 340,234 log
rows in 61 hours and ~170,000 futile writes at the door.

A ratio catches the sparse case and the empty one, and it doubles as the
backstop for systematically failing creates: if creates stop working the
console never fills, the ratio stays low, and the second run arrests instead of
looping.

**Seeding a genuinely empty console is a deliberate act:**
`drush unifi:sync --force`. Cron never forces. Confirm `api_host` points at the
console you mean to fill before running it — on a full roster this enqueues
thousands of creates.

## Testing

`lando phpunit` is **not** a defined Lando tool in this project — it silently
does nothing and exits 0, which reads exactly like a passing run. Use:

```bash
lando ssh -c "cd /app && SIMPLETEST_DB='mysql://pantheon:pantheon@database/pantheon' \
  vendor/bin/phpunit -c phpunit.xml web/modules/custom/unifi_access_sync/tests/"
```

The container's SQLite (3.34.1) is below Drupal 11's minimum (3.45), so kernel
tests must run against MySQL. They use a random table prefix and coexist with
the site tables.

## Related Modules

- `event_access_unifi`: Creates time-bound visitor passes (QR/PIN) for event registrants
- `access_unifi_bridge`: Receives UniFi Access webhooks, forwards to access_request workflow
