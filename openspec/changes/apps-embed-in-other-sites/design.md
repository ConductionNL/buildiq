# Design: apps-embed-in-other-sites

Read at buildiq development `d21e42f`; Nextcloud server read in the local
checkout for the public API and the default headers.

## What exists

- `lib/Controller/DashboardController.php:125` `builder(string $slug)`,
  `#[NoAdminRequired]` and `#[NoCSRFRequired]`, marks the app's own nav entry,
  provides `builderSlug` and `builderVersion` as initial state and returns
  `new TemplateResponse(Application::APP_ID, 'builder')` (line 137): the user
  layout, with the Nextcloud header. Route `appinfo/routes.php:124`.
- `templates/builder.php` adds the `buildiq-builder` script and one
  `#content` div; `src/builder.js` mounts `CnAppRoot` with the app's manifest.
- The `Application` schema in `lib/Settings/openbuild_register.json` has
  `slug`, `name`, `description`, `appType`, `status`, `baseRef`,
  `productionVersion`, `icon`, `iconDark`, `allowUserOverrides`, `permissions`,
  `githubRepo`, `githubDefaultBranch`. The app settings modal
  (`src/modals/AppSettingsModal.vue`) already edits `allowUserOverrides`.
- Nextcloud: `TemplateResponse::RENDER_AS_BASE` renders without the header;
  `EmptyContentSecurityPolicy::addAllowedFrameAncestorDomain()` adds a
  `frame-ancestors` source. The server's `.htaccess` sets
  `X-Frame-Options: SAMEORIGIN` (lines 34-35); browsers ignore it when a CSP
  `frame-ancestors` directive is present. The session cookie is `SameSite=Lax`
  (`lib/private/Session/CryptoWrapper.php:67`).

## D1. Embedding is off until a maker lists origins

`Application.embed` is `{enabled: bool, origins: string[]}`, default off. An
origin is a scheme and host (`https://intranet.example.nl`), validated on save:
no path, no wildcard, `https` only. Only owners edit it, like the other app
settings.

## D2. The runtime answers an embed request differently, and only that

`builder()` reads `embed=1`. When the app has embedding on, the response is
rendered as `RENDER_AS_BASE` and carries a policy whose `frame-ancestors` lists
exactly the app's origins. When embedding is off, the request is served as today
with the default policy, so framing stays refused. The app's permissions are
unchanged: the same manifest resolution runs, and a viewer without access sees
what they would see outside the frame.

## D3. No login inside a frame

With `SameSite=Lax`, a frame on a site that is not the same site as Nextcloud
carries no session. The embedded runtime detects the missing session and shows
"Sign in to use this app" with a link that opens Nextcloud in a new tab, then
reloads the frame when the tab reports back. The settings section says that
embedding works on sites that share the Nextcloud domain's site (for example
an intranet on a sibling subdomain).

## D4. One page or the whole app

The snippet is `<iframe src=".../builder/{slug}?embed=1" title="{app name}">`
for the app, or the page's route after the slug for one page. In embed mode the
app navigation is hidden, so a one-page embed shows only that page.

## Risks

- Nginx or other front ends may set their own `X-Frame-Options`. The section
  links to the admin documentation, and the Playwright test asserts the header
  the app sends, not the front end's.
- Clickjacking: only listed origins may frame, and embedding is off by default.

## What it does not do

- It makes nothing public, and adds no route with `#[PublicPage]`.
- It does not pass a session into a cross-site frame.
