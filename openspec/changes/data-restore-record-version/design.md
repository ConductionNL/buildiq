# Design: data-restore-record-version

Read at buildiq development `d21e42f`, openregister development `ae898b0` and
`@conduction/nextcloud-vue` 2.57.1.

## Where it sits

- Server, openregister (not touched): `revert#revert`
  (`appinfo/routes.php:1453`) reaches `RevertController::revert()`, which takes
  `datetime`, `auditTrailId` or `version` and answers 404, 403 or 423 on the
  matching failure. `RevertHandler::revert()` requires `update` permission on the
  object (`lib/Service/Object/RevertHandler.php:148-158`) and refuses a record
  locked by someone else (lines 163-167). `AuditTrailMapper::revertObject()`
  bumps the patch version unless told to overwrite it
  (`lib/Db/AuditTrailMapper.php:1537-1542`).
- Renderer, nextcloud-vue 2.57.1: `CnObjectSidebar` renders a `tabs` array
  whose tabs hold `widgets` (`src/components/CnObjectSidebar/CnObjectSidebar.vue:170-205`),
  maps the widget type `audit` to `CnAuditTrailTab` (line 253), and binds each
  widget's own `props` (`widgetBindings()`, line 940). `CnAuditTrailTab`
  (420 lines) has filters, a list and an expandable detail per entry, and no
  action. The store's lifecycle plugin has `revertObject()`
  (`src/store/plugins/lifecycle.js:156`).
- Authoring, buildiq: `src/components/page-editor/DetailPageEditor.vue:65-126` is
  the sidebar fieldset (object, boolean or none shape) with a
  `SidebarTabBuilder` for `config.sidebar.tabs` (lines 121-123), written through
  `updateSidebarKey()`. `SidebarTabBuilder.vue` edits id, label, icon and
  component and keeps any other key on a tab when it edits one field.

## D1. A named section, not another row in the tab builder

The tab builder asks for a registry key, which a maker should not need to know
to get a record's history. The detail page editor gets a "Record history"
section with two switches. "Show record history" adds, or removes, one tab in
`config.sidebar.tabs`:

`{ id: 'history', label: 'History', icon: 'History', widgets: [{ type: 'audit', props: { allowRestore: false } }] }`

"Let users restore an earlier version" flips `allowRestore` on that tab's audit
widget. The tab keeps the stable id `history`, so the section finds it again and
the tab builder shows it like any tab. Turning history on switches the sidebar to
its object shape when it was unset, the shape the editor already calls preferred.

## D2. Restore is off unless the maker turns it on

A history is safe to show; a restore changes data. So `allowRestore` defaults to
`false` in the tab buildiq writes and in the renderer (sibling half). An app made
before this change gets no button.

## D3. The server decides who may restore

The button follows the same signal as the page's Edit action, so a user who
cannot edit never sees it, but that is a courtesy. The control is OpenRegister's
check in `RevertHandler` (update permission, then lock). Buildiq adds no route
and no permission of its own. The renderer shows the server's refusal as a
message: "You cannot restore this record." for 403, and the lock holder's name
for 423.

## D4. Restore by audit entry

The renderer restores to "the state after this entry" by sending that entry's
`auditTrailId`, which is what the list already holds. Restoring by date or by
version number is left out of the interface; the route keeps accepting them.

## Risks

- `RevertHandler` saves through `ObjectEntityMapper::update()`, not the object
  save path, so a restored state that the current schema would reject may be
  stored. That is OpenRegister's to confirm or close; the change names it as a
  sibling check (task T05).
- A restore of a record that other records point at does not touch those
  records. The confirmation says so.

## What it does not do

- It adds no buildiq route, service or stored state.
- It does not restore deleted records, single fields, or buildiq's own versions.
