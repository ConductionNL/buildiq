## ADDED Requirements

### Requirement: Component styling consumes Nextcloud CSS color variables

All color declarations in Buildiq component CSS (`color`, `background`, `background-color`, `border-color`) SHALL use Nextcloud CSS variables (`var(--color-*)`) — optionally with a literal fallback inside the `var()` — so the UI renders correctly under dark mode and `nldesign` government theming. Semantic states SHALL map to the semantic variable families: success → `--color-success*`, warning → `--color-warning*`, brand/primary → `--color-primary-element*`, muted text → `--color-text-maxcontrast`, neutral chip backgrounds → `--color-background-dark`. A literal color outside a `var()` fallback SHALL only appear where the surface intentionally does NOT track the theme (the icon-preview light/dark simulation canvases), and every such exemption SHALL carry an inline `/* intentional: ... */` comment naming the reason.

#### Scenario: Badges are readable in dark mode

- **GIVEN** the application-detail header status/role/semver/type badges and the groups-widget role chips
- **WHEN** the instance switches to the dark theme
- **THEN** badge text and background SHALL both resolve through NC variables to theme-correct values
- **AND** no badge SHALL render dark hardcoded text (e.g. `#555`) on a dark background

#### Scenario: Text on brand-colored surfaces uses the paired text variable

- **WHEN** a label sits on `var(--color-primary-element)` or `var(--color-success)` background
- **THEN** its text color SHALL be the paired `-text` variable (`--color-primary-element-text`, `--color-success-text`), not a literal `#fff`

#### Scenario: Theme-simulation canvases are exempt and documented

- **WHEN** the icon upload/review previews render their fixed light and dark canvases
- **THEN** those canvases MAY use literal colors that do not track the active theme
- **AND** each such declaration SHALL carry an inline comment marking it intentional

### Requirement: Built apps follow the user's dark mode

A published built app SHALL style its pages only with Nextcloud CSS color variables (through the shared renderer components), so that when a user has chosen the dark theme in their Nextcloud settings the built app SHALL render with dark backgrounds and light text, without a per-app setting.

#### Scenario: A built app opens in dark mode

- **GIVEN** a user whose Nextcloud theme is set to dark
- **WHEN** they open a published built app with an index page and a detail page
- **THEN** both pages render on the dark main background with readable text
- **AND** no page area keeps a fixed light background
