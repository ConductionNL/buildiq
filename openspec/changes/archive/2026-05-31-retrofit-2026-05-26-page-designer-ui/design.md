# Design — page-designer-ui (retrofit)

Retrofit change. Tasks describe retroactive annotation, not new implementation
work. The Page Designer UI already ships; this records its observed behaviour as
numbered REQs so gate-16 spec-coverage can trace each method to a requirement.
No behaviour is changed.

The Page Designer is a controlled component: `PageDesigner` takes a `manifest`
prop and emits `update:manifest` / `save-and-preview`; `PageDesignerHost` and
`BuilderHost` are the route-level hosts that resolve the slug + active version,
load the manifest from OpenRegister, and persist edits with a PUT. The nine
sub-editors each own one `page.type` and emit their config slice upward; the
field builders are reusable list editors (columns, actions, form fields, layout
items, widgets, sidebar tabs/sections) shared across sub-editors.
