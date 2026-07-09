---
kind: code
---

## Why

The OpenBuild runtime renders a virtual app's manifest the same way for every
user. There is no way to scope parts of an app to a Nextcloud group — e.g. a
Pet Store where only a **vets** group sees the medical menu items, the medical
objects, and a dedicated vet dashboard.

Two of the three layers already exist:

- **Object visibility** is enforceable today via OpenRegister schema RBAC
  (`schema.authorization.read = ["vets"]`). Verified on the Pet Store demo: a
  user in `vets` reads `medicalRecord` objects; a user not in `vets` reads none;
  admin bypasses. No OpenBuild change needed for this layer.
- **Menu filtering** is supported by `CnAppNav` (`@conduction/nextcloud-vue`):
  it filters `manifest.menu[]` items that declare a `permission` against a
  `permissions` prop. **But the OpenBuild runtime never supplies that prop** —
  it does not inject the current user's group memberships into the rendered app,
  so a `permission`-tagged menu item either always shows or never shows.

This change wires the missing user-context layer and adds the manifest surface
to declare group-scoped menus and a group-scoped dashboard.

## What Changes

- **Inject runtime user context.** The runtime (`BuilderHost` / `CnAppRoot`
  mount) resolves the current user's groups (server-provided initial state, not
  a client call) and passes them as the `permissions` set to `CnAppNav` /
  `CnPageRenderer`, so `permission`-gated menu items and pages are filtered
  per user.
- **Manifest: `permission` on menu items and pages.** A menu item or page may
  declare `permission: "group:vets"` (or a list). The renderer shows it only
  when the user holds that permission; admins/owners see everything.
- **Group-scoped dashboard.** Support more than one dashboard page where a
  non-default dashboard carries a `permission`; vets landing on the app see the
  vet dashboard, others see the default.
- **Guard, don't trust the client.** Menu/page hiding is a UX layer; the
  authoritative control remains OpenRegister schema RBAC on the underlying
  objects (already enforced server-side). Document this so authors don't treat
  menu hiding as security.

## Capabilities

### Modified Capabilities
- **openbuild-runtime** — inject current-user group context; filter menus/pages
  by `permission`; support a group-scoped dashboard.

### Referenced (no change here)
- OpenRegister schema RBAC (`schema.authorization`) — the authoritative
  object-level control; the Pet Store sets `medicalRecord.authorization.read =
  ["vets"]`.

## Impact

- Apps with no `permission` fields render unchanged (prop omitted ⇒ all items
  visible).
- Unblocks Pet Store tutorial feature **C** (vets-only medical menu + dashboard).
