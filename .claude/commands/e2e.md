---
description: Run the Playwright end-to-end tests against a running instance
---

Run the Playwright suite in `tests/e2e`.

1. Make sure a Nextcloud with buildiq and OpenRegister is running, with the frontend built
   (`npm run build`).
2. Install the browser once: `npm run test:e2e:install`.
3. Point the suite at the instance; outside CI there is no default
   (`tests/e2e/support/baseUrl.ts`): `export PLAYWRIGHT_BASE_URL=http://localhost:8080`
   (use your instance's URL).
4. `npm run test:e2e`

Report failing specs with the assertion that failed.
