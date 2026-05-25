# Design — settings-and-observability (retrofit)

Retrofit change. Tasks describe retroactive annotation, not new
implementation work. The code already exists and ships in OpenBuilt;
this change records the observed behaviour of the settings surface and
the liveness/metrics probes as numbered REQs so gate-16 spec-coverage
can trace each method to a requirement.

## Observed shape
- `SettingsService` owns the read/update/import logic; the controller
  is a thin auth-gated HTTP wrapper over it.
- `InitializeSettings` repair step force-reloads the bundled register
  config at install/upgrade.
- `HealthController` / `MetricsController` are CSRF/admin-exempt but
  still require an authenticated session.

No behaviour is changed by this retrofit.
