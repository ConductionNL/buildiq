---
kind: code
---

# Proposal: pages-installable-offline-and-mobile

## Why

Two rows describe the same person: somebody out in the field with a phone and a
bad connection. They want the app on the home screen, and they want it to keep
working when the signal drops. No tender, featureRequest or roadmap row carries
either; the competitor cells are the demand.

buildiq matrix, row `pg-offline`, "Let users keep working in an app while offline
and sync later.", rated `no`. Two competitors rated `yes`:

- Mendix: "fully offline-first apps; the client synchronizes the local database
  with the server"
  (https://docs.mendix.com/refguide/mobile/building-efficient-mobile-apps/offlinefirst-data/).
- Microsoft Power Apps: "offline-capable canvas apps sync with Dataverse; only in
  the native Power Apps Mobile players"
  (https://learn.microsoft.com/en-us/power-apps/maker/canvas-apps/offline-apps).

buildiq matrix, row `pg-native-mobile`, "Build a native mobile app for iOS or
Android from the same design.", rated `no`. Two competitors rated `yes`:

- Mendix: "build true native mobile apps and PWAs from a single model using
  navigation profiles" (https://docs.mendix.com/refguide/mobile/).
- Microsoft Power Apps: "wrap packages a canvas app as a custom-branded Android or
  iOS app" (https://learn.microsoft.com/en-us/power-apps/maker/common/wrap/overview).

What buildiq does today, per the matrix `built.evidence`:

- `pg-offline`: "no service worker, no offline cache, no local-first storage for
  the running app anywhere in the repo." The only "offline" hit is the import
  template users fill in and upload later.
- `pg-native-mobile`: "No native mobile build tooling anywhere in the repo."

The hard part already exists one layer down. nextcloud-vue 2.57.1 ships a generic
offline data-collection core, extracted from procest's field-inspection PWA
(`src/integrations/offline/index.js`): an IndexedDB cache with an expiry
(`storePlanning()`, `ttlMs` defaulting to 24 hours, `offlineDb.js:151-165`), a
mutation queue with replay, backoff and conflict handling
(`syncQueueEngine.js`, `syncReplayService.js`), a queue screen
(`CnOfflineQueue`), and a shell service worker that caches the app shell and
bundle and never an object read (`src/offline/serviceWorker.js`, `NEVER_CACHED`),
which a host must register on purpose (`registerOfflineWorker()`). Today only the
built-in `field-inspection` leaf uses it. Buildiq never registers a worker, never
serves a web app manifest, and has no way for a maker to say what an app needs
offline.

## What changes

- A maker makes an app installable: buildiq serves a web app manifest with the
  app's name, short name and icon, so app users add it to the home screen of a
  phone or a desktop and it opens full screen.
- An administrator decides whether apps may work offline on this instance. It is
  off until they allow it.
- A maker says what an app takes along: per schema, a filter (for example "assigned
  to me, due today"), a maximum number of records, and how long the copy may be
  used; and which form pages keep working offline.
- Offline, app users open the records taken along, with the time they were
  downloaded, and submit the offline forms. Submissions wait in a queue, go out
  when the connection returns, and conflicts are shown for a person to settle. A
  "Waiting to sync" page lists what is still on the device.
- The export gains a mobile wrapper project for iOS and Android: the same app, its
  name and icons, opening this instance. The organisation builds, signs and
  publishes it.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | pg-offline | Let users keep working in an app while offline and sync later. | no | a maker's offline declaration, a worker per app, take-along records and queued forms in built apps |
| buildiq | pg-native-mobile | Build a native mobile app for iOS or Android from the same design. | no | an installable app with its own name and icon, and a wrapper project for the app stores |

## Existing work it builds on

- `openspec/specs/openbuild-runtime`: the standalone runtime entry (`src/builder.js`)
  that registers the worker and links the manifest.
- `openspec/specs/app-icon-management`: the app's light and dark icons
  (`icon#iconLight`, `icon#iconDark`, `appinfo/routes.php:175-176`), used for the
  install icon.
- `openspec/specs/form-editor-logic` and `openspec/specs/page-designer-ui`: the form
  page editor that gets the "Works offline" switch.
- `openspec/specs/openbuild-exporter`: the export targets, which gain the wrapper.
- `apps-standalone-deploy` (this pass): its server bundle and this wrapper are both
  export options.

## Sibling halves

- nextcloud-vue owes the generic wiring. Its offline core serves the
  `field-inspection` leaf only. `CnFormPage`, `CnDetailPage` and `CnIndexPage` need
  to read an `offline` block from the manifest: list and open taken-along records
  from the offline cache with their download time when there is no connection, and
  queue a form submission through `enqueueMutation()` instead of failing. The
  manifest schema (`app-manifest-v2.schema.json`) needs that `offline` block. No
  nextcloud-vue change for it exists at development `c8aa858`.

## Out of scope

- Editing existing records offline beyond the declared forms.
- Offline for flows, automations, documents or file uploads.
- Building, signing and publishing the store apps. The organisation does that with
  its own developer accounts.
- Push notifications on the device.
