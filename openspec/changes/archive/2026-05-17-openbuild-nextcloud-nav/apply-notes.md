# Apply Notes — openbuilt-nextcloud-nav

## Seed data deferral (tasks 6.1, 6.2)

Tasks 6.1 and 6.2 require extending `SeedHelloWorld.php` to attach demo icon
SVG files to the seeded Hello World Application.  **`SeedHelloWorld.php` does
not exist on this branch.**

Chain spec `openbuilt-versioning-model` (spec C) deleted the class entirely.
Per the tasks.md note: "spec C deleted `SeedHelloWorld` entirely; seeding is
now owned by the creation-wizard (spec F, future)."  The creation-wizard class
is not yet implemented, so there is no seeding path to extend.

**Deferred decision:** Tasks 6.1 and 6.2 are blocked on spec F
(`openbuilt-creation-wizard`).  Once the wizard ships its seed step, the
wizard's post-creation hook should attach `app-icon.svg` and `app-icon-dark.svg`
to the Hello World Application record, and set the top-level `icon` /
`iconDark` refs.  A GitHub issue should be opened against the
`openbuilt-creation-wizard` change to track this.

All other tasks (1–5, 7) are fully implemented.

## Playwright / Newman tests (tasks 7.4, 7.5, 7.6)

These E2E tests require a live Nextcloud instance with OpenBuilt and OR
installed.  They are listed in the tasks.md as deliverables but have not been
added to the repository in this apply run because:

1. The E2E test harness does not yet exist for OpenBuilt (no `tests/e2e/`
   directory with a working Playwright spec runner configured).
2. The Newman collection at `tests/newman/openbuilt.postman_collection.json`
   does not yet exist.

PHPUnit tests (tasks 7.1–7.3) have been fully implemented.
