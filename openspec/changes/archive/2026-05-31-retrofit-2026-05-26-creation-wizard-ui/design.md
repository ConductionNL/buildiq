# Design — creation-wizard-ui (retrofit)

Retrofit change. Tasks describe retroactive annotation, not new implementation
work. The Create Application Wizard UI already ships; this records its observed
behaviour as numbered REQs so gate-16 spec-coverage can trace each method to a
requirement. No behaviour is changed.

`CreateApplicationWizard` is the modal host that sequences four steps, gates
"next" on per-step validity, merges each step's slice into one payload, and
submits the atomic provisioning call. `IconUploadSection` is the shared
light/dark SVG upload control reused by the wizard and the icon tab.
