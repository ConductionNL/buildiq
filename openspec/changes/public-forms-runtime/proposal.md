---
kind: code
---

## Why

OpenBuild's virtual apps are unreachable by anyone who is not an authenticated Nextcloud user with organisation access — every manifest fetch and every object write goes through `#[NoAdminRequired]` endpoints. That closes off the single most-demanded builder feature (Forms#358 32↑, #805 26↑, #624 33↑, #549 20↑, #638 27↑, Tables#1748 21↑) and the canonical municipal use case (intake/melding forms citizens fill in before they have any NC identity). Competing builders (Baserow 2.2) ship secure per-record pre-filled public edit links; Nextcloud itself already has the platform primitive (`PublicShareController`/`#[PublicPage]`, proven by OpenRegister's `CaseTokenController`). OpenBuild has neither a share-token model nor a public rendering/write path.

## What Changes

- **Share-token model** (OR-backed, per ADR-022): a `ShareToken` schema scoping one token to one Application + one page, with expiry, optional password, revoked flag, and an optional `boundObjectId` for per-record edit links. Managed from a new dialog reachable from the page designer and from `AppSettingsModal` (create / revoke / copy link / set expiry).
- **Public runtime controller**: a `#[PublicPage]` controller resolving `{token}` → `ShareToken` → Application manifest, reusing the existing `CnAppRoot` manifest-rendering path but restricted to exactly the token's bound page — no other page, schema, or app in the same Application is reachable through a public token.
- **Anonymous submission endpoint**: `#[PublicPage]` + `#[NoCSRFRequired]` (justified — no NC session exists to carry a CSRF token) + `#[AnonRateLimit]`, writing to OpenRegister through a new server-side service acting with the Application-owner's authorization context (never the OR client API, never the visitor's — there is no visitor identity). Includes a honeypot spam-guard field and an optional email-verification flag per form page.
- **Prefill-from-URL**: token-scoped public page reads allow-listed query params and maps them onto matching form fields at render time (Forms#638 pattern).
- **Per-record edit links**: a token variant bound to one existing object UUID (`boundObjectId`) opens the form pre-filled with that record and updates it (not creates) on submit — the Baserow 2.2 pattern.
- **Manifest `public` config block**: a new optional block on a form/read page's config (`{ enabled, mode: "submit"|"read"|"edit", allowedPrefillFields, honeypotField, requireEmailVerification }`), validated by the new service before a token can be issued against that page.
- **Rate limiting + spam guard**: `AnonRateLimit` on both the render and submit routes; honeypot field is stripped server-side and a filled honeypot silently no-ops the submission (200 without a write, to avoid signalling the guard to a bot).

## Capabilities

### New Capabilities
- `public-form-access`: the `ShareToken` schema and lifecycle (create/revoke/expiry/password/boundObjectId), the token-management UI, the `#[PublicPage]` render controller, the anonymous submission service + spam guard + rate limiting, prefill-from-URL mapping, and per-record edit-link binding.

### Modified Capabilities
- `openbuild-runtime`: the manifest resolution contract gains a token-scoped public path — a public request resolves through `ShareToken` instead of session/organisation auth, and is restricted to the token's single bound page rather than the full Application. (Delta spec at `specs/openbuild-runtime/spec.md`.)

## Impact

- **Schema:** `lib/Settings/openbuild_register.json` — new `ShareToken` schema (openbuild register namespace); manifest page `config` gains the optional `public` block (validated app-side, not in the external nc-vue manifest schema).
- **Backend:** new `PublicFormController` (`#[PublicPage]`), new `ShareTokenService` (issue/revoke/resolve), new `PublicSubmissionService` (owner-context write, honeypot, rate limit), `appinfo/routes.php` gains the public routes registered before the SPA catch-all.
- **Frontend:** new `src/dialogs/ShareTokenDialog.vue` (create/revoke/copy link), wired from the page designer toolbar and `AppSettingsModal`; a public-page bootstrap entry (no Pinia auth store assumptions) that mounts `CnAppRoot` for the single allow-listed page.
- **RBAC:** unaffected for authenticated paths; the new public path is a strictly narrower, token-scoped grant that never touches the existing `permissions` block or organisation scoping.
- **Docudesk/Automation:** none in this change (document generation and approval actions are separate changes).
