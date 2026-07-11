> Apply-agent note: this change is deliberately mechanical. Before
> writing any file, VERIFY the referenced line numbers and contracts
> against HEAD (they were verified when this spec was authored but the
> tree moves): read `src/views/PageDesigner.vue`,
> `src/components/page-editor/PageListEditor.vue`,
> `src/components/page-editor/LogsPageEditor.vue` (the contract
> template for every new editor), `src/mixins/pageEditorValidation.js`,
> and `tests/components/page-editor/LogsPageEditor.spec.js` (the test
> template) IN FULL first. Do not invent config keys — the per-type key
> inventory is pinned in design.md ("Verified per-type config
> inventory") with library-file citations.

## 1. Add-page picker: types + defaults

- [ ] 1.1 `src/components/page-editor/PageListEditor.vue`: append
      `'map'`, `'roadmap'`, `'search'`, `'wiki'` to the exported
      `PAGE_TYPES` array (line 87 at authoring time) and add four
      `DEFAULT_CONFIGS` entries exactly as REQ-PEC-002 pins them:
      `map: { center: [52.1326, 5.2913], zoom: 7, layers: [], markers: {} }`,
      `roadmap: {}`,
      `search: { register: '', schema: '', facets: [] }`,
      `wiki: { register: '', schema: '' }`. No other change to the add
      flow (id/route/title derivation stays generic).
- [ ] 1.2 `tests/components/page-editor/PageListEditor.spec.js`: extend
      the existing spec — assert `PAGE_TYPES` contains all thirteen
      types, and that confirming an add with type `map` produces a page
      whose `config` deep-equals the pinned map default (REQ-PEC-002
      scenario "Adding a map page seeds the map-shaped default
      config").

## 2. MapPageEditor

- [ ] 2.1 Create `src/components/page-editor/MapPageEditor.vue` by
      cloning `LogsPageEditor.vue`'s structure (SPDX header, props
      `config`/`pageType` (default `'map'`)/`appSlug`/`dataRegisters`/
      `parentRoute`, `emits: ['update:config']`,
      `pageEditorValidationMixin`, `useRegisterPicker` in `setup()`,
      lossless `update(key, value)` clone-and-touch-one-key). Form
      surface per REQ-PEC-003: centre lat + lng number inputs writing
      `center` as `[lat, lng]`; `zoom` number input; `height` text
      input; `layers[]` inline row-list builder (rows: `type` select
      over `tile|wms|wfs|geojson`, `url` text input, add/remove row
      buttons); markers fieldset with a one-of radio — "Source URL"
      branch writes `markers.dataSource.url`, "Register + schema"
      branch writes `markers.dataSource.register` +
      `markers.dataSource.schema` from the picker dropdowns and shows
      the persistent reserved-shape hint (design.md Decision 3);
      `latField`/`lngField`/`popupField` inputs (schema-property
      `<select>` when register+schema bound, `<input type="text">`
      otherwise); `clustering` checkbox. Branch switch mutually clears
      the other branch's `dataSource` keys (only within
      `markers.dataSource` — never touch unsurfaced sibling keys).
      `validatedConfigKeys` returns
      `['center', 'zoom', 'height', 'layers', 'markers']`; every
      surfaced field gets `<InlineFieldMark :error="markFor(key)" />`
      and `:aria-invalid="isInvalid(key)"`.
- [ ] 2.2 `src/views/PageDesigner.vue`: add
      `import MapPageEditor from '../components/page-editor/MapPageEditor.vue'`,
      register it in the `components` block, and add `map:
      'MapPageEditor'` to `SUB_EDITOR_MAP` (line 153 at authoring
      time).
