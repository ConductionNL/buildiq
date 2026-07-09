## ADDED Requirements

### Requirement: Icon-only action buttons carry an accessible name

Every `NcButton` in the schema designer's sub-editors (`LifecycleEditor.vue`, `WidgetEditor.vue`, `RelationEditor.vue`, and the shared editor pattern they follow) that renders only an icon in its `#icon` template slot — with no visible text label — SHALL carry an `:aria-label` (or equivalent accessible-name prop) describing the action, sourced through `t('openbuild', '...')` with an English-language key.

#### Scenario: Remove-state button exposes its action to assistive technology

- **GIVEN** the Lifecycle sub-editor rendering a list of states
- **WHEN** a screen reader user tabs to the remove-state icon button
- **THEN** the accessible name SHALL announce "Remove state" (or the localized equivalent), not a bare "button"

#### Scenario: Remove-transition, remove-action, remove-widget, remove-relation buttons are equally labelled

- **GIVEN** the Lifecycle, Widget, and Relation sub-editors
- **WHEN** a screen reader user reaches any icon-only remove button in these editors
- **THEN** each SHALL announce a distinct, action-specific accessible name ("Remove transition", "Remove action", "Remove widget", "Remove relation")
