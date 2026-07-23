## Context

OpenBuild currently has exactly one authorization posture: an authenticated Nextcloud user in the Application's organisation, gated by the `permissions` RBAC block (`openbuild-rbac`). Every manifest fetch (`ApplicationsController::getManifest`) and every object write goes through OpenRegister's authenticated objects API via `useObjectStore` — there is no anonymous path anywhere in the stack. Precedent for anonymous access exists at the platform level (`OCP\AppFramework\Http\Attribute\PublicPage`, `OCP\Share`) and inside the ecosystem (OpenRegister's `CaseTokenController`, a `#[PublicPage]` citizen endpoint resolving a token to a scoped case view). This change is the first `#[PublicPage]` surface in OpenBuild.

Constraints: OpenBuild owns no DB tables (ADR-022) — the `ShareToken` model must be an OR-backed schema in the `openbuild` register namespace, not a bespoke table. Anonymous writes have no NC session, so CSRF protection (which relies on a session-bound token) does not apply and must be replaced with rate limiting + spam guard (`#[AnonRateLimit]`, honeypot) per the platform's own guidance for anonymous POST endpoints. The manifest itself is validated against the external `@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`, which this change does not modify — the `public` block lives inside a page's `config`, a free-form object already permitted by that schema, and is validated by OpenBuild's own service, not by the external schema.

## Goals / Non-Goals

**Goals:**
- A token names exactly one Application + one page. Resolving a token never exposes any other page, schema, or object in the Application.
- Anonymous submission writes through a server-side service using the Application owner's authorization context — never a visitor identity (none exists) and never the OR client API directly from an unauthenticated frontend.
- Prefill-from-URL and per-record edit links are both expressions of the same `ShareToken` model (`boundObjectId` null vs set), not two separate features.
- Rate limiting and a honeypot spam guard are non-optional on every anonymous route.
- Token management (create/revoke/expiry/password) is reachable from both the page designer (in-context) and `AppSettingsModal` (app-wide overview).

**Non-Goals:**
- Anonymous read/write to arbitrary schemas or pages not explicitly bound to a token — no "make the whole app public" switch.
- Visitor accounts, magic-link login, or any form of anonymous *identity* — submissions are attributed to the token, not to a person.
- Changing the authenticated RBAC model (`openbuild-rbac`) — the public path is additive and orthogonal.
- File uploads on public forms (deferred — needs a separate virus-scan/quota design).

## Decisions

### D1 — `ShareToken` is its own OR schema, not a field on `Application`
**Choice:** A new `ShareToken` schema in `lib/Settings/openbuild_register.json` (`applicationId`, `pageId`, `token` (opaque, generated server-side), `mode: submit|read|edit`, `boundObjectId?`, `expiresAt?`, `passwordHash?`, `revoked`, `allowedPrefillFields[]`, `honeypotField`, `requireEmailVerification`).
**Why:** An Application can have many tokens (one per page, one per shared record); embedding them as an array on `Application` would fight OR's object-level RBAC and make revocation (a single-row update) awkward. A dedicated schema gets its own lifecycle, its own list query, and matches the `BuiltAppRoute` precedent (a small lookup schema keyed by an opaque string) already in this register.
**Alternative considered:** Store tokens as a manifest page-config array. Rejected — the manifest is meant to be portable/exportable (Phase-2 export ships it verbatim); tokens are environment-specific secrets and must never be exported inside a manifest blob.

### D2 — Public resolution is a dedicated controller, not a branch inside `ApplicationsController::getManifest`
**Choice:** `PublicFormController::render(string $token)` (`#[PublicPage]`, `#[NoCSRFRequired]`, `#[AnonRateLimit]`) resolves `ShareToken` → `Application` → the single bound page's manifest fragment, and returns a minimal manifest containing only that page, its schema, and its widgets — not the full Application manifest.
**Why:** Keeping the public path in its own controller means the authenticated `getManifest` code path is never touched by anonymous-request handling — no risk of a future refactor accidentally widening the public surface. It also lets the response be a deliberately *reduced* manifest (single page) rather than the full one, which the authenticated endpoint always returns.
**Alternative considered:** Add a `?token=` query param to the existing manifest endpoint. Rejected — mixes two authorization postures (session-based and token-based) in one method, raising exactly the kind of semantic-auth-mismatch class of bug the fleet gates watch for.

### D3 — Anonymous writes go through `PublicSubmissionService` acting as the Application owner, not through `useObjectStore`
**Choice:** `PublicSubmissionService::submit(ShareToken $token, array $data)` validates the honeypot field is empty, validates `$data` against the target schema, then calls OR's `ObjectService` using a `SystemOperationContext`-style elevated context scoped to the Application owner's organisation — mirroring the pattern OpenRegister itself uses for its own system-context writes. The visitor's HTTP request never touches an authenticated OR endpoint.
**Why:** There is no visitor identity to authorize against; the only coherent authorization story is "the app owner configured this token to accept this kind of write, so the write happens as the app owner." This mirrors OR's own `CaseTokenController` pattern (token → elevated service-context write), not a new invention.
**Alternative considered:** Mint a throwaway NC guest session per submission. Rejected — heavier, and NC guest sessions are not a first-class supported primitive for this kind of anonymous-write flow.

### D4 — `boundObjectId` unifies prefill-from-URL and per-record edit links
**Choice:** A token with `mode: submit` and no `boundObjectId` is a plain create-form share (optionally prefilled from allow-listed query params). A token with `mode: edit` and a `boundObjectId` set opens the same form pre-filled from that object's current values and, on submit, updates that object instead of creating a new one.
**Why:** Both are "render a form, optionally pre-fill it, write somewhere" — modelling them as two values of the same `mode` field (rather than two schemas or two controllers) keeps the render/submit code path singular and halves the surface needing rate-limit/spam-guard/validation coverage.
**Alternative considered:** A separate `RecordEditLink` schema. Rejected — near-total duplication of `ShareToken`'s fields for no behavioural gain.

### D5 — Honeypot + rate limit are enforced server-side only; the field is never validated client-side
**Choice:** The honeypot field name is chosen per-token (`honeypotField`, default a randomised name at creation) and rendered as a visually-hidden input. `PublicSubmissionService` rejects (silently 200, no write) any submission where that field is non-empty. `#[AnonRateLimit]` caps both the render and submit routes per IP.
**Why:** A honeypot only works if a scraping bot cannot detect it, which requires the field name not be a static, greppable constant — this is the reason it is per-token rather than hardcoded.
**Alternative considered:** CAPTCHA. Rejected for v1 — adds a third-party dependency and accessibility friction (WCAG conflict for some CAPTCHA implementations); honeypot + rate limit is the same first line of defence Forms#549 asks for.

### Declarative-vs-imperative decision (ADR-031)
The `ShareToken` schema fields (expiry, revoked, mode, boundObjectId) are declarative OR properties. Token resolution (opaque-string → Application → page lookup), the elevated-owner-context write, and the honeypot/rate-limit guard logic are **imperative**, justified under ADR-031's external-integration and security-boundary exceptions: this is a cross-object lookup crossing an authorization boundary (anonymous → owner-context), which is exactly the class of logic ADR-031 reserves for services rather than declarative schema metadata.

## Risks / Trade-offs

- **A leaked/guessed token exposes the bound page to anyone** → tokens are cryptographically random (≥128 bits), never sequential/guessable; `expiresAt` and manual revoke bound the exposure window; the response never includes other pages/schemas.
- **Honeypot degrades for sighted assistive-tech users if mis-marked** → the hidden input uses `aria-hidden="true"` + `tabindex="-1"` + off-screen CSS (not `display:none`, which some screen readers still announce inconsistently) and carries no visible label — WCAG-safe hidden-field pattern.
- **Owner-context write bypasses the visitor's (nonexistent) permission check** → the write is scoped to exactly one schema, one Application, one owner, and only for tokens explicitly marked `mode: submit|edit`; `mode: read` tokens never reach `PublicSubmissionService` at all.
- **Rate limiting alone does not stop a slow/distributed bot** → acceptable for v1 (matches Forms#549's ask); CAPTCHA/verification-email upgrade path is the `requireEmailVerification` flag, deferred to a follow-up if abuse is observed.
- **Per-record edit links let anyone with the link edit that record indefinitely** → `expiresAt` is mandatory (not optional) for `mode: edit` tokens specifically, defaulting to a short window (e.g. 30 days), distinct from the optional expiry on `submit`/`read` tokens.

## Migration Plan

1. Add the `ShareToken` schema to `lib/Settings/openbuild_register.json` (additive, no existing schema touched).
2. Land `ShareTokenService` (issue/revoke/resolve/validate-password) + `PublicSubmissionService` (honeypot + rate-limit + owner-context write) + `PublicFormController`.
3. Register the public routes in `appinfo/routes.php`, before the SPA catch-all, per the existing specific-first ordering convention.
4. Add the `public` block to the page-config editor (page designer) and the manifest `config.public` validation in `ShareTokenService::issue()` (a token cannot be created for a page whose config does not declare `public.enabled: true`).
5. Ship `ShareTokenDialog.vue` wired from the page designer toolbar and `AppSettingsModal`.
6. No data migration — the feature is fully additive; zero impact on existing Applications until a page owner opts a page into `public.enabled`.

**Rollback:** Remove the public routes from `appinfo/routes.php` (public URLs 404) and stop rendering `ShareTokenDialog`; existing `ShareToken` records become inert (harmless orphan OR objects). No data loss for authenticated app data — only the public surface disappears.

## Open Questions

- Should `mode: read` tokens (public read-only pages, no form) be in this same change or deferred? Lean: include — the scope explicitly lists "public read pages" alongside form submission, and it reuses the identical resolution path with zero extra write-side risk.
- Where does per-page `public.enabled` get toggled — a checkbox in the existing page-config panel, or only implicitly by creating a token? Lean: explicit checkbox in page config (matches "declared in the page config, validated" in the proposal) so a page's public-eligibility is visible without cross-referencing the token list.
- Does `requireEmailVerification` block the write until verified, or accept-then-flag? Lean: accept-then-flag (write happens immediately, object gets an `emailVerified: false` field an owner can filter on) — blocking would need an email-sending dependency this change does not otherwise require.

## Seed Data

Example `ShareToken` object (OR record in the `openbuild` register):

```json
{
  "id": "00000000-0000-0000-0000-000000000000",
  "applicationId": "00000000-0000-0000-0000-000000000000",
  "pageId": "melding-intake-form",
  "token": "<opaque-random-token>",
  "mode": "submit",
  "boundObjectId": null,
  "expiresAt": "2026-12-31T23:59:59Z",
  "passwordHash": null,
  "revoked": false,
  "allowedPrefillFields": ["postcode", "straat"],
  "honeypotField": "<random-field-name>",
  "requireEmailVerification": false
}
```

Example per-record edit-link token:

```json
{
  "id": "00000000-0000-0000-0000-000000000000",
  "applicationId": "00000000-0000-0000-0000-000000000000",
  "pageId": "melding-detail-form",
  "token": "<opaque-random-token>",
  "mode": "edit",
  "boundObjectId": "00000000-0000-0000-0000-000000000000",
  "expiresAt": "2026-08-22T00:00:00Z",
  "passwordHash": null,
  "revoked": false,
  "allowedPrefillFields": [],
  "honeypotField": "<random-field-name>",
  "requireEmailVerification": false
}
```

Example manifest page `config.public` block (embedded in the existing page config, not a top-level manifest property):

```json
{
  "public": {
    "enabled": true,
    "mode": "submit",
    "allowedPrefillFields": ["postcode", "straat"],
    "requireEmailVerification": false
  }
}
```