- [ ] 2.3 Create `tests/components/page-editor/MapPageEditor.spec.js`
      modelled on `LogsPageEditor.spec.js` (same `useRegisterPicker`
      mock factory): assert (a) mounting with the REQ-PEC-002 default
      config renders centre/zoom pre-filled; (b) editing zoom emits
      `update:config` preserving an unsurfaced key (REQ-PEC-007
      scenario "Unsurfaced config keys survive a form edit" — seed
      `attributionPosition: 'bottomleft'`); (c) switching the marker
      branch to register+schema clears `markers.dataSource.url` and
      shows the reserved-shape hint (scenario "Map marker-source
      branches are mutually exclusive"); (d) `validatedConfigKeys`
      equals the five keys from task 2.1.

## 3. RoadmapPageEditor

- [ ] 3.1 Create `src/components/page-editor/RoadmapPageEditor.vue`
      (same contract skeleton as task 2.1; `pageType` default
      `'roadmap'`; no register picker needed — omit the
      `useRegisterPicker` setup, keep the `dataRegisters` prop for
      contract uniformity). Form surface per REQ-PEC-004: `repo` text
      input (placeholder `owner/repo`); forge fieldset — `forge.type`
      select over `codeberg|forgejo|gitea|github` plus optional
      `forge.baseUrl` text input, written as one `forge` object
      (delete the whole `forge` key when type is unset); `disabled`
      checkbox; text inputs for `documentationUrl`, `suggestUrl`,
      `openbuiltUrl`, `llmSkillsUrl`; a static hint paragraph naming
      the resolution order (manifest config >
      `features_roadmap_<key>` initialState > built-in fallback) and
      that `features[]` is Raw-JSON-only (design.md Decision 4).
      `validatedConfigKeys` returns `['repo', 'forge', 'disabled',
      'documentationUrl', 'suggestUrl', 'openbuiltUrl',
      'llmSkillsUrl']`; InlineFieldMark + `aria-invalid` on every
      surfaced field.
- [ ] 3.2 `src/views/PageDesigner.vue`: import + `components`
      registration + `roadmap: 'RoadmapPageEditor'` in
      `SUB_EDITOR_MAP`.
- [ ] 3.3 Create
      `tests/components/page-editor/RoadmapPageEditor.spec.js`: assert
      (a) repo/forge fields render and emit shape
      `{ repo, forge: { type, baseUrl } }`; (b) a seeded `features`
      array in `config` survives editing `repo` byte-identically
      (lossless, REQ-PEC-004); (c) unsetting forge type deletes the
      `forge` key; (d) the resolution-order hint text renders.

## 4. SearchPageEditor

- [ ] 4.1 Create `src/components/page-editor/SearchPageEditor.vue`
      (same contract skeleton as task 2.1, WITH `useRegisterPicker`).
      Form surface per REQ-PEC-005: register + schema dropdowns
      (generic scope keys); text inputs for `title`, `placeholder`,
      `searchLabel`, `idleLabel`, `emptyLabel`; `facets[]` inline
      row-list builder — per facet row: `key` text input, optional
      `label` text input, `multiple` checkbox, nested options row-list
      of `{ value, label? }` pairs with add/remove; a static hint that
      search execution is consumer-wired via `@search`.
      `validatedConfigKeys` returns `['register', 'schema', 'title',
      'placeholder', 'searchLabel', 'idleLabel', 'emptyLabel',
      'facets']`; InlineFieldMark + `aria-invalid` per field.
- [ ] 4.2 `src/views/PageDesigner.vue`: import + `components`
      registration + `search: 'SearchPageEditor'` in `SUB_EDITOR_MAP`.
- [ ] 4.3 Create
      `tests/components/page-editor/SearchPageEditor.spec.js`: assert
      (a) adding a facet row with key + two options emits the
      `facets[]` shape `{ key, options: [{ value }, { value }] }`;
      (b) an unsurfaced key (e.g. `facetsTitle`) survives a
      placeholder edit; (c) register change resets schema (same
      partner-clear as LogsPageEditor); (d) the consumer-wiring hint
      renders.

## 5. WikiPageEditor

- [ ] 5.1 Create `src/components/page-editor/WikiPageEditor.vue`
      (same contract skeleton as task 2.1, WITH `useRegisterPicker`
      and its `config.register`/`config.schema` watchers fetching
      schemas + schema properties, exactly like LogsPageEditor). Form
      surface per REQ-PEC-006: required `register` + `schema`
      dropdowns — mark both invalid (InlineFieldMark error +
      `aria-invalid`) when empty; schema-property dropdowns for
      `contentField`, `titleField`, `treeField`, `sidebarTitleField`
      with the schema defaults (`body`, `title`, `children`,
      `titleField`) shown as placeholder/empty-option text and NEVER
      written unless explicitly picked; free-text `idParam`
      (placeholder `id`); sidebar fieldset with `sidebarRegister` +
      `sidebarSchema` picker dropdowns; text inputs for `emptyText`,
      `emptyDescription`, `emptyBodyText`, `emptyBodyDescription`.
      `validatedConfigKeys` returns `['register', 'schema',
      'contentField', 'titleField', 'idParam', 'treeField',
      'sidebarTitleField', 'sidebarRegister', 'sidebarSchema',
      'emptyText', 'emptyDescription', 'emptyBodyText',
      'emptyBodyDescription']`.
- [ ] 5.2 `src/views/PageDesigner.vue`: import + `components`
      registration + `wiki: 'WikiPageEditor'` in `SUB_EDITOR_MAP`.
- [ ] 5.3 Create
      `tests/components/page-editor/WikiPageEditor.spec.js`: assert
      (a) empty register/schema render error marks with `aria-invalid`
      (REQ-PEC-007 scenario "Wiki register and schema are marked
      invalid when empty"); (b) with the picker mock returning schema
      properties `body`/`title`/`children`, the four field-mapping
      dropdowns list them and an untouched mount emits no
      field-mapping keys (scenario "Wiki field mappings offer schema
      properties once bound"); (c) an unsurfaced key survives a
      `titleField` edit; (d) `validatedConfigKeys` matches task 5.1's
      thirteen keys.

## 6. Dispatch fallback: stub narrowed to unknown types only

- [ ] 6.1 `src/views/PageDesigner.vue`: on the centre-pane
      `<component :is="subEditorFor(selectedPage.type)">` binding
      (line 59-66 at authoring time), additionally bind
      `:title="t('openbuild', 'Unsupported page type: {type}', { type: selectedPage.type })"`
      and
      `:message="t('openbuild', 'No visual editor exists for this page type yet. Edit the raw config below; unknown keys are preserved.')"`
      so `StubPageEditor`'s two required props are satisfied whenever
      the fallback mounts (REQ-PEC-001). The four dedicated editors do
      not declare these props; Vue drops them as unused attrs — do NOT
      add per-type conditional template branches.
- [ ] 6.2 `tests/views/PageDesigner.spec.js`: extend the existing
      dispatch coverage — assert `subEditorFor('map') ===
      'MapPageEditor'`, `subEditorFor('roadmap') ===
      'RoadmapPageEditor'`, `subEditorFor('search') ===
      'SearchPageEditor'`, `subEditorFor('wiki') === 'WikiPageEditor'`,
      and `subEditorFor('timeline') === 'StubPageEditor'`; mount with a
      selected `type: 'timeline'` page and assert the stub renders a
      non-empty heading naming the type (REQ-PEC-001 scenario "Unknown
      page type still falls back to the stub with its props bound") and
      that a `type: 'wiki'` selection renders `WikiPageEditor` with no
      stub textarea (scenario "Selecting a wiki page mounts the wiki
      sub-editor, not the stub").

## 7. Playwright e2e — one flow per new editor

- [ ] 7.1 Create `tests/e2e/spec-coverage/page-editor-coverage.spec.ts`
      following the header-comment + structure conventions of
      `tests/e2e/spec-coverage/page-designer-ui.spec.ts` (SPDX header;
      header comment listing each REQ-PEC id with the scenario titles
      it covers — the gate greps this file for scenario references, so
      name the REQ-PEC-002/003/004/005/006 scenario titles explicitly;
      `BASE`/`LIVE` env handling; page-designer route
      `/apps/openbuild/builder/<slug>/pages`; respect the existing
      openbuild#41 quarantine convention if the builder surface is
      still quarantined at implementation time — mirror whatever
      `page-designer-ui.spec.ts` does at HEAD).
- [ ] 7.2 In that file, e2e test 1 (REQ-PEC-003 "Create, configure,
      save and render a map page" + REQ-PEC-002 "Add page lists the
      four new types" / "Adding a map page seeds the map-shaped default
      config"): open the page designer for the seed app, click "Add
      page", assert the type select lists `map`/`roadmap`/`search`/
      `wiki`, pick `map`, assert the map editor opens with centre/zoom
      pre-filled from the default config, set a tile-layer URL and a
      marker source URL, save, navigate to
      `/apps/openbuild/builder/<slug>/map` and assert
      `[data-testid="cn-map-page"]` is visible, then reopen the
      designer and assert the values round-tripped.
- [ ] 7.3 e2e test 2 (REQ-PEC-004 "Create, configure, save and render a
      roadmap page"): add a `roadmap` page, set `repo` to
      `ConductionNL/openbuild` and forge type `github`, save, navigate
      to the page route and assert `.cn-features-and-roadmap-view` is
      visible, reopen and assert repo/forge round-trip.
- [ ] 7.4 e2e test 3 (REQ-PEC-005 "Create, configure, save and render a
      search page"): add a `search` page, set a placeholder and one
      facet with two options, save, navigate to the page route and
      assert `[data-testid="cn-search-page"]` is visible with the
      configured placeholder and both facet options in the sidebar,
      reopen and assert round-trip.
- [ ] 7.5 e2e test 4 (REQ-PEC-006 "Create, configure, save and render a
      wiki page"): add a `wiki` page, bind the seed app's register +
      schema via the dropdowns, pick a `contentField` and `titleField`,
      save, navigate to the page route and assert
      `[data-testid="cn-wiki-page"]` is visible, reopen and assert
      register/schema/field-mapping round-trip.

## Quality reminders (run before requesting review — not tracked as tasks)

- Run `openspec validate page-editor-coverage --strict` and resolve any
  structural errors.
- Run `npm run lint` and `npm run test` (vitest) — all four new specs
  plus the extended `PageListEditor.spec.js` / `PageDesigner.spec.js`
  must pass.
- Confirm the Raw JSON tab shows the identical config object after a
  form edit in each new editor (REQ-PEC-007 round-trip), and that a
  page authored purely in Raw JSON opens in the matching form editor
  with unknown keys intact.

## Acceptance Criteria

- All thirteen canonical v2 page types are creatable from the "Add
  page" picker; `map`, `roadmap`, `search` and `wiki` mount dedicated
  form-based sub-editors, never the raw-JSON stub.
- `StubPageEditor` mounts only for types outside `SUB_EDITOR_MAP`, and
  when it does, its `title`/`message` props are bound (no Vue
  required-prop warning, no empty heading).
- Every new editor preserves unsurfaced config keys byte-identically
  through any form edit and carries InlineFieldMark validation on every
  surfaced key via `validatedConfigKeys`.
- One Playwright flow per new editor proves create → configure → save →
  render against the built app surface
  (`cn-map-page` / `.cn-features-and-roadmap-view` / `cn-search-page` /
  `cn-wiki-page`).
