# Tasks: apps-embed-in-other-sites

- [ ] **T01**: Add `embed` (`enabled`, `origins[]`) to `Application` in `lib/Settings/openbuild_register.json` with origin validation (https, scheme and host only) (REQ-BQEM-001). Verify: PHPUnit on the register import and on the origin validator.
- [ ] **T02**: In `DashboardController::builder()`, answer `?embed=1` for an app with embedding on with `RENDER_AS_BASE` and a CSP whose `frame-ancestors` lists the app's origins; leave every other request as it is (REQ-BQEM-002). Verify: PHPUnit asserting the policy and render mode for on, off and an unknown app.
- [ ] **T03**: In `src/builder.js`, hide the app navigation in embed mode and show the sign-in link when there is no session (REQ-BQEM-003). Verify: vitest for the embed-mode shell props and the no-session state.
- [ ] **T04**: Add the Embed section to `src/modals/AppSettingsModal.vue`: switch, origins list, copy snippet for the app or a chosen page (REQ-BQEM-001, REQ-BQEM-004). Verify: vitest for the snippet builder; Playwright `tests/e2e/app-embed.spec.ts` switches embedding on, loads the snippet URL and asserts the `Content-Security-Policy` header names the origin.
- [ ] **T05**: English and Dutch strings for the section and the sign-in notice; a page in `docs/` on embedding, with the same-site limit.
- [ ] **T06**: Run `openspec validate apps-embed-in-other-sites --strict`.
