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
| `unifi_access_sync.api` | `UnifiApiService` | Low-level HTTP client for UniFi Access Developer API (`/api/v1/developer/users`) |
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
   - Creates missing users, deletes extras

### Configuration

Settings stored in `unifi_access_sync.settings`, configured via `/admin/config/system/unifi-access-sync`:

- `api_host`: UniFi console URL (e.g., `https://<console-ip>:12445`)
- `api_token`: Bearer token with user read/write scopes (or use Key module)
- `use_key_module` / `api_key_id`: Optional Key module integration for secure token storage
- `verify_ssl`: Disable for self-signed certs
- `door_term_id`: Taxonomy term ID representing Door access

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

## Related Modules

- `event_access_unifi`: Creates time-bound visitor passes (QR/PIN) for event registrants
- `access_unifi_bridge`: Receives UniFi Access webhooks, forwards to access_request workflow
