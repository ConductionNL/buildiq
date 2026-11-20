# Installable apps, offline use and the mobile wrapper

How a buildiq app becomes installable, what it keeps on a device, and what the
mobile app project contains. User-facing steps are in the user guide under
*Install an app and use it offline*.

## Routes

All are signed-in routes, limited to users who may open the app. Every refusal
answers 404, so a stranger cannot tell whether an app exists.

| Route | Answers |
| --- | --- |
| `GET /run/{slug}/` | The app's run page (`dashboard#run`). |
| `GET /run/{slug}/manifest.webmanifest` | The web app manifest: name, short name, theme colour, icons, `start_url` and `scope` set to the run path. |
| `GET /icons/{slug}-touch.png` | A 180-pixel PNG of the app icon, only when the server's Imagick reads SVG. |
| `GET /run/{slug}/offline-worker.js` | The offline worker, with `Service-Worker-Allowed: ./`. 404 unless the instance allows offline apps and the app's manifest has an `offline` block. |

## Worker scope

The worker is registered under `/run/{slug}/`, so it controls only that app's
run page and nothing else on the instance. It carries the shell decisions of
`@conduction/nextcloud-vue`'s offline core and never caches an object read:
records reach the device only through the take-along download below. Its
script is stamped with a content version, so a new release replaces the old
worker.

## Storage

- The instance setting `allow_offline_apps` (`SettingsService`, off by
  default) gates everything. Off means no worker route and no offline settings
  in the editor.
- The manifest's `offline` block holds `takeAlong` (schema, filter, limit up to
  500, hours up to 72) and `forms` (page ids). Buildiq validates it itself
  (`src/services/manifestValidation/offline.js`) until the library schema
  carries it.
- `startOffline()` (`src/services/offlineRuntime.js`) stores each take-along
  set with `storePlanning()` in one collection per app, `buildiq:{slug}`, with
  its expiry. When the app loads without a session it clears that collection,
  so a signed-out device keeps no records.
- Offline form submissions wait in the library's queue; the generated
  `waiting-to-sync` page shows them through `CnOfflineQueue`.

## Mobile wrapper

The export option *Mobile app project* sets `includeMobileProject` and
`mobileBundleId` on the export job (`register.d/85-export-job-mobile-project.json`).
`ExportJobService` refuses a bundle id the stores would not accept (422).
`RunExportJob` hands the bundle id to `ExportService::generateAppZip()`, and
`MobileProjectWriter` writes `mobile/`:

| File | Content |
| --- | --- |
| `capacitor.config.json` | `appId` (the bundle id, default `nl.<instance-host>.<slug>`), `appName`, `server.url` set to the absolute run URL, `server.allowNavigation` holding only this instance's host, cleartext only for an `http://` instance. |
| `package.json` | Capacitor core, Android and iOS, the CLI and the assets tool. |
| `www/index.html` | A link to the run URL, shown only if it cannot load. |
| `resources/icon.png`, `resources/splash.png` | The app icon, rasterised to 1024 and 2732 pixels from SVG when the server can, else the SVG as `resources/icon.svg`. |
| `README.md` | Building, signing, publishing and the embedded-login limit. |

Buildiq builds, signs and publishes nothing and holds no signing key.
