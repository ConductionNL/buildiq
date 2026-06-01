# Retrofit — frontend-foundation

Describes observed behaviour of OpenBuild's shared frontend foundation and the
per-user preferences endpoint — the composables
(`useApplicationInsights`, `useLivePreview`, `useManifestValidator`,
`useRegisterPicker`, `useRole`), the Vuex/object stores
(`store/modules/object`, `store/modules/settings`, `store/store`), the slug
utilities (`utils/slugPattern`), the `PermissionsModal`, the settings views
(`settings/AdminRoot`, `settings/Settings`), and the backend
`PreferencesController` — as 5 new REQs.

Code already exists. This change retroactively specifies the foundation
behaviour so gate-16 spec-coverage can trace each method.

## Approach
- Describe observed composable contracts, store actions, slug validation, the
  permissions modal sync/save, settings load/save, and the preferences
  read/write endpoint.
- REQs match behaviour, not aspiration. No behaviour is changed.
