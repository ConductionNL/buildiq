# Retrofit — page-designer-ui

Describes observed behaviour of the Page Designer UI layer — the
`PageDesigner` controlled view, its route-level hosts (`PageDesignerHost`,
`BuilderHost`), the nine per-page-type config sub-editors under
`src/components/page-editor/`, and the reusable field builders under
`src/components/page-editor/fields/` — as 5 new REQs.

Code already exists (it implements the backend-facing `openbuilt-page-designer`
capability). This change retroactively specifies the frontend behaviour at the
component-method level so gate-16 spec-coverage can trace each method.

## Approach
- Describe observed inputs, computed surfaces, emit contracts, undo/redo, and
  validation wiring per component.
- REQs match behaviour, not aspiration. No behaviour is changed.
