# Spec: standalone-server-bundle

## Purpose

A maker exports an app and an administrator runs it on a server of its own,
without the builder. The export carries a server bundle: an image recipe that
builds the app into Nextcloud, a compose file that runs it with a database and
OpenRegister, and instructions. The bundle has no default passwords and no trace
of buildiq.

## ADDED Requirements

### Requirement: The export can include a server bundle (REQ-BQSD-001)

The export dialog SHALL offer "Include files to run it on its own server", on by
default, for the ZIP and the GitHub target. When it is on, the exported tree SHALL
contain `deploy/Dockerfile`, `deploy/compose.yaml`, `deploy/.env.example` and
`deploy/README.md`, and the app's `README.md` SHALL point to `deploy/README.md`.
When it is off, the tree SHALL have no `deploy/` folder.

#### Scenario: A maker exports the permit app with a server bundle

- **GIVEN** a maker on the app `vergunningen`, production version
- **WHEN** they open Export, keep "Include files to run it on its own server" on, and download the ZIP
- **THEN** the archive holds `deploy/Dockerfile`, `deploy/compose.yaml`, `deploy/.env.example` and `deploy/README.md`, with the app id `vergunningen` filled in

### Requirement: The bundle runs the app without the builder (REQ-BQSD-002)

The `Dockerfile` SHALL build the app from the exported source (PHP dependencies
without dev packages, then the frontend build) into the pinned Nextcloud image,
and `compose.yaml` SHALL run that image with a database and OpenRegister
installed from its release. No file in `deploy/` SHALL install, name or require
buildiq.

#### Scenario: An administrator starts the exported app on a new server

- **GIVEN** an administrator with the exported `vergunningen` archive on a server with Docker, and the passwords set in `deploy/.env`
- **WHEN** they run `docker compose up` in `deploy/` and log in
- **THEN** the "Vergunningen" app opens with its pages and its seed records, and the app list shows OpenRegister and Vergunningen but not Buildiq

### Requirement: The bundle has no default passwords (REQ-BQSD-003)

`compose.yaml` SHALL require the Nextcloud administrator password and the database
password from the environment and SHALL stop with a message naming the missing
variable when either is unset. `.env.example` SHALL list them empty. No file in
`deploy/` SHALL carry a password value.

#### Scenario: A server without a database password refuses to start

- **GIVEN** the `deploy/` folder with `NEXTCLOUD_ADMIN_PASSWORD` set and `POSTGRES_PASSWORD` unset
- **WHEN** the administrator runs `docker compose up`
- **THEN** compose stops before any container starts and names `POSTGRES_PASSWORD`

### Requirement: OpenRegister matches the builder's version (REQ-BQSD-004)

The bundle SHALL pin OpenRegister to the version installed on the builder's
instance at export time, SHALL let the administrator change it in `.env`, and the
README SHALL name the version the app was built on. When that release cannot be
fetched the installer SHALL fail with the version it looked for and SHALL NOT fall
back to another version.

#### Scenario: The bundle names the OpenRegister version

- **GIVEN** a builder instance running OpenRegister 1.14.2
- **WHEN** a maker exports `vergunningen` with the server bundle
- **THEN** `deploy/.env.example` sets `OPENREGISTER_VERSION=1.14.2` and `deploy/README.md` says the app was built on OpenRegister 1.14.2
