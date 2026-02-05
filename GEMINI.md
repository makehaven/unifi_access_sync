# UniFi Access Sync Context

This module synchronizes Drupal members with an active "Door" permission to **UniFi Access** via its Developer API. This ensures that even if the Drupal site is offline, local door controllers have a synced list of valid users for access validation.

## Tech Stack
- **Framework:** Drupal 10/11
- **Language:** PHP 8.1+
- **API Client:** Guzzle (Drupal `http_client` service)
- **Integration:** UniFi Access Developer API (`/api/v1/developer/users`)

## Core Architecture

### Services
- `unifi_access_sync.api` (`UnifiApiService`): Handles low-level HTTP communication with the UniFi Access console.
- `unifi_access_sync.sync_manager` (`UnifiSyncManager`): Orchestrates the reconciliation logic between Drupal `badge_request` nodes and UniFi Access users.

### Data Flow
1. **Source of Truth:** Drupal `badge_request` nodes.
   - Specifically where `field_badge_requested` matches the configured **Door Term ID** and `field_badge_status` is `'active'`.
2. **Reconciliation:**
   - **Full Sync:** Triggered by `hook_cron` (hourly) or `drush unifi:sync`. Compares all eligible Drupal users against the current UniFi user list.
   - **Real-time Sync:** Triggered by `hook_entity_insert` and `hook_entity_update` on `badge_request` nodes. Performs targeted add/remove for the specific user.

### Configuration
- Settings are stored in `unifi_access_sync.settings`.
- Configurable via `/admin/config/system/unifi-access-sync`.
- **Key Settings:** `api_host`, `use_key_module`, `api_token`, `api_key_id`, `verify_ssl`, `door_term_id`.
- **Key Module Support:** If the Key module is enabled, users can select a Key entity to securely store the API token.

## Commands & Usage

### Drush
- `drush unifi:sync`: Manually triggers a full reconciliation of all eligible members.

### Cron
- A full reconciliation is throttled to run at most once per hour during regular Drupal cron runs.

## Testing

### Automated Tests
The module includes Kernel tests to verify the synchronization logic and API pagination.
- Run tests via PHPUnit: `phpunit -c core web/modules/custom/unifi_access_sync/tests/src/Kernel/`

### Manual Testing
- Provide mock `badge_request` nodes/users in a staging environment and run `drush unifi:sync`.
