# Design: pages-installable-offline-and-mobile

Read at buildiq development `d21e42f` and `@conduction/nextcloud-vue` 2.57.1.

## Where it sits

- Runtime: `GET /builder/{slug}` (`appinfo/routes.php:124`) and its slash alias
  (line 131) reach `DashboardController::builder()`
  (`lib/Controller/DashboardController.php:125`), which returns the `builder`
  template; `src/builder.js` mounts the app (created at line 483). The designer's
  own routes live under the same prefix (`/builder/{slug}/pages`, comment at
  `appinfo/routes.php:128-130`).
- Icons: `icon#iconLight` and `icon#iconDark` serve SVG (`appinfo/routes.php:175-176`,
  fallback chain in `lib/Service/IconService.php:133-136`).
- Offline core, nextcloud-vue: `src/integrations/offline/` (cache with expiry,
  queue, replay, conflicts), `src/components/CnOfflineQueue/`, and
  `src/offline/serviceWorker.js` with `registerOfflineWorker({scriptUrl, scope})`
  (`src/offline/registerOfflineWorker.js`), which does nothing unless called.
- Settings: `lib/Settings/AdminSettings.php` and `SettingsService` for instance
  settings; `src/modals/AppSettingsModal.vue` for app settings; the form page
  editor `src/components/page-editor/FormPageEditor.vue`.
- Export: `ExportService` targets (ZIP, GitHub) and the export dialog
  `src/dialogs/ExportDialog.vue`.

## D1. An installable app runs under a path of its own

A service worker controls every URL under its scope, and scope is a path prefix.
`/apps/buildiq/builder/vergunningen` is a prefix of the designer's
`/apps/buildiq/builder/vergunningen/pages` and of another app's
`/apps/buildiq/builder/vergunningen-oud`. So an installable app starts at a new
alias, `GET /run/{slug}/` (`dashboard#run`, the same controller method), and its
worker's scope is `/apps/buildiq/run/{slug}/`: one app's runtime, and nothing
else. The old runtime URL keeps working.

## D2. The web app manifest is a signed-in route

`GET /run/{slug}/manifest.webmanifest` is `#[NoAdminRequired]` and answers only a
user who may open the app (the manifest endpoint's rule), with the app's name,
short name, `start_url` and `scope` set to the run path, `display: standalone`,
the theme colour, and the app's icon. The template links it with
`crossorigin="use-credentials"` so the browser sends the session. No public route
is added. iOS wants a PNG touch icon; when the server can rasterise the SVG it
serves one, otherwise the icon falls back to the SVG and iOS shows a screenshot
tile.

## D3. Offline is off until an administrator allows it

An instance setting, "Let apps work offline", is off by default. With it off, the
app setting is hidden, no worker is served, and nothing is stored on devices.
Records on a device are copies outside the server's control until they expire,
so this is the administrator's decision first and the maker's second.

## D4. The maker declares what goes offline

A version's manifest gets `offline: {takeAlong: [{schema, filter, limit, ttlHours}], forms: [pageId]}`,
edited in a new "Offline" section of the app settings and a "Works offline"
switch on form pages. `takeAlong` maps onto the offline core's planning download
(`storePlanning()` with `ttlMs`), capped at 500 records per schema and 72 hours.
The worker script, `GET /run/{slug}/offline-worker.js`, is served by buildiq with
`Service-Worker-Allowed` set to the run scope, and `builder.js` calls
`registerOfflineWorker()` only when the app has an `offline` block and the
instance allows it. It never caches object reads (the library's rule). When the
app loads without a session, the runtime clears the app's offline store, so a
signed-out device keeps no records.

## D5. A "Waiting to sync" page comes with offline

An app with `offline.forms` gets a page with `CnOfflineQueue` and a menu entry,
added by buildiq when the maker saves the offline settings, so app users can see
what is still on the device, retry it, and settle conflicts.

## D6. The wrapper is a project, not a build

A new export option, "Mobile app project", writes `mobile/` into the exported tree:
a Capacitor project with the app's name, bundle id (`nl.<instance-host>.<slug>`,
editable), icons and splash, whose only content is the run URL of this instance.
Navigation outside the instance's origin opens the system browser. Login is
Nextcloud's own login page in the web view. The README says how to build, sign and
publish, which the organisation does. Buildiq builds nothing and holds no signing
key.

## Risks

- An identity provider that blocks embedded web views stops the wrapper's login.
  The README says so, and the installable app (D2) avoids it.
- A device lost while holding records exposes them until they expire. D3 and the
  caps in D4 bound it, and the administrator can turn offline off.
- Two people changing the same record offline conflict. The offline core records
  the conflict and a person settles it; nothing is overwritten silently.

## What it does not do

- It never caches object reads in the worker.
- It builds and signs no store package.
