---
kind: code
---

# Proposal: apps-embed-in-other-sites

## Why

buildiq matrix, row `app-embed-external` ("Embed a built app or one of its pages
in another website", `no`): "grep -rniF 'iframe|embed' src lib (excl. tests,
node_modules): no hits. No embed-token or iframe-safe-headers mechanism found."
The row sits in buildiq's core area (apps).

Four competitors rate it yes, quoted from the matrix:

- NocoBase: "packages/plugins/@nocobase/plugin-embed/src/client-v2/copyEmbedLinkFlow.tsx:50
  "Copy embedded link" menu item on every page, producing /embed/<pageUid>".
- Budibase: "packages/builder/src/settings/pages/embed.svelte:123 generates the
  <iframe> snippet for the workspace or a chosen app at /embed<url>, :25 allowed
  origins".
- Appsmith: "EmbedSettings/index.tsx:103 embed snippet tab, :73 make app public
  toggle ... Embedding a private app needs license_private_embeds_enabled".
- Power Apps: "embed canvas apps in an iframe in websites; only Power Apps users
  in the same tenant can access the embedded app"
  (https://learn.microsoft.com/en-us/power-apps/maker/canvas-apps/embed-apps-dev).

No tender, featureRequest or roadmap row carries it.

The Power Apps cell draws the line this change keeps: an embedded app is used by
people who already sign in to the platform. An organisation puts a built app on
its intranet page; staff use it there without opening Nextcloud first. Showing
a form or records to the public is a citizen-facing surface, and hydra ADR-108
gives that to portaliq.

## What changes

- An "Embed" section in the app's settings: switch embedding on, list the sites
  allowed to frame the app (origins), and copy an iframe snippet for the whole
  app or for one page.
- The runtime route answers an embed request (`?embed=1`) without the Nextcloud
  header and app navigation, and with a `frame-ancestors` policy naming the
  allowed origins. Without embedding on, or for any other origin, framing stays
  refused as today.
- The embedded app shows a sign-in link that opens in a new tab when the viewer
  has no session, instead of a login form inside the frame.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| buildiq | app-embed-external | Embed a built app or one of its pages in another website. | no | an embed switch, allowed origins, a framing policy and a snippet |

## Existing work it builds on

- The published runtime route `/apps/buildiq/builder/{slug}`
  (`openspec/specs/openbuild-runtime/spec.md`), served by
  `DashboardController::builder()`.
- `openspec/specs/app-channel-application/spec.md` and the app settings modal,
  where per-app switches such as `allowUserOverrides` live.

## Sibling halves

- portaliq: anonymous or citizen-facing embedding (a public form or list on a
  municipality's website) is portaliq's under hydra ADR-108. This change does not
  make any page public.
- nextcloud/server: none owed. The framing policy uses the public
  `ContentSecurityPolicy::addAllowedFrameAncestorDomain()` API.

## Out of scope

- Public, anonymous embedding (portaliq).
- Single sign-on into the frame from the host site.
- Sizing the frame to its content through `postMessage`.
