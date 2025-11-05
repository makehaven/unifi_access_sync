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

Go to **Config → System → UniFi Access Sync** and set:
- **API Host:** `https://<console-ip>:12445`
- **API Token:** token with user read/write scopes
- **Verify SSL:** uncheck if controller uses self-signed cert
- **Door Term ID:** the taxonomy term ID representing **Door**

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
