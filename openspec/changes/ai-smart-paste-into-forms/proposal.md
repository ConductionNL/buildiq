---
kind: code
---

# Proposal: ai-smart-paste-into-forms

## Why

**buildiq matrix, row `ai-smart-paste`**, "Paste text or a document into a form
and have AI fill in its fields.", rated `no`, `built.state` `none`. A roadmap
demand row points at the Power Apps 2026 release wave 1 planned features
(https://learn.microsoft.com/en-us/power-platform/release-plan/2026wave1/power-apps/planned-features).
Two competitors rate it `yes`:

- NocoBase: "packages/plugins/@nocobase/plugin-ai/src/client-v2/ai-employees/form-filler/tools/index.ts:192
  formFiller tool used by Dex (packages/plugins/@nocobase/plugin-ai/src/ai/ai-employees/dex.ts:14
  "extract and structure data from text, and can fill forms automatically") ...
  Reached on: form, AI employee Dex, paste text" (source read at v2.2.18).
- Microsoft Power Apps: "form fill assistance suggests field values from text or
  an image the user copied and pasted with smart paste, or from provided files"
  (https://learn.microsoft.com/en-us/power-apps/maker/common/faq-from-filling-assistance).

Today, from `built.evidence`: "Searched: no paste-to-fill on form pages
(src/components/page-editor/FormPageEditor.vue, nextcloud-vue v2.55.1
CnFormPage); the AI companion (CnAiCompanion via hermiq,
src/views/BuilderHost.vue:38) answers questions and does not fill a form".

## What changes

- The form page editor gets a "Fill from pasted text" setting: the maker turns
  it on and picks which fields the AI may fill.
- The manifest carries it as `config.smartPaste` on a `type: form` page, and
  buildiq's manifest validation checks it.
- The form renderer shows "Paste to fill" to the app user. The user pastes text,
  the AI proposes values for the allowed fields, the user reviews them in the
  form, and nothing saves until the user submits.
- Smart paste is refused on forms in `public` mode, so no anonymous caller ever
  reaches a language model through a built app.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | ai-smart-paste | Paste text or a document into a form and have AI fill in its fields. | no | authoring and validating smart paste on a form page |

## Existing work it builds on

- Spec `openspec/specs/page-designer-ui/spec.md` and
  `openspec/specs/openbuild-page-designer/spec.md`: the form page editor
  (REQ-OBPD-006).
- Spec `openspec/specs/form-editor-logic/spec.md`: conditional visibility and
  validation on form fields. A proposed value still passes the field's own
  validation, and a hidden field is never filled.
- Change `ai-copilot-documents-and-code-help` (this pass): the same
  approve-before-apply rule, applied here to app users.

## Sibling halves

- nextcloud-vue: the renderer. `CnFormPage` (`src/components/CnFormPage/CnFormPage.vue`
  at 2.57.1) renders `type: form` pages and has no paste control. nextcloud-vue
  owes: `config.smartPaste` declared in `src/schemas/app-manifest.schema.json`
  (the form config sits in the page `config` description, and `config` allows
  extra keys), and a "Paste to fill" control in `CnFormPage` that calls the fill
  endpoint, shows the proposals in the fields, and marks them as suggestions.
  No change for it exists on nextcloud-vue development.
- hermiq: the fill endpoint. It takes pasted text and the allowed fields (key,
  label, type, allowed values) and returns proposed values, marked as a draft,
  never saved. It follows Hermiq's governed delegate pattern
  (`lesson-authoring-ai-delegate`: an AI feature row seeded off, a DPO
  acknowledgement before it can be turned on, an input allowlist, one log line
  per call). No such endpoint exists on hermiq development: its routes carry
  `assistant#converse`, `assistant#detectPii` and the lesson authoring actions,
  and nothing that fills a form.

## Out of scope

- Images and uploaded files as input. Text only.
- Smart paste on `public` forms and on portaliq's citizen journeys (ADR-108).
- Filling a field the maker did not allow, or a field hidden by a condition.
- Saving on the user's behalf.
