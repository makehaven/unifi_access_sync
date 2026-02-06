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
2. Open the **UniFi Access** application.
3. Navigate to **Settings** &rarr; **Control Plane** &rarr; **Integrations**.
4. Generate a new API Token. Copy this immediately as it won't be shown again.
5. Note the console IP/Hostname for the API Host field.

### Drupal Module Setup
Go to **Config → System → UniFi Access Sync** and set:
- **API Host:** `https://<console-ip>` (e.g. `https://192.168.1.1`)
- **API Token:** Paste the token generated above (sent as `X-API-KEY` header).
- **Verify SSL:** Uncheck if using a self-signed certificate (common for local IPs).
- **Door Term ID:** The taxonomy term ID representing the "Door" access level.

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

- User creation payload uses `email` and `name`. Customize mapping in `UnifiSyncManager::userPayloadForEmail()` if you want first/last names from profiles.
- This module **does not** perform unlocks. Pair with your webhook bridge for real-time decisions.
