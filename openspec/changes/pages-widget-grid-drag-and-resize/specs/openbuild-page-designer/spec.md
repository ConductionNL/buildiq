# openbuild-page-designer Specification (delta)

## ADDED Requirements

### Requirement: Widget grids are draggable and resizable in edit mode (REQ-OBPD-012)

When a maker opens a published app in Edit with Buildiq mode, the widget grid of every dashboard page and of every detail-page body SHALL become editable: each widget SHALL be movable by dragging its handle and resizable by dragging its edge, snapping to the grid columns and rows. The same moves SHALL be possible by keyboard (focus the widget handle, arrow keys to move, with a modifier to resize). Save SHALL write each widget's new grid position and size into the app's manifest through `PUT /apps/buildiq/api/applications/{slug}/manifest`, and a reload SHALL show the saved layout. Outside edit mode the grid SHALL be static.

**ID:** REQ-OBPD-012

#### Scenario: A maker moves and widens a widget
- **GIVEN** a published app with a dashboard page holding three widgets
- **WHEN** the maker switches to Edit with Buildiq, drags the first widget one column to the right, widens it by one column and saves
- **THEN** the manifest stores the widget's new `x` and `w`
- **AND** after a reload the dashboard shows the widget in its new place and size

#### Scenario: A keyboard user moves a widget
- **GIVEN** the same dashboard in edit mode
- **WHEN** the maker focuses the second widget's handle, presses the right arrow key and saves
- **THEN** the widget moves one column to the right and the manifest stores the new position

#### Scenario: A detail page body
- **GIVEN** a detail page whose body is a widget grid
- **WHEN** the maker resizes a widget in edit mode and saves
- **THEN** the detail page layout in the manifest carries the new size

#### Scenario: A user who is not editing
- **GIVEN** a user opens the dashboard without Edit with Buildiq
- **WHEN** the user drags a widget
- **THEN** nothing moves and the layout is unchanged
