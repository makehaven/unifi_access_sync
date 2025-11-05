## Purpose
`unifi_access_sync` keeps UniFi Access in sync with members who hold an active “Door” badge in Drupal, so card readers continue to work offline.

## Key Services & Hooks
- Service `unifi_access_sync.sync_manager` (`UnifiSyncManager`) handles reconciles and targeted updates.
- Service `unifi_access_sync.api` wraps UniFi REST calls for users.
- Cron hook throttled to once/hour and entity insert/update on `badge_request` trigger incremental syncs.
- Drush command `unifi:sync` runs a manual reconcile.

## Configuration (`unifi_access_sync.settings`)
- `api_host`, `api_token`, `verify_ssl`: UniFi developer API connection.
- `door_term_id`: Taxonomy term ID representing Door access.

## Interactions
- Reads `badge_request` nodes and associated users to determine who “should” exist in UniFi.
- Calls UniFi Access developer API (`GET/POST/DELETE /developer/users`).
- Logs via `logger.channel.unifi_access_sync`.

## Testing Notes
- Provide mock `badge_request` nodes/users in a staging environment and run `drush unifi:sync`.
- For unit/kernel coverage, target `UnifiSyncManager::getShouldHaveAccessEmails()` and `reconcile()` with mocked services.
