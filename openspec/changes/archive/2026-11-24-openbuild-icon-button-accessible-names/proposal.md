---
kind: code
---

## Why

Six `<NcButton>` instances across the Schema Designer's sub-editors and the (in-flight) Walkthrough Designer render **only** an icon (`<template #icon><DeleteIcon .../></template>`) with no default-slot text and no `aria-label`/`:aria-label` prop:

- `src/components/schema-editor/LifecycleEditor.vue:56` — remove-state button (`DeleteIcon`)
- `src/components/schema-editor/LifecycleEditor.vue:108` — remove-transition button (`DeleteIcon`)
- `src/components/schema-editor/LifecycleEditor.vue:147` — remove-action button (`DeleteIcon`)
- `src/components/schema-editor/WidgetEditor.vue:49` — remove-widget button (`DeleteIcon`)
- `src/components/schema-editor/RelationEditor.vue:53` — remove-relation button (`DeleteIcon`)
- `src/components/walkthrough-editor/WalkthroughDesigner.vue:102` — add-option button (`Plus`)

`@nextcloud/vue`'s `NcButton` does not synthesize an accessible name from the icon component's name or `aria-hidden` SVG content — a screen reader announces these as a bare "button" with no indication of what they do, in a form full of other buttons (add/remove state, transition, action, widget, relation, option). This fails WCAG 2.1 SC 4.1.2 (Name, Role, Value) and SC 1.1.1 (non-text content) for a **destructive** action in five of the six cases (irreversible remove from a form array). The app already gets this right elsewhere: `WalkthroughDesigner.vue:59,67,73,111,154,214,222,228` (move-up/move-down/delete-step/remove-option/add-tour buttons) all carry `:aria-label="t('buildiq', '...')"` alongside their icon — these six are the outliers, not the pattern, so the fix is mechanical and low-risk.

## What Changes

- Add `:aria-label="t('buildiq', '<verb + noun>')"` to each of the six `NcButton` instances listed above, using new, English-source i18n keys (e.g. `'Remove state'`, `'Remove transition'`, `'Remove action'`, `'Remove widget'`, `'Remove relation'`, `'Add option'`) consistent with the existing labelled siblings in the same files.
- No visual change — `aria-label` is not rendered, only exposed to assistive tech.
- No BREAKING changes.

## Capabilities

### New Capabilities

_None._

### Modified Capabilities

- `buildiq-schema-designer`: adds a requirement that icon-only action buttons in the schema designer's sub-editors carry an accessible name.

## Impact

- `src/components/schema-editor/LifecycleEditor.vue` — 3 buttons.
- `src/components/schema-editor/WidgetEditor.vue` — 1 button.
- `src/components/schema-editor/RelationEditor.vue` — 1 button.
- `src/components/walkthrough-editor/WalkthroughDesigner.vue` — 1 button. This file is also in scope of the in-flight `buildiq-walkthrough-editor` change; the fix here is additive (one `:aria-label` prop) and does not conflict with that change's pending work. When `buildiq-walkthrough-editor` archives and syncs its own capability spec, that spec should adopt the same accessible-name requirement for its icon-only buttons — noted here so the follow-up isn't lost.
- New i18n keys added to `l10n` source strings (English keys, per the project's i18n-keys-are-English rule); translations follow the existing l10n pipeline.
