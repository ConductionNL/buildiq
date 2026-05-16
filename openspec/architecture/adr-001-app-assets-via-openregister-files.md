# ADR-001: App assets stored as OpenRegister files-attached-to-object

**Status**: accepted

**Date**: 2026-05-15

## Context

OpenBuilt virtual apps need to carry asset payloads alongside their manifest:

- A nav-bar icon (and dark-mode variant) so each published app is recognisable in the Nextcloud top bar (see chain spec [`openbuilt-nextcloud-nav`](../changes/)).
- Plausibly in the near future: logos for the app shell, banner images on landing pages, document templates used by Docudesk integrations, sample/seed files for the apps generated from a virtual app, and user-uploaded files inside the apps once exported.

There are three obvious options for where those bytes live:

1. **Inline in the manifest** — store the SVG/PNG bytes (base64 or raw) as a JSON string on the `application` register record. Self-contained, but bloats the manifest, fights the JSON-schema validator on size, and gives us no per-file metadata (MIME type, version, owner, audit trail).
2. **A separate file store managed by openbuilt** — disk path under `data/openbuilt/`, or a parallel NC Files folder. Keeps blobs out of the register but forces us to invent a second persistence/permission/export mechanism just for app assets.
3. **OpenRegister files-attached-to-object** — OR already supports binding files to a register record, with its own RBAC, versioning, and download endpoints. We get a single persistence model.

OpenRegister files-attached-to-object is already shipping; multiple Conduction apps (e.g. Decidesk, Docudesk) use it for object-level attachments. The pattern is the established Conduction default for "files that belong to a record".

## Decision

**All assets that belong to an OpenBuilt virtual app are stored as files attached to the `application` register record via OpenRegister's files-attached-to-object functionality.** This includes (non-exhaustively) the light/dark nav icons, future per-app logos/banners, document templates, and any user-uploaded blobs surfaced as app features.

Manifest fields reference each asset by file ref (name or UUID), not by inline bytes and not by a path under `/img/`. Example shape for the icon (finalised in the nav-entry spec):

```jsonc
"icon":     { "ref": "app-icon.svg" },
"iconDark": { "ref": "app-icon-dark.svg" }
```

OpenBuilt exposes the asset bytes to the rest of Nextcloud (and to anonymous nav-icon fetches) through a thin OpenBuilt-side endpoint that reads the attached file from OR — never by replicating the bytes into openbuilt's own filesystem.

The export pipeline includes the attached file blobs in the generated app bundle and seeds them into the generated app's own register on install, so exported apps continue to follow the same pattern with zero special-casing.

## Consequences

**Positive:**
- One persistence model for everything attached to an app — no parallel file store, no parallel RBAC.
- Export/clone/transfer of an app drags its assets along automatically because they live on the record itself.
- Future asset features (banners, document templates, user uploads inside generated apps) plug in for free; we never have to "decide where this kind of file goes" again.
- Audit / version / permission semantics for assets are whatever OR already gives us — no re-implementation, no drift.

**Negative / trade-offs:**
- Adds a hard dependency on OR's files-attached-to-object endpoint being installed and reachable. Mitigated: OpenBuilt already requires OR (chain spec #1 application-register).
- Nav-icon rendering needs a small openbuilt-side endpoint that fans out to OR; we don't get to serve the SVG straight from `/img/`. Mitigated: same controller can cache and apply CSP/MIME headers correctly per request.
- A migration step will be needed if/when we ever ship a built-in default icon — defaults live in `/img/`, user-picked icons live in OR. The endpoint must transparently fall back between the two.

## Alternatives considered

- **Inline SVG in manifest** — rejected: blows up manifest size, no version/audit, fragile for binary formats (PNG/JPEG for banners later).
- **Standalone openbuilt file store** — rejected: forces us to reinvent RBAC, export, and lifecycle for a single use case; diverges from the rest of the Conduction fleet which standardises on OR-attached files.
- **Cloning Nextcloud's app-store icon model (`/img/app.svg` on disk)** — rejected: virtual apps are *records*, not Nextcloud apps; they don't have a disk footprint until exported, so an on-disk asset model doesn't fit the runtime.

## Related

- Chain spec `openbuilt-nextcloud-nav` (icon picker UX + nav-entry registration; first consumer of this ADR)
- Chain spec `openbuilt-exporter` (must bundle OR-attached files into the export tarball)
