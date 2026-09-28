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
   - **and that user is not blocked and holds the `member_role` role** (default
     `member`). The badge is a qualification and is never revoked (JR,
     2026-09-21); the role is the membership. Badge alone = 3,314 accounts,
     ~2,470 of them former members. Badge + role = 848.

2. **Sync Triggers**:
   - **Cron**: Full reconcile throttled to once/hour
   - **Entity hooks**: `hook_entity_insert`/`hook_entity_update` on `badge_request` for targeted add/remove
   - **Drush**: `drush unifi:sync` for manual full reconcile

3. **Reconciliation Logic** (`UnifiSyncManager::reconcile()`):
   - Compares eligible Drupal members (`getShouldHaveAccessUserData()`) against UniFi users (`listUsers()`), matched on `user_email` or `email`, with each record's `status`
   - Missing → `create`; present but `DEACTIVATED` → `reactivate`; present and active → nothing
   - Active extras → `deactivate` only when `allow_delete` is TRUE (otherwise logged); extras already `DEACTIVATED` are ignored
   - The amplification valve counts **active** records only

### Configuration

Settings stored in `unifi_access_sync.settings`, configured via `/admin/config/system/unifi-access-sync`:

- `api_host`: UniFi console URL (e.g., `https://<console-ip>:12445`)
- `api_token`: Access API token (sent as both `Authorization: Bearer` and `X-API-KEY`; port 12445 takes either, UniFi OS on 443 only forwards X-API-KEY)
- `use_key_module` / `api_key_id`: Optional Key module integration for secure token storage
- `verify_ssl`: Disable for self-signed certs
- `door_term_id`: Taxonomy term ID representing Door access
- `allow_delete`: FALSE by default — reconcile logs "Would revoke" instead of queueing deactivations until this is on (guards the console's non-Drupal users)
- `member_role`: `member` by default — the role a door-badged account must hold to count as a current member; empty disables the check

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

**2. The create payload is flat, and the email field is `user_email`** — which
this module no longer sends; see "Never send the console an email address".

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

**5. Reactivation is `PUT /users/{id}` with `{"status":"ACTIVE"}`.** Verified
on the live console 2026-09-21 in both directions on a test record
(`200 {"code":"SUCCESS"}`, read-back confirmed). `reactivateUser()` and
`deactivateUser()` share `setUserStatus()`. This matters because of the
2026-09-18 incident (see the site's `docs/DEPLOY_TODO.md`): Pantheon dev
mass-created ~1,224 real records on the production console, which were then
deactivated. The console now holds 1,250 records, 23 active and 1,227
DEACTIVATED, and 372 of the deactivated ones are current members. Creating those again is
refused (`CODE_ADMIN_EMAIL_EXIST`); they have to be switched back on.

**A record with no `status` is treated as active.** The other reading would
queue a reactivation per member per hour if the console ever stopped sending
the field — a new runaway. Only an explicit `DEACTIVATED` triggers a write.

## Never send the console an email address (2026-09-27)

**A console user that has an email address gets UniFi's "Welcome to UniFi
Identity!" invitation** from `identity@ui.com` ("You have been invited to
access UniFi Identity resources on this site: MakeHaven Dream Machine Pro",
UniFi Endpoint download links, 7-day credential). That is what members
received on 2026-09-18, when every record the dev mass-create made carried
`user_email` (evidence: Phil Bernstein's forward to JR, 09-21). The Developer
API has no parameter to suppress it: the documented invitation endpoint
`POST /users/identity/invitations` (API ref §10.1) is not something we call,
and the console-side "UniFi Endpoint Email Invite → Send Automatically To New
People" setting fires on the console's own terms.

So:
- **Creates are `{first_name, last_name, employee_number}` only.**
  `employee_number` = `drupal-{uid}` (API ref §3.2, optional free text) is the
  join key; `fetchUnifiUsers()` indexes records by address AND by
  `#uid:{uid}`, and `findRecord()` tries address first, then uid.
- `createUser()` **refuses** any payload containing `user_email` or `email`,
  before any HTTP request. Do not remove that guard.
- The queue worker drops a `create` item with no `uid` (it could never be
  matched again → hourly re-create).
- **Reactivation** is `PUT {"status":"ACTIVE"}` only, so it cannot add an
  address. But the ~368 switched-off current members already carry the
  address from 09-18, and whether re-activating one re-sends the invitation is
  **untested**. They are held (`reactivate_emailed_records`, default FALSE,
  update 9004) and reported by `unifi:status` as "Reactivation held".
- `drush unifi:sync-one <email>` plans (and with `--execute` performs) the
  sync for one current member, printing the exact payload. It does not need
  `sync_enabled` (flipping that to test one record would open cron and the
  badge hooks) but does need live. `--reactivate-emailed` is the one-record
  trial that decides `reactivate_emailed_records`.

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

## Intercom provisioning: card, door, photo (2026-09-28)

A console record with a name does not open the UA-Intercom. `UnifiProvisioner`
(service `unifi_access_sync.provisioner`) adds, for ACTIVE records of current
members, each behind its own setting and **all off by default** (update 9005):

| Setting | What it does | API (permission) |
|---|---|---|
| `provision_nfc_cards` | Imports the member's `field_card_serial_number` (user field, then main profile; uppercase hex; byte-for-byte what the intercom reports, verified 2026-09-14) with alias `drupal-{uid}`, then binds it. `force_add` is always false: **a card bound to another record is never moved**, only reported. | `GET /credentials/nfc_cards/tokens` (view:credential), `POST /credentials/nfc_cards/import` CSV `nfc_id,alias` (edit:credential), `PUT /users/{id}/nfc_cards` (edit:user) |
| `access_policy_ids` | **Adds** these policies to the member's direct policies. The PUT **replaces** the whole list, so the current list is read first and the union written; an empty list is refused outright. | `GET /users/{id}/access_policies?only_user_policies=true`, `PUT /users/{id}/access_policies` (edit:user); `drush unifi:policies` lists ids (view:policy) |
| `provision_avatars` | Uploads the member headshot (`profile.main.field_member_photo`, 'large' derivative, JPEG/PNG) **only when the record has no picture**; a console-set picture is never replaced. | `POST /users/{id}/avatar` multipart (edit:user; local users only, which is what we create) |

**Never a record with an email address (hard rule, no setting).** UniFi mails
its Identity invitation to console users that carry an address, and the
console's auto-invite also fires when access is *granted* to one — the
2026-09-18 incident. `UnifiProvisioner::carriesEmail()` (user_email, email,
profile.email, or a `has_email` flag carried through the queue) makes every
step skip such a record, in reconcile, the queue worker and `sync-one`. Our
own creates carry no address, so members are unaffected; staff or 09-18
leftovers that do carry one must be handled by hand in the console.

**Flood control.** `reconcile()` queues `provision` items only when
`needsWork()` says so, using just the listUsers row and state: no API call per
member per hour. Any attempt (success or failure) is recorded in state
`unifi_access_sync.provision[uid]`, and a member is not looked at again for
`RETRY_AFTER` (a day). The door policy is remembered per uid, so it is checked
once, not hourly. A record that already holds any card is not re-checked for
cards (the list shows only a display id, not the serial).

**Trial path:** set the settings, then `drush unifi:sync-one <email>` (plan)
and `--execute` on live. For an already-ACTIVE member it provisions directly;
for a new member it creates, then provisions using the id from the create
answer. `unifi:status` shows "To provision".

**The 09-14 token lacks view:policy** (`GET /access_policies` →
`CODE_UNAUTHORIZED`). Reissue it with view:policy, edit:user, view:credential
and edit:credential before switching the door step on.
