# AGENTS.md

Guide for coding agents working in this repository. Every command below is defined in
`composer.json`, `package.json` or the `Makefile` in this repo.

## What this app is

Buildiq is a citizen-developer app builder for Nextcloud. Non-technical users compose apps
from registers, connectors, workflows and documents through a visual interface; each built
app is a manifest stored as an OpenRegister object and rendered at runtime, without new PHP.

## Setup

- Install dependencies: `composer install` and `npm ci` (Node version in `.nvmrc`).
- Development: clone into a Nextcloud `custom_apps` directory, or use `make dev-link` to
  create a `../buildiq` symlink when the checkout has another name. Then
  `npm run build` and `php occ app:enable buildiq` (see `README.md`, "From Source").
- Evaluation stack: `docker compose -f docker-compose.yml up -d --build`, then open
  `http://localhost:8080/apps/buildiq/`.
- Demo stack: `docker compose -f buildiq-compose.yaml up -d`, then open
  `http://localhost:8615/apps/buildiq/`.

## Build

- `npm run build` builds the production frontend bundle with webpack. The `js/` output is
  not committed; build before enabling the app or the UI is blank.
- `npm run dev` builds a development bundle; `npm run watch` rebuilds on change.

## Test

- PHP unit tests (PHPUnit): `composer test:unit`
- PHP coverage report: `composer test:coverage`
- JavaScript unit tests (Vitest): `npm run test`
- End-to-end tests (Playwright, `tests/e2e`): `npm run test:e2e`. Install browsers once with
  `npm run test:e2e:install`.
- API tests (Newman): `npm run test:newman`, against a Nextcloud on `localhost:8080`.

## Lint and quality

- Full PHP gate: `composer check:strict` (lint, phpcs, phpmd, psalm, phpstan, tests and the
  shared gates). `composer check` is the quicker subset.
- Single tools: `composer phpcs`, `composer phpmd`, `composer psalm`, `composer phpstan`;
  auto-fix with `composer phpcs:fix` or `composer cs:fix`.
- Frontend: `npm run lint` (ESLint), `npm run stylelint` and `npm run format` (Prettier check).
- Manifest and translation checks: `npm run check:manifest`, `npm run test:l10n`,
  `npm run test:l10n:parity`.

## OpenSpec flow

Work is spec-driven (`openspec/config.yaml`).

1. Propose a change in `openspec/changes/<change-name>/` with a proposal, design, tasks and
   delta specs.
2. When the change is implemented, its requirements land in `openspec/specs/<capability>/spec.md`.
3. The finished change moves to `openspec/changes/archive/`.

## Architecture decisions

ADRs for this app live in `openspec/architecture/` (format and naming in
`openspec/architecture/README.md`).

## Repo conventions

- Buildiq owns no database tables. Its data, including the built apps, lives in
  OpenRegister; the register is defined in `lib/Settings/openbuild_register.json` plus the
  fragments in `lib/Settings/register.d/`.
- Behaviour such as lifecycles is declared as schema metadata (`x-openregister-lifecycle`)
  in the register, not written as service code.
- Pages are manifest config: `src/manifest.json` plus the fragments in `src/manifest.d/`.

## Commits

Use [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/), for example
`feat(designer): ...` or `fix(runtime): ...`. See `CONTRIBUTING.md`.
