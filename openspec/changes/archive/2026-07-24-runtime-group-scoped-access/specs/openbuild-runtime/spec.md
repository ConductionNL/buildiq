# openbuild-runtime

## ADDED Requirements

### Requirement: The runtime MUST inject the current user's group context

When rendering a virtual app, the OpenBuild runtime MUST resolve the current
user's group memberships server-side (via initial state, not a client API call)
and supply them to the manifest renderer as the set of permission strings the
user holds (`group:<gid>`, plus `admin` for admins and `owner` for application
owners). When no permission context is available the renderer MUST fall back to
showing all items (no regression for apps without permission fields).

#### Scenario: A vet's group context reaches the renderer
- GIVEN the current user is a member of the `vets` group
- WHEN the virtual app is rendered
- THEN the renderer receives a permissions set containing `group:vets`

#### Scenario: Apps without permissions render unchanged
- GIVEN a manifest whose menu items and pages declare no `permission`
- WHEN any user opens the app
- THEN every menu item and page renders regardless of the user's groups

### Requirement: Menu items and pages MUST be filterable by permission

A manifest `menu[]` item or `pages[]` entry MAY declare a `permission`
(string or list). The runtime MUST render that item/page only when the user
holds at least one of the declared permissions; admins and application owners
MUST see all items. A `permission`-gated page MUST NOT be routable for a user
who lacks the permission.

#### Scenario: Vets-only medical menu and page
- GIVEN the medical menu item and its page declare `permission: "group:vets"`
- WHEN a user in `vets` opens the app
- THEN the medical menu item is visible and its page is reachable
- AND WHEN a user not in `vets` (non-admin) opens the app
- THEN the medical menu item is hidden and its page is not routable

### Requirement: A group-scoped dashboard MAY be the landing page for its group

When more than one dashboard page exists, the runtime MUST land the user on the
highest-priority dashboard page whose `permission` the user satisfies, falling
back to the default dashboard when none match.

#### Scenario: Vets land on the vet dashboard
- GIVEN a default dashboard and a `MedicalDashboard` page with `permission: "group:vets"`
- WHEN a user in `vets` opens the app at its root
- THEN the vet dashboard is shown
- AND a non-vet user is shown the default dashboard

### Requirement: Navigation hiding MUST NOT be treated as object security

Permission-based hiding of menus and pages is a presentation concern only. The
authoritative access control for the data a page reads MUST be enforced by
OpenRegister schema RBAC (`schema.authorization`). The runtime MUST NOT rely on
hidden navigation to protect objects.

#### Scenario: Object access holds even if navigation is bypassed
- GIVEN `medicalRecord.authorization.read = ["vets"]` in OpenRegister
- WHEN a non-vet user requests medical objects directly (bypassing the hidden menu)
- THEN OpenRegister returns no medical objects for that user
