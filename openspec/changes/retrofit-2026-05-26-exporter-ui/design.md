---
status: pr-created
pr: https://codeberg.org/Conduction/openbuild/pulls/36
---

# Design — exporter-ui (retrofit)

Retrofit change. Tasks describe retroactive annotation, not new implementation
work. The exporter UI already ships; this records its observed behaviour as
numbered REQs so gate-16 spec-coverage can trace each method to a requirement.
No behaviour is changed.

`ExportDialog` collects the export target, visibility, license, and version,
then submits the export job. `ExportJobsList` lists the queued/running/finished
jobs, polls for status, and labels each.
