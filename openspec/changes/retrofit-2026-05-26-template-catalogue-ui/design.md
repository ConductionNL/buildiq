# Design — template-catalogue-ui (retrofit)

status: pr-created

Retrofit change. Tasks describe retroactive annotation, not new implementation
work. The template gallery UI already ships; this records its observed
behaviour as numbered REQs so gate-16 spec-coverage can trace each method to a
requirement. No behaviour is changed.

`TemplateGallery` fetches `ApplicationTemplate` records, filters by category,
resolves per-template screenshots, and opens the clone dialog.
`CloneTemplateDialog` submits the clone and redirects to the new application.
