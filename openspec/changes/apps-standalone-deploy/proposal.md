---
kind: code
---

# Proposal: apps-standalone-deploy

## Why

buildiq matrix, row `app-standalone-deploy`, "Run a built app on its own server,
separate from the builder.", rated `no`, in buildiq's core area (apps). No tender,
featureRequest or roadmap row carries it. One competitor rated `yes`:

- Mendix: "Mendix on Kubernetes deploys apps in your own private cluster
  (premium offering); tsv #873 containerized apps deployable anywhere"
  (https://docs.mendix.com/developerportal/deploy/private-cloud/).

Two rated `partial` and name the same limit buildiq has: NocoBase ("builder and
runtime are one instance ... moving a build to a separate server goes through
... whole-instance restore or the Migration manager, commercial") and Budibase
("export and import a workspace to another Budibase instance (still a full
builder install)").

What buildiq does today, per the matrix `built.evidence`: "lc-export-real-app
produces an installable Nextcloud-app ZIP (still requires a Nextcloud+OpenRegister
host), not a self-contained standalone server." The exporter already makes the
app independent of the builder: `openspec/specs/openbuild-exporter` requires that
the "Exported app boots standalone with zero Buildiq dependency". What it hands
over is source. The README it writes says: "As its own app: run `composer
install`, `npm ci` and `npm run build`, then enable it. It needs OpenRegister."
(`lib/Service/ExportAppContentBundler.php:358-359`). Going from that archive to a
running server is left to whoever receives it.

## What changes

- The export dialog gets "Include files to run it on its own server", on by
  default for both the ZIP and the GitHub target.
- The exported tree gains a `deploy/` folder: a `Dockerfile` that builds the app
  from the exported source into a Nextcloud image, a `compose.yaml` that runs it
  with a database and OpenRegister, an `.env.example`, and a `README.md` that
  takes an administrator from download to a running server.
- The bundle holds no builder: buildiq is not installed, referenced or needed.
- The bundle has no default passwords. It refuses to start until the
  administrator and database passwords are set.
- OpenRegister is pinned to the version the builder ran when the app was
  exported, so the app runs against the data layer it was built on.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | app-standalone-deploy | Run a built app on its own server, separate from the builder. | no | a runnable server bundle in the export, so the app runs on its own Nextcloud without the builder |

## Existing work it builds on

- `openspec/specs/openbuild-exporter`: the exported tree ("Exported tree shape
  conforms to the nextcloud-app-template baseline"), the ZIP and GitHub targets,
  "Optional seed-data inclusion", and "Exported app boots standalone with zero
  Buildiq dependency". This change adds a folder to that tree and one option to
  the job.
- `openspec/specs/exporter-ui`: "Export dialog collects options and submits the
  job", which gets the new switch.
- `data-registers-runtime` (open, every task ticked): the per-binding choice to
  bundle a data register's rows, which the bundle's seed step honours.
- `buildiq-compose.yaml` at the repository root: buildiq's own demo rig, which
  already installs OpenRegister from its release tarball and pins the Nextcloud
  major. The bundle reuses its approach, without its demo passwords.

## Sibling halves

None. The bundle installs OpenRegister from its published releases and asks
nothing new of it.

## Out of scope

- A runtime outside Nextcloud. A built app is a Nextcloud app rendered by
  `CnAppRoot` on OpenRegister; a second renderer is not buildiq's to build.
- Hosting, scaling, backups and TLS for the server. The README points at the
  Nextcloud admin manual for those.
- Publishing a container image to a registry from the exported repository.
- Moving live records from the builder's instance to the new server beyond the
  existing seed-data and data-register switches.
