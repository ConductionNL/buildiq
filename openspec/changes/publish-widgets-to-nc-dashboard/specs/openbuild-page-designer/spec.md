## ADDED Requirements

### Requirement: Promote a widget to the Nextcloud dashboard from the page designer

The page designer SHALL offer a per-widget control that promotes the widget to the user's
Nextcloud dashboard. The control SHALL be off for every existing widget, so opening and
saving a page never promotes anything by itself.

Turning the control on SHALL reveal the panel title and panel icon fields, SHALL write an
`ncDashboard` object onto that widget placement, and SHALL assign the placement a stable
`id` when it has none, because the manifest schema requires an id on any promoted
placement. Turning the control off SHALL remove the `ncDashboard` object and SHALL leave
the `id` in place, so promoting the same widget again restores the same dashboard identity.

The icon field SHALL accept only names from the shared semantic icon set.

All copy on this control follows the Conduction voice: sentence case, no em-dashes.

#### Scenario: Promoting a widget assigns it a stable id

@e2e openbuild-page-designer::promoting-a-widget-assigns-it-a-stable-id

- **WHEN** an author turns on the promote control for a widget that has no id
- **THEN** the widget placement gains an `id` and an `ncDashboard` object
- **AND** the panel title and panel icon fields are shown
- **AND** saving the page stores both

#### Scenario: Demoting a widget keeps its identity

@e2e openbuild-page-designer::demoting-a-widget-keeps-its-identity

- **WHEN** an author turns the promote control off for a previously promoted widget
- **THEN** the `ncDashboard` object is removed from the placement
- **AND** the placement keeps the `id` it was given
- **AND** turning the control on again produces the same dashboard widget id as before

#### Scenario: Existing pages are untouched until an author promotes something

@e2e exclude no-op default state, asserted by a vitest spec that opens and saves an unpromoted page and diffs the manifest

- **WHEN** an author opens a page whose widgets carry no `ncDashboard` object and saves it unchanged
- **THEN** the saved manifest is unchanged
- **AND** no widget is promoted
