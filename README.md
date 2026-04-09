# UniFi Access Sync (Drupal 10/11)

Syncs Drupal members (with an **active** "Door" permission) to **UniFi Access** for offline validation.

## What this module does

1. **Member Sync** (resilience/offline)
   - Finds users tied to a `badge_request` node where:
     - `field_badge_requested` == your **Door** taxonomy term ID
     - `field_badge_status` == `active`
   - Ensures these users exist in UniFi Access. Removes users that no longer qualify.
   - Runs via **cron** (hourly throttle) and reacts to `badge_request` insert/update.

> Endpoints used (UniFi Access Developer API):
> - `GET /api/v1/developer/users`
> - `POST /api/v1/developer/users`
> - `DELETE /api/v1/developer/users/{id}`

---

## Install

1. Copy folder to `/web/modules/custom/unifi_access_sync/` (or use the zip provided).
2. Enable: `drush en unifi_access_sync -y` then `drush cr`

## Configure

### UniFi Console Setup
1. Log in to your UniFi Console.
2. Open the **UniFi Access** application (the door/lock icon — **not** the Network app).
3. Navigate to **Settings** &rarr; **Control Plane** &rarr; **Integrations**.
4. Click **Create New API Key**. Copy the token immediately — it won't be shown again.
5. Set the following **permissions** on the key:
   - **People & Groups** → Edit
   - **Visitor** → Edit
   - **Credentials** → Edit
   - **Access Policy** → View
6. Note the console IP/Hostname for the API Host field.

> **Common mistake:** Do not use an API key from the UniFi **Network** app — it must be from **UniFi Access** → Integrations. Network keys will return `401 Unauthorized`.

### Drupal Module Setup
Go to **Config → System → UniFi Access Sync** and set:
- **API Host:** Enter **only the base URL** of your UniFi Console (e.g., `https://unifi.yourdomain.com` or `https://192.168.1.1`). **Do NOT include `/proxy/access/integration/v1/developer/users` or any other path segments.** This module will append the correct API paths automatically.
- **API Token:** Paste the token generated above (sent as `X-API-KEY` header).
- **Verify SSL:** Uncheck if using a self-signed certificate (common for local IPs).
- **Door Term ID:** The taxonomy term ID representing the "Door" access level.

### Important Notes on Resilience and Performance
- **Asynchronous Processing:** All UniFi API calls (create/delete users) are now processed **asynchronously** via Drupal's Queue API. This prevents cron execution timeouts and improves site responsiveness. You can monitor the `unifi_access_sync_queue` via `drush queue:list` and process it with `drush queue:run unifi_access_sync_queue`.
- **Improved Error Handling:** API communication errors are now more robustly handled and logged, providing clearer messages for troubleshooting.
- **Troubleshooting:** If you encounter issues, check the `unifi_access_sync` log channel for detailed error messages. Ensure your `API Host` is correctly configured and accessible from your Drupal environment.

## Usage

### Manual reconcile (Drush)
```
drush unifi:sync
```

### Automatic
- Cron throttles a full reconcile to **once per hour**.
- On `badge_request` insert/update, a targeted add/remove runs for that user.

### Visitors
Time-bound visitor credentials now live in the dedicated `event_access_unifi` module. Enable that module if you need QR/PIN distribution for event registrants.

## Field assumptions

- Content type: `badge_request`
- Fields:
  - `field_badge_requested` (entity reference → **badges** vocabulary)
  - `field_badge_status` (text, value `'active'` for eligibility)
  - `field_member_to_badge` (entity reference → **user**)

Adapt these in code if yours differ.

## Notes


- This module **does not** perform unlocks. Pair with your webhook bridge for real-time decisions.

## Production Re-enable Checklist
Use this before re-enabling on live.

1. Validate API host/token (or Key module key) and rotate credentials if prior testing was unstable.
2. Confirm `door_term_id` matches the active Door taxonomy term in production.
3. Ensure queue processing is healthy:
   - `drush queue:list`
   - `drush queue:run unifi_access_sync_queue`
4. Run a staged reconcile (`drush unifi:sync`) and verify queued create/delete actions are expected.
5. Monitor `unifi_access_sync` logs for at least one full cron cycle after enable.

## Additional Tests To Add
- Kernel test for `unifi_access_sync_cron()` throttling behavior (`unifi_access_sync.last_cron`).
- Kernel tests for entity hook behavior in `unifi_access_sync.module` (insert/update badge_request).
- Unit tests for `UnifiApiService::userPayloadForData()` edge cases (single-word names, empty names, unusual display names).
- Unit tests for queue-worker exception rethrow behavior to verify retry semantics.
