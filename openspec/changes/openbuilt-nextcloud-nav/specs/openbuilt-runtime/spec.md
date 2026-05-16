## MODIFIED Requirements

### Requirement: REQ-OBR-007 ApplicationCard renders icon and omits redundant Live chip

`ApplicationCard.vue` SHALL render the Application's icon in front of the app title using an
`<img>` element whose `src` is the URL of the icon-serving light endpoint
(`/index.php/apps/openbuilt/icons/{slug}.svg`). The image SHALL carry a descriptive `alt`
attribute (the app's name). The component SHALL omit the `Live` chip that was previously
conditionally rendered on `app.currentVersion` (line 30 of the original file); the
lifecycle-status pill (line 23) already communicates "Published" state to the user and the
Live chip produces duplicate signalling. The `ob-app-card__chip--live` CSS rule and the
`v-if="app.currentVersion"` conditional SHALL be removed.

#### Scenario: Published app card shows icon before the title

- **WHEN** a user views the virtual apps index and a published Application has an icon
  registered at the icon endpoint
- **THEN** each ApplicationCard renders an `<img>` element with
  `src="/index.php/apps/openbuilt/icons/{slug}.svg"` before the app name heading

#### Scenario: Card icon falls back gracefully when endpoint returns an error

- **WHEN** the icon endpoint returns a non-200 response (e.g. slug not found)
- **THEN** the `<img>` element's `@error` handler replaces the src with a transparent 1×1
  placeholder or the OpenBuilt default icon path, so no broken-image icon appears in the card

#### Scenario: Live chip is absent from all ApplicationCards

- **WHEN** a user views the virtual apps index and one Application has `currentVersion` set
- **THEN** no element with class `ob-app-card__chip--live` or text "Live" is rendered on
  any card — the Published status pill on the same card is the sole visual indicator

#### Scenario: Card layout and existing fields are not disrupted

- **WHEN** the icon is rendered in front of the title
- **THEN** the title heading, description paragraph, version chip, role chip, and slug chip
  continue to render in their expected positions and the card's click navigation to
  VirtualAppDetail is unaffected
