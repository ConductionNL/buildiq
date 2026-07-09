# Tasks — runtime group-scoped access

## 1. Inject current-user group context
- [ ] 1.1 Controller provides `user-groups` (and `is-admin`, owner markers) via `IInitialState::provideInitialState` on the builder/runtime page.
- [ ] 1.2 `BuilderHost` reads it with `loadState('openbuild','user-groups')`, maps to `['group:<gid>', 'admin'?, 'owner'?]`, and passes as `permissions` to `CnAppRoot` (→ `CnAppNav` / `CnPageRenderer`).
- [ ] 1.3 Unit/component test: `permissions` derived from initial state; admin gets `admin`; owner gets `owner`.

## 2. Manifest permission surface
- [ ] 2.1 Extend the manifest schema/validator: `menu[].permission` and `pages[].permission` (string | string[]).
- [ ] 2.2 `CnPageRenderer` filters routed pages by `permission` (mirror `CnAppNav.passesPermission`); a gated page is not reachable without the permission.
- [ ] 2.3 Multiple dashboards: pick the landing dashboard as the highest-priority dashboard page whose `permission` the user satisfies, else the default.
- [ ] 2.4 Component test: vet sees medical menu + vet dashboard; non-vet does not; admin sees all.

## 3. Author guidance (security boundary)
- [ ] 3.1 Document that `permission` hides navigation only; object-level access MUST be set via OpenRegister `schema.authorization`. Add to the runtime/manifest docs.

## 4. Pet Store demo wiring
- [ ] 4.1 Add `permission: "group:vets"` to the medical menu item(s) and a `MedicalDashboard` page in the demo manifest.
- [ ] 4.2 (Done in OpenRegister) `medicalRecord.authorization.read/create/update/delete = ["vets"]`.

## 5. Verification
- [ ] 5.1 Live: as a `vets` user the medical menu + vet dashboard show and medical objects load; as a non-vet they do not; admin sees everything.
- [ ] 5.2 Frontend gates (ADR-004): initial-state (not DOM) for group data; no admin component in vue-router.
