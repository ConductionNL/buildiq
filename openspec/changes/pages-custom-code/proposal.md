---
kind: code
---

# Proposal: pages-custom-code

## Why

**buildiq matrix, row `pg-custom-js`**, "Write JavaScript expressions to bind or
transform values on a page.", rated `no`, `built.state` `none`. Three competitors
rate it `yes`:

- NocoBase: "packages/core/client-v2/src/runjs holds the RunJS runtime used by JS
  fields, JS actions and linkage values; JS block at ... JSBlock.tsx:190 ...
  Reached on: field or action settings, JS" (source read at v2.2.18).
- Budibase: "packages/builder/src/components/common/bindings/BindingPanel.svelte:153-154
  adds a JavaScript mode beside Handlebars for any bindable setting, :274
  evaluates it; no licence check on JS bindings" (source read at v3.46.0).
- Appsmith: "app/client/src/pages/AppIDE/layouts/components/Editor.tsx:43
  JSEditorPane for JS objects; every property accepts {{ }} bindings evaluated in
  app/client/src/workers (evaluation worker)" (source read at v2.4.2).

Today, from `built.evidence`: "searched for
'expression'/'jsExpr'/'customJs'/'bindingExpression'/'Function(' across
src/components/page-editor/ and src/services/: no hits. The only
conditional-value mechanism found is visibleWhen ({field, op, value} with a fixed
op enum eq/neq/gt/gte/lt/lte, VisibleWhenBuilder.vue:69-73), a declarative
predicate, not a JavaScript expression language. No formula/computed-value field
editor exists."

**buildiq matrix, row `pg-custom-code-component`**, "Drop in a custom component
written in code when the built-in ones are not enough.", rated `partial`,
`built.state` `built`. Five competitors rate it `yes`:

- NocoBase: "packages/core/client-v2/src/flow/models/blocks/js-block/JSBlock.tsx:267
  JS block rendering custom code with ctx APIs ... Reached on: page, Add block, JS
  block" (source read at v2.2.18).
- Budibase: "AddPluginModal.svelte:23-26 installs component plugins from URL,
  NPM, GitHub or file ... adds them as a 'Plugins' category in the component
  picker" (source read at v3.46.0).
- Appsmith: "app/client/src/widgets/index.ts:100 CUSTOM_WIDGET renders builder
  HTML, CSS and JS in a sandboxed iframe
  (app/client/src/widgets/CustomWidget/component/index.tsx:32)" (source read at
  v2.4.2).
- Mendix: "pluggable widgets are React components in JavaScript or TypeScript,
  shareable via the Marketplace"
  (https://docs.mendix.com/apidocs-mxsdk/apidocs/pluggable-widgets/).
- Microsoft Power Apps: "Power Apps component framework code components for
  model-driven and canvas apps (not on-premises)"
  (https://learn.microsoft.com/en-us/power-apps/developer/component-framework/overview).

Today, from `built.evidence`: "src/components/page-editor/CustomPageEditor.vue
authors {component, props} for type:"custom", 'component' is a free-text input
... naming a key in the CONSUMING APP's pre-registered customComponents map
(CustomPageEditor.vue:3-18 comment) ... There is no code editor anywhere in
buildiq for writing a new component's implementation." The missing half: a
component whose code the maker writes in buildiq.

Code a maker writes runs in the browser of every app user, so where it runs
decides whether this is safe. Appsmith's answer, a sandboxed iframe, is the one
this change takes for both rows.

## What changes

- A custom page, and a dashboard or detail widget, can hold its own code: HTML,
  CSS and JavaScript the maker writes in buildiq.
- Index and detail columns, and form field defaults and visibility, can take a
  JavaScript expression instead of a fixed value or a fixed comparison.
- All maker code runs in a sandboxed iframe with an opaque origin and a document
  served by buildiq with a policy that allows no network, no cookies and no
  access to the page around it. It talks to the page only by messages.
- The page gives the code data it may see and accepts three requests back:
  navigate, show a notice, and change a field of the current record, which the
  page performs with the user's own rights.
- Only app owners write code, and only when an administrator allows code on
  pages for the instance.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | pg-custom-js | Write JavaScript expressions to bind or transform values on a page. | no | expressions on columns, form defaults and visibility, evaluated in a sandbox |
| buildiq | pg-custom-code-component | Drop in a custom component written in code when the built-in ones are not enough. | partial | a component whose code is written in buildiq and runs in a sandbox |

## Existing work it builds on

- Specs `openspec/specs/openbuild-page-designer/spec.md`,
  `page-designer-ui` and `page-editor-coverage`: the custom page editor and the
  column builder.
- Spec `openspec/specs/form-editor-logic/spec.md`: `visibleWhen` and field
  validation; an expression is an alternative to the fixed predicate, not a
  replacement.
- Spec `openspec/specs/component-blocks/spec.md` (archived
  `2026-07-24-component-blocks`): saved blocks can carry a code component like
  any other widget.
- Open change `harden-xss-dos-csrf`: the code route follows its rules.

## Sibling halves

- nextcloud-vue: the renderer. It owes `CnCodeFrame`, which mounts the sandboxed
  iframe for a code component and carries the message protocol, and an
  expression evaluator that runs a page's expressions in one sandboxed frame and
  hands the values to `CnIndexPage`, `CnDetailPage` and `CnFormPage`. It also owes
  the manifest keys: `config.code` on `type: custom` pages and on widgets,
  `columns[].expression`, and `expression` on `formField` defaults and
  `visibleWhen` (at 2.57.1 the `column` definition has `formatter` and no
  `expression`, and `formField` has `default` and no `expression`). No change for
  it exists on nextcloud-vue development.
- None other.

## Out of scope

- Installing third-party component packages from npm or GitHub.
- Code with network access. A code component that needs data gets it from the
  page, never by its own requests.
- Server-side code. That is `logic-script-step`.
- TypeScript and frameworks inside the frame beyond what the maker writes.
