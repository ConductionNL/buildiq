---
retrofit: true
---

# exporter-ui Specification

## Purpose

The exporter UI graduates a published Buildiq virtual app into a standalone
Nextcloud app. `ExportDialog` collects the export target, visibility, license,
and version, then submits the export job; `ExportJobsList` lists the jobs, polls
for status, and labels each.

This capability is observed behaviour of those components. It is the frontend
half of the `buildiq-exporter` backend capability.

## Requirements

### Requirement: Export dialog collects options and submits the job

`ExportDialog` SHALL expose the target, visibility, license, and version option
lists (`targetOptions`, `visibilityOptions`, `licenseOptions`,
`versionOptions`), submit the export job (`submit`), and close (`onClose`).

@e2e exclude retrofit component-contract spec — `targetOptions`, `visibilityOptions`, `licenseOptions`, `versionOptions`, `submit`, `onClose` are dialog-component contracts verified by Vitest unit tests; export-dialog open/submit integration requires a published app + export backend which is covered by the buildiq-exporter Newman tests

#### Scenario: Submit an export

- **WHEN** the user selects a target, visibility, license, and version and submits
- **THEN** the dialog queues an export job with those options and closes

### Requirement: Export jobs list fetches, polls and labels job status

`ExportJobsList` SHALL fetch the export jobs (`fetchJobs`) on `mounted`, poll
for status updates, clean up the poll on `beforeDestroy`, label each job's
status (`statusLabel`), open the export dialog (`openDialog`), and react to a
newly queued job (`onQueued`).

@e2e exclude retrofit component-contract spec — `fetchJobs`, `statusLabel`, `onQueued`, poll-cleanup-on-`beforeDestroy` are component lifecycle contracts verified by Vitest unit tests; job-status polling requires a running async export job backend which is covered by the buildiq-exporter Newman tests

#### Scenario: Poll a running job

- **WHEN** an export job is running
- **THEN** the list polls and updates its status label until the job finishes

#### Scenario: Reflect a new job

- **WHEN** a new export is queued from the dialog
- **THEN** the list adds the job and begins polling it
