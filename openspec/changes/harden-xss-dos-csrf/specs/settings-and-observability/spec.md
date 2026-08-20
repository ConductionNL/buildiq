## ADDED Requirements

### Requirement: State-changing settings endpoints MUST enforce CSRF protection

The OpenBuild settings write endpoints SHALL NOT carry `#[NoCSRFRequired]`.
`SettingsController::create` (POST `/api/settings`, writes instance-wide config
including `registry_url`/`registry_token`) and `SettingsController::load` (POST
`/api/settings/load`, re-provisions registers/schemas) MUST require a valid
Nextcloud request token. An admin group check in the body does NOT substitute for
CSRF protection, because a forged cross-site request rides the admin's own
session. The SPA already sends the request token via `@nextcloud/axios`, so
enforcing CSRF breaks no legitimate caller.

#### Scenario: Settings write without a valid token is rejected
- **WHEN** a POST to `/api/settings` or `/api/settings/load` arrives without a
  valid Nextcloud request token
- **THEN** the request is rejected by CSRF middleware before the controller runs

#### Scenario: Settings write from the SPA succeeds
- **WHEN** the OpenBuild SPA posts settings with the request token attached
- **THEN** the request is accepted and processed as before

### Requirement: The per-user preference write endpoint MUST enforce CSRF protection

`PreferencesController::setPreference` (PUT `/api/preferences/{key}`) SHALL NOT
carry `@NoCSRFRequired`; it MUST require a valid request token so a forged request
cannot flip a victim's per-user preference flags. The read-only
`getPreference` GET may retain its no-CSRF stance.

#### Scenario: Preference write without a valid token is rejected
- **WHEN** a PUT to `/api/preferences/{key}` arrives without a valid request token
- **THEN** the request is rejected before the preference is written