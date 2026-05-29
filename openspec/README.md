# OpenBuild — OpenSpec

This folder contains feature specifications, architectural decisions, and implementation specs for **OpenBuild** — a citizen-developer app builder for Nextcloud that composes apps from OpenRegister schemas, OpenConnector APIs, Procest workflows, Docudesk documents, NL Design themes, and MyDash dashboards without scaffolding PHP for each new app. Built apps are rendered at runtime via `CnAppRoot` from manifests stored in OpenBuild's own register (ADR-024 + ADR-031).

## Structure

| File / Folder | Purpose |
|---|---|
| `app-config.json` | App identity, configuration, and tracked decisions — written by `/opsx:app-explore` |
| `config.yaml` | OpenSpec CLI project configuration — context and rules |
| `specs/` | Feature specs — what the app should do (input for OpenSpec changes) |
| `architecture/` | App-specific Architectural Decision Records (ADRs) |
| `changes/` | Individual change directories, each with a full set of specification artifacts (created on first change) |

> If `app-config.json` has `"requiresOpenRegister": true`, install [OpenRegister](https://codeberg.org/Conduction/openregister) before enabling this app. Set to `false` if your app does not use OpenRegister.

## app-config.json — Key Fields

| Field | Default | Notes |
|-------|---------|-------|
| `cicd.enableNewman` | `false` | Enable only when a Postman collection exists in the repo. Newman runs API test collections in CI — enabling it without a collection breaks every pipeline run. **To enable:** add a Postman collection to `tests/`, set `enableNewman: true`, and run `/opsx:app-apply`.  |
| `cicd.phpVersions` | `["8.3", "8.4"]` | PHP versions to test against. Update when Nextcloud drops support for older PHP versions. |
| `cicd.nextcloudRefs` | `["stable31", ...]` | Nextcloud branches to test against. Add new stable refs as Nextcloud releases them. |
| `dependencies.requiresOpenRegister` | `true` | Controls whether the app shows an OpenRegister gate in `src/App.vue` and adds OpenRegister to CI `additional-apps`. |

## Artifact Progression

Each change in `changes/` moves through these artifacts:

```
proposal.md ──► specs/ ──► design.md ──► tasks.md ──► plan.json
                                                        │
                                                        ▼
                                                  GitHub Issues
                                                        │
                                                        ▼
                                                  implementation
                                                        │
                                                        ▼
                                                  review.md
                                                        │
                                                        ▼
                                                  archive/
```

## Workflow

1. **Explore** — Use `/opsx:app-explore` to think through goals, architecture, and features; captures decisions into `app-config.json`
2. **Plan** — When a feature spec reaches `planned` status, use `/opsx:ff` to create a change spec
3. **Implement** — Use `/opsx:apply` to implement the tasks
4. **Verify** — Use `/opsx:verify` to check implementation matches the spec
5. **Archive** — Use `/opsx:archive` to move completed changes to `changes/archive/`

## Commands

| Command | Purpose |
|---------|---------|
| `/opsx:app-design` | Full upfront design — architecture, features, wireframes (optional pre-step) |
| `/opsx:app-create` | Bootstrap a new app or onboard an existing repo |
| `/opsx:app-explore` | Think through goals, architecture, and features; updates `app-config.json` |
| `/opsx:app-apply` | Apply `app-config.json` decisions to actual app files |
| `/opsx:app-verify` | Audit app files against `app-config.json` (read-only) |
| `/opsx:explore` | Investigate a problem or idea before starting a change (no output) |
| `/opsx:ff {name}` | Create all artifacts for a new change at once |
| `/opsx:new {name}` | Start a new change (step-by-step) |
| `/opsx:continue` | Generate the next artifact in the sequence |
| `/opsx:plan-to-issues` | Convert tasks.md into plan.json and GitHub Issues |
| `/opsx:apply` | Implement tasks from a change |
| `/opsx:verify` | Verify implementation matches the spec |
| `/opsx:archive` | Archive a completed change |
