# Repository Guidelines

## Project Structure & Module Organization
- `src/` contains the module PHP code, organized by responsibility:
  - `src/Service/` holds the UniFi API client and sync manager.
  - `src/Form/` contains the admin configuration form.
  - `src/Commands/` provides the Drush command.
- `config/` contains default configuration for `unifi_access_sync.settings`.
- `tests/src/Kernel/` holds PHPUnit kernel tests for the services.
- `unifi_access_sync.*.yml` files define routing, services, permissions, and module metadata.

## Build, Test, and Development Commands
- `drush en unifi_access_sync -y` enables the module.
- `drush cr` clears Drupal caches after changes.
- `drush unifi:sync` runs a full manual reconcile against UniFi Access.
- `phpunit -c core web/modules/custom/unifi_access_sync/tests/src/Kernel/` runs the kernel test suite for this module.

## Coding Style & Naming Conventions
- Follow Drupal coding standards (2-space indentation, braces on the same line).
- Use `StudlyCaps` for class names and `lower_snake_case` for services.
- Keep service classes in `src/Service/` and form classes in `src/Form/`.
- Configuration keys live under `unifi_access_sync.settings` and should be referenced via the config factory.

## Testing Guidelines
- Framework: PHPUnit kernel tests.
- Place new tests in `tests/src/Kernel/` and name them `*Test.php` (e.g., `UnifiSyncManagerTest.php`).
- Focus coverage on reconciliation logic and API pagination or payload changes.

## Commit & Pull Request Guidelines
- Commit history favors short, present-tense summaries (e.g., `Update package`, `add configure link`).
- Keep commits scoped to one logical change when possible.
- PRs should include:
  - A concise description of the behavior change.
  - Any config or schema updates called out explicitly.
  - Test notes (command run or reason tests were skipped).
  - Screenshots only if the admin UI changes.

## Configuration & Security Notes
- UniFi API settings are stored in `unifi_access_sync.settings` and configured via `/admin/config/system/unifi-access-sync`.
- Tokens can be stored in Key module entities when available; avoid hardcoding secrets.
