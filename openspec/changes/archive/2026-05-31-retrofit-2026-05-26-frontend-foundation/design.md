# Design — frontend-foundation (retrofit)

Retrofit change. Tasks describe retroactive annotation, not new implementation
work. The shared frontend foundation and the preferences endpoint already ship;
this records their observed behaviour as numbered REQs so gate-16 spec-coverage
can trace each method to a requirement. No behaviour is changed.

The composables encapsulate cross-view logic (insights, live preview, manifest
validation, register picking, RBAC role resolution). The object/settings stores
hold the OR base URLs and app settings. The slug utilities enforce the shared
kebab pattern. The settings views read/write app-level config. The
`PreferencesController` is a generic per-user key/value endpoint backed by
Nextcloud IConfig user values, namespaced under `pref_` with a sanitised key.
