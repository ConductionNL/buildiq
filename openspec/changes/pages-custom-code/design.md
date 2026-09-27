# Design: pages-custom-code

Read at buildiq development `d21e42f`; nextcloud-vue at the pinned 2.57.1.

## Where it sits

- Custom page editor: `src/components/page-editor/CustomPageEditor.vue`. It
  authors `{component, props}` for `type: custom` (header, lines 3-18): a
  registry key input with a datalist (lines 26-39) and a raw JSON props textarea
  (lines 53-60).
- Column builder: `src/components/page-editor/fields/ColumnBuilder.vue` picks a
  schema property or `@self.*` metadata per column (lines 21-40).
- Form logic: `src/components/page-editor/fields/VisibleWhenBuilder.vue` with the
  fixed op list `OPS` (line 69); `src/components/page-editor/fields/FormFieldBuilder.vue`
  with `showLogic`.
- Manifest writes: `PUT /api/applications/{slug}/manifest`
  (`appinfo/routes.php:63`) to `ApplicationsController::saveManifest()`
  (`lib/Controller/ApplicationsController.php:337`), owner and editor RBAC in the
  controller. The copilot and agents write manifests through
  `lib/Mcp/BuildiqToolProvider.php` handlers `buildiq.upsertPage` and
  `buildiq.addWidget`.
- Runtime: `src/views/BuilderHost.vue` mounts a nested `CnAppRoot` with
  `runtimeRegistry` (lines 34-41); hybrid apps render the same manifest in their
  own `CnAppRoot`. The page renderer is nextcloud-vue's `CnPageRenderer`.
- Manifest schema at 2.57.1: `column` has `formatter` and no `expression`;
  `formField` has `default` and no `expression`.

## D1. The manifest shapes

- Code component: `config.code = {html, css, js, inputs}` on a `type: custom`
  page, or on a widget. `inputs` names what the page hands in: `object` (the
  current record on a detail page), `rows` (a register and schema query with the
  page's filters), `route` (route params), `user` (id and display name).
  `config.component` and `config.code` are mutually exclusive.
- Expression: `columns[].expression`, `formField.expressionDefault` and
  `visibleWhen.expression`, each a JavaScript expression string evaluated with
  `row` or `values`, `user` and `route` in scope, returning a JSON value.

## D2. Where maker code runs

Every piece of maker code runs inside an `<iframe sandbox="allow-scripts">`
without `allow-same-origin`, so the frame has an opaque origin: it cannot read
the page, its cookies or its storage, and a request it makes carries no session.
The frame's document comes from a new buildiq route:

- `GET /apps/buildiq/sandbox/{slug}/{version}/{codeId}` for a code component;
- `GET /apps/buildiq/sandbox/evaluator` for the expression evaluator, which
  receives expressions and data by message.

Both are `#[NoAdminRequired]`, check that the caller may use the app, and answer
with a `ContentSecurityPolicy` that allows the document's own inline script and
style and nothing else: no `connect-src`, no frames, no forms, images only as
`data:`. The rest of buildiq keeps Nextcloud's default policy. No route is
public.

## D3. The message protocol

The page posts `{type: "inputs", data}` into the frame. The frame may post back
only `navigate` (a page id and params from the app's own manifest), `notice` (a
short text shown as a toast) and `setField` (a field of the current record and a
value). The page checks each message against the app's manifest and schema, and
performs `setField` through OpenRegister with the user's own session, so the
user's rights apply and nothing widens. Unknown message types are dropped. The
protocol and the frame belong to nextcloud-vue (see proposal); buildiq authors
the manifest and serves the documents.

## D4. Expressions are pure

The evaluator frame runs each expression as a function of its scope with a time
budget per page render, and answers with JSON values. A thrown error or a timeout
renders the cell empty with a marker, and the page designer preview shows the
error. An expression never runs in the host page.

## D5. Authoring

- `CustomPageEditor.vue` gains a choice between "Registered component" (today's
  editor) and "Write your own": three `CnJsonViewer` editors (`html`, plain text
  for CSS and JavaScript) and an inputs checklist.
- The same editor is offered for a widget in the dashboard and detail editors.
- `ColumnBuilder.vue` gains an "fx" toggle per column that swaps the property
  picker for an expression box. `VisibleWhenBuilder.vue` and the form field
  default gain the same toggle.
- The page designer preview (`PreviewSandbox.vue`) renders code and expressions
  through the same sandbox, so a maker sees errors before publishing.

## D6. Who may write code

`saveManifest()` refuses any change to `config.code`, `expression`,
`expressionDefault` or `visibleWhen.expression` unless the caller is an
`owners` member of the app, and unless the instance setting "Allow code on
pages" (off by default) is on. The copilot and agent handlers
(`buildiq.upsertPage`, `buildiq.addWidget`) never write those keys and drop them
from a plan with a validator message. Turning the setting off makes buildiq serve
a placeholder document for every code component and skip expressions, with a
notice in the page designer.

## Risks

- A code component can still draw a misleading form inside its frame. It cannot
  reach the session, navigate the top window or open popups, and only owners can
  write one.
- Asynchronous evaluation makes computed cells appear a moment after the rest.
  The renderer shows a placeholder until the answer arrives.
- Until nextcloud-vue ships the frame and the evaluator, the manifest keys are
  inert in rendered apps; the page designer says so.

## What it does not do

- It never runs maker code in the host page or in PHP.
- It installs no third-party packages.
- It gives code no network access.
