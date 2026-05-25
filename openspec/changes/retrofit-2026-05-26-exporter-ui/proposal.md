# Retrofit — exporter-ui

Describes observed behaviour of the exporter UI — the `ExportDialog` and the
`ExportJobsList` view — as 2 new REQs.

Code already exists (it implements the `openbuilt-exporter` backend
capability). This change retroactively specifies the export-target/visibility/
license selection, submission, and job polling behaviour so gate-16
spec-coverage can trace each method.

## Approach
- Describe observed option lists, submit, job list fetch/poll, and status
  labelling.
- REQs match behaviour, not aspiration. No behaviour is changed.
