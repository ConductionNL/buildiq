#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 Conduction B.V.
# SPDX-License-Identifier: EUPL-1.2
#
# Provision Buildiq's OpenRegister register + schemas, the `hello-world`
# virtual-app fixture and the instance settings the e2e suite depends on, for
# the shared `E2E Tests (Playwright)` CI job.
#
# Wired up as the workflow's `playwright-seed-command`. That step runs AFTER
# `php -S` is up and with cwd set to the Nextcloud server root, so this is
# invoked as:
#
#     playwright-seed-command: 'bash apps/buildiq/tests/e2e/ci-seed.sh'
#
# WHY THIS IS NEEDED — four separate reasons, all of which fail SILENTLY.
# ----------------------------------------------------------------------
#
#  1. `occ app:enable buildiq` runs the `InitializeSettings` post-migration
#     repair step, which is supposed to import lib/Settings/openbuild_register.json
#     (plus the ADR-037 register.d/*.json fragments) into OpenRegister. An
#     IRepairStep runs with NO user session, so OpenRegister's RBAC denies the
#     import outright ("User 'Anonymous' does not have permission to 'create'
#     objects in schema '…'"). `InitializeSettings::run()` catches \Throwable and
#     downgrades it to a warning, so `occ app:enable buildiq` still exits 0.
#     The app enables, the SPA boots, and the register simply is not there.
#     Additionally the repair path calls loadConfiguration(force: false), which
#     is version-gated: it can advance the recorded configuration version WITHOUT
#     applying the register, so a second run does nothing either.
#     → done here explicitly over the admin HTTP API, FORCED, then VERIFIED.
#
#  2. `tests/e2e/global-setup.ts` seeds the `hello-world` fixture and disables
#     Nextcloud's rate-limit protection by resolving the DOCKER CONTAINER that
#     publishes the port under test (`docker ps --filter publish=<port>`). On a
#     GitHub runner Nextcloud is a bare `php -S`, not a container, so that
#     resolution returns null and BOTH steps are skipped with a console warning
#     — which nothing reads. Consequences: every hello-world spec fails on an
#     empty app, and `ApplicationCreationController::wizard()`'s
#     `#[UserRateLimit(limit: 10, period: 3600)]` starts returning 429 from the
#     eleventh `ensureApp()` onwards, i.e. mid-run, for a reason that has nothing
#     to do with what the failing spec asserts.
#     → both are performed here with `occ` directly, where `occ` actually is.
#
#  3. The frontend bundle. A missing or empty bundle does NOT 404 on Nextcloud —
#     it serves the HTML error page with **HTTP 200 and Content-Type text/html**.
#     Every status-code check in the pipeline reads that as success, and the
#     whole suite then fails on selector timeouts that accuse the selectors.
#     → gated below on the SERVED response's content type actually being
#       JavaScript.
#
#  4. Cold start. `php -S` (even with PHP_CLI_SERVER_WORKERS=8) pays a cold
#     opcache and a first parse of a multi-megabyte webpack bundle on the first
#     hit, and that cost lands entirely on whichever spec happens to run first.
#     → warmed here, in the environment-preparation step where it belongs,
#       rather than inside an assertion timeout that would have to keep drifting
#       upward.
#
# It is idempotent: the import is idempotent server-side, the fixture seeder
# short-circuits once the hello-world Application exists, and re-running only
# re-verifies.

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
# The Nextcloud server root — where `occ` lives. This script is normally invoked
# with cwd already set there by the workflow, but derive it from the app's own
# location so it also works when run from anywhere else.
SERVER_DIR="$(cd -- "${APP_DIR}/../.." && pwd)"

# ── Target resolution ────────────────────────────────────────────────────────
# The shared workflow's "Seed test data" step exports BASE_URL / NEXTCLOUD_URL /
# NC_BASE_URL / ADMIN_USER / ADMIN_PASSWORD / NC_ADMIN_USER / NC_ADMIN_PASS.
# Accept all of them, and fall back to the CI runner's own `php -S 0.0.0.0:8080`
# ONLY when actually running on CI.
#
# On a developer box `localhost:8080` is the SHARED dev container, and this
# script performs ADMIN WRITES (it imports a register and sets a system config).
# Off CI, an unset target is a hard error — matching tests/e2e/support/baseUrl.ts.
BASE="${PLAYWRIGHT_BASE_URL:-${BASE_URL:-${NEXTCLOUD_URL:-${NC_BASE_URL:-}}}}"
if [ -z "$BASE" ]; then
	if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
		BASE="http://localhost:8080"
	else
		echo "ERROR: no base URL set. Export PLAYWRIGHT_BASE_URL or BASE_URL." >&2
		echo "       Refusing to default to http://localhost:8080 outside CI —" >&2
		echo "       that is the SHARED dev container and this script writes to it." >&2
		exit 1
	fi
fi
BASE="${BASE%/}"

USER_NAME="${ADMIN_USER:-${NC_ADMIN_USER:-admin}}"
USER_PASS="${ADMIN_PASSWORD:-${NC_ADMIN_PASS:-admin}}"

echo "[ci-seed] target:     ${BASE}"
echo "[ci-seed] app dir:    ${APP_DIR}"
echo "[ci-seed] server dir: ${SERVER_DIR}"

# Small helper so every probe reports its STATUS CODE. A refusal (403), a
# redirect to the login form and an empty result are the same shape to a
# payload-only probe; they must never be conflated.
api_get() {
	# $1 = output file, $2 = path
	curl -sS -o "$1" -w '%{http_code}' \
		-u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
		"${BASE}$2" || echo 000
}

# ── 0. Instance settings the suite cannot set for itself on CI ───────────────
# `ratelimit.protection.enabled=false`: see reason (2) in the header. The limit
# is correct product behaviour and is deliberately NOT changed in the app; it is
# turned off for this throwaway test instance only, which is Nextcloud's own
# supported switch. Without it the eleventh app creation in the run 429s and
# every later spec fails for an unrelated reason.
#
# Non-fatal by design: if `occ` is unreachable the run continues and the 429s
# simply reappear, with this warning in the log to explain them. Making it fatal
# would trade a legible late failure for an early one with no more information.
#
# `htaccess.IgnoreFrontController=true`: THE single most important line in this
# file. See the long note below — without it every deep-linking spec silently
# lands on the Dashboard.
if [ -f "${SERVER_DIR}/occ" ]; then
	if (cd "${SERVER_DIR}" && php occ config:system:set ratelimit.protection.enabled --value=false --type=boolean); then
		echo "[ci-seed] rate-limit protection disabled for this instance."
	else
		echo "::warning::Could not disable ratelimit.protection.enabled — expect HTTP 429 from the app-creation wizard after 10 creates."
	fi

	# ── PRETTY URLs — why a deep link silently became the Dashboard ──────────
	#
	# buildiq's SPA builds its router as
	#
	#     createWebHistory(generateUrl('/apps/buildiq'))     (src/main.js)
	#
	# and `generateUrl()` from @nextcloud/router is:
	#
	#     if (window.OC.config.modRewriteWorking === true) return webroot + path
	#     return webroot + '/index.php' + path
	#
	# Nextcloud sets `modRewriteWorking` from `htaccess.IgnoreFrontController`.
	# A real Apache install has it on (that is what the shipped .htaccess is
	# for), so the router base is `/apps/buildiq` and every spec's
	# `page.goto('/apps/buildiq/applications')` matches.
	#
	# A freshly `occ maintenance:install`ed instance behind `php -S` does NOT
	# have it set. The router base is then `/index.php/apps/buildiq`, which is
	# NOT a prefix of the URL the spec opened — so vue-router matches nothing
	# and falls back to the default route. The SPA mounts, renders perfectly,
	# and shows the DASHBOARD. No error, no 404, no console warning: the app
	# just quietly is not on the page the spec asked for.
	#
	# That is the shared root cause behind the great majority of the 67
	# failures measured on run 30893236971 (job 91940627335). Every one of them
	# was a selector for a NON-Dashboard surface — `.ob-va-actions`,
	# `.agents-page`, `.automations-page`, `.page-designer__left`,
	# `.ob-detail-header`, `.ob-app-card` — timing out, and every failure
	# screenshot in the artifacts shows the same Dashboard. The specs that
	# passed are exactly the two classes this cannot touch: pure `request.get()`
	# API tests, and builder-host, whose builder.js router is based on
	# `/apps/buildiq/builder/<slug>` and which the spec opens AT that base.
	#
	# createApplicationWizard.spec.ts already carries a live-verified note about
	# the mirror image of this on the dev box ("the `/index.php/`-prefixed form
	# of this deep link redirects to the bare Dashboard, silently dropping the
	# sub-path"). Same bug, opposite instance: whichever URL shape disagrees
	# with `generateUrl()` loses its sub-path.
	#
	# The shared workflow's `ci-router.php` already serves pretty URLs correctly
	# (it mirrors Nextcloud's .htaccess and gates on `/apps/files/` not 404ing),
	# so turning this on states something TRUE about the instance. It is not a
	# workaround — it aligns the CI box with every real deployment.
	if (cd "${SERVER_DIR}" && php occ config:system:set htaccess.IgnoreFrontController --value=true --type=boolean); then
		echo "[ci-seed] pretty URLs enabled (htaccess.IgnoreFrontController=true)."
	else
		echo "::warning::Could not set htaccess.IgnoreFrontController."
	fi
else
	echo "::warning::No occ at ${SERVER_DIR}/occ — skipping instance configuration."
fi

# ── 1. Import the Buildiq configuration ────────────────────────────────────
# buildiq's appinfo/routes.php returns \OCA\OpenRegister\AppHost\Routes::standard(),
# whose canonical table ships `settings#load` at POST /api/settings/load. On
# buildiq that resolves to OCA\Buildiq\Controller\SettingsController::load(),
# which calls SettingsService::reloadConfiguration() → doLoadConfiguration(force: true)
# — precisely the forced import the repair step cannot perform, and the only path
# that also deep-merges the ADR-037 register.d/*.json fragments (business rules,
# automations, component blocks, agent workspace) onto the base register.
#
# It is admin-only (an explicit isInGroup('admin') body check returning 403), so
# HTTP Basic as admin is required.
#
# `OCS-APIRequest: true` is load-bearing, not decoration: Nextcloud's
# Request::passesCSRFCheck() short-circuits to true on that header (the
# strict-cookie precondition is satisfied because a Basic-auth request carries
# no session cookie at all). Without the header this POST is rejected as a CSRF
# failure.
IMPORT_URL="${BASE}/index.php/apps/buildiq/api/settings/load"
echo "[ci-seed] POST ${IMPORT_URL} (forced import)"

IMPORT_BODY="$(mktemp)"
IMPORT_CODE="$(
	curl -sS -o "$IMPORT_BODY" -w '%{http_code}' \
		-u "${USER_NAME}:${USER_PASS}" \
		-X POST \
		-H 'Content-Type: application/json' \
		-H 'OCS-APIRequest: true' \
		--data '{}' \
		"$IMPORT_URL" || echo 000
)"

echo "[ci-seed] settings#load HTTP ${IMPORT_CODE}"
head -c 2000 "$IMPORT_BODY"; echo

# HTTP 200 is necessary but NOT sufficient: the controller returns the service
# result verbatim, and a failed import is reported as `{"success": false, …}`
# with a 200. Treat anything that is not an explicit success as a reason to try
# the generic importer below, and let the verification step decide the outcome.
IMPORT_OK=0
if [ "$IMPORT_CODE" = "200" ] && grep -q '"success":[[:space:]]*true' "$IMPORT_BODY"; then
	IMPORT_OK=1
	echo "[ci-seed] buildiq settings#load reported success."
else
	echo "[ci-seed] buildiq settings#load did not report success; falling back to the OpenRegister importer."
fi

# ── 1b. Fallback: OpenRegister's generic configuration importer ──────────────
# Independent of buildiq's own controller wiring, so it still provisions the
# base register if `settings#load` is unavailable (e.g. an OpenRegister build
# whose AppHost route table predates it). Admin-only. It reads the upload under
# the literal form key `file`; a raw JSON body is NOT an accepted shape.
#
# ⚠️ This fallback imports the BASE register only — it cannot perform the
# register.d/*.json deep-merge, which lives in buildiq's own SettingsService.
# The verification step below therefore still fails if we end up here, which is
# the intended outcome: a partial provision must not read as a success.
if [ "$IMPORT_OK" != "1" ]; then
	REGISTER_JSON="${APP_DIR}/lib/Settings/openbuild_register.json"
	if [ ! -f "$REGISTER_JSON" ]; then
		echo "::error::openbuild_register.json not found at ${REGISTER_JSON}."
		exit 1
	fi

	OR_URL="${BASE}/index.php/apps/openregister/api/configurations/import"
	echo "[ci-seed] POST ${OR_URL} (file=openbuild_register.json, force=true)"
	OR_BODY="$(mktemp)"
	OR_CODE="$(
		curl -sS -o "$OR_BODY" -w '%{http_code}' \
			-u "${USER_NAME}:${USER_PASS}" \
			-X POST \
			-H 'OCS-APIRequest: true' \
			-F "file=@${REGISTER_JSON}" \
			-F 'force=true' \
			-F 'appId=buildiq' \
			"$OR_URL" || echo 000
	)"
	echo "[ci-seed] configurations/import HTTP ${OR_CODE}"
	head -c 2000 "$OR_BODY"; echo
fi

# ── 2. Verify the register and schemas are actually there ────────────────────
# An import reporting success is not the same as the register existing.
#
# The required slugs below are READ OUT OF THE REPO, not guessed: every schema
# in lib/Settings/openbuild_register.json and lib/Settings/register.d/*.json
# carries an EXPLICIT top-level `slug` key, and OpenRegister's SchemaMapper only
# derives one from the title when that key is absent. Note in particular that
# `applicationVersion` is camelCase and `rule-test-case` does not match its
# title ("TestCase") — mechanically kebab-casing the titles would produce four
# wrong slugs here. The register slug is `buildiq`, taken from
# `x-openregister.app`, which is what
# ImportHandler::autoCreateRegisterIfApplication() uses for an
# `x-openregister.type: application` configuration.
#
# The HTTP status is captured and checked separately from the payload on
# purpose: an endpoint that 404s or redirects to the login form yields an empty
# slug set, which is indistinguishable from "the import produced nothing" if you
# only look at the parsed list. A wrong lookup manufactures an absence for free.
verify() {
	python3 - "$1" "$2" "$3" <<'PY'
import json, sys
path, kind, code = sys.argv[1], sys.argv[2], sys.argv[3]
required = {
    # The register slug moved to 'buildiq' with this change. The note that
    # used to sit here said the opposite — that the slug stays 'openbuild'
    # because it addresses a register that already exists — which was true
    # only while the rename was the APP id alone. This PR renames the register
    # too, and MigrateRegisterSlug renames the existing row ahead of the
    # import, so the row this addresses is the same one; it answers to a new
    # name. The FILE is still openbuild_register.json and is referenced by
    # path elsewhere in this script: that is a filename, not an identifier
    # anything resolves.
    'registers': ['buildiq'],
    'schemas': [
        # lib/Settings/openbuild_register.json
        'application', 'application-template', 'built-app-route',
        'hello-message', 'applicationVersion', 'export-job',
        # lib/Settings/register.d/10-business-rules.json
        'rule-set', 'decision-table', 'condition-action-rule',
        'rule-execution-log', 'rule-test-case',
        # lib/Settings/register.d/40|60|70-*.json
        'automation', 'component-block', 'agent', 'agent-run',
    ],
}[kind]
with open(path) as fh:
    raw = fh.read()
if code != '200':
    print(f'::error::OpenRegister {kind} endpoint returned HTTP {code}, so the '
          f'slug list below proves nothing about the import. First 500 bytes:')
    print(raw[:500])
    sys.exit(1)
try:
    body = json.loads(raw)
except json.JSONDecodeError:
    print(f'::error::{kind} endpoint did not return JSON (HTTP 200). First 500 bytes:')
    print(raw[:500])
    sys.exit(1)
items = body if isinstance(body, list) else body.get('results', [])
slugs = {i.get('slug') for i in items if isinstance(i, dict)}
missing = [s for s in required if s not in slugs]
print(f'[ci-seed] {kind} present: {sorted(s for s in slugs if s)}')
if missing:
    print(f'::error::Buildiq {kind} missing after import: {missing}')
    print('::error::The e2e suite cannot create an application, schema, page or '
          'automation without them; every UI spec would fail on an empty list.')
    sys.exit(1)
print(f'[ci-seed] {kind} OK ({len(required)} required slugs present)')
PY
}

REG_BODY="$(mktemp)"
REG_CODE="$(api_get "$REG_BODY" "/index.php/apps/openregister/api/registers?_limit=300")"
verify "$REG_BODY" registers "$REG_CODE"

SCH_BODY="$(mktemp)"
SCH_CODE="$(api_get "$SCH_BODY" "/index.php/apps/openregister/api/schemas?_limit=1000")"
verify "$SCH_BODY" schemas "$SCH_CODE"

# The register existing is still not the same as it being READABLE by the admin
# session the specs use. Probe the collection the suite actually lists so that
# failure mode has a name here rather than as a timeout on an empty table.
OBJ_CODE="$(curl -sS -o /dev/null -w '%{http_code}' \
	-u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/openregister/api/objects/buildiq/application?_limit=1" || echo 000)"
echo "[ci-seed] objects/buildiq/application probe -> ${OBJ_CODE}"
if [ "$OBJ_CODE" -ge 400 ] 2>/dev/null; then
	echo "::error::The buildiq application collection is not readable (HTTP ${OBJ_CODE})."
	exit 1
fi

echo "[ci-seed] Buildiq register + schemas provisioned."

# ── 3. Seed the deterministic `hello-world` virtual-app fixture ──────────────
# Production no longer ships a hello-world seed (the SeedHelloWorld repair step
# was retired with the versioned-model migration), so the suite seeds it itself
# via the test-only occ command, which writes with `_rbac: false` and therefore
# works from a session-less CLI context. global-setup.ts also tries this, but
# only through `docker exec` — which resolves to nothing on a CI runner (see
# header reason 2). Run it here, where `occ` actually is.
#
# Idempotent: the command short-circuits once the hello-world Application (or
# its BuiltAppRoute) exists.
if [ -f "${SERVER_DIR}/occ" ]; then
	echo "[ci-seed] occ buildiq:seed-hello-world-fixture"
	if ! (cd "${SERVER_DIR}" && php occ buildiq:seed-hello-world-fixture); then
		echo "::error::Seeding the hello-world fixture failed."
		echo "::error::Specs that open the canonical hello-world virtual app would fail on an empty page."
		exit 1
	fi

	# Prove the fixture is actually there, over HTTP, as the specs will see it.
	# The occ command exiting 0 covers 'already present — skipping' as well as a
	# successful create, so its exit code alone is not evidence of existence.
	HW_BODY="$(mktemp)"
	HW_CODE="$(api_get "$HW_BODY" "/index.php/apps/buildiq/api/applications/hello-world/manifest")"
	echo "[ci-seed] hello-world manifest -> HTTP ${HW_CODE}"
	if [ "$HW_CODE" != "200" ]; then
		head -c 500 "$HW_BODY"; echo
		echo "::error::The hello-world fixture is not resolvable at /api/applications/hello-world/manifest (HTTP ${HW_CODE})."
		exit 1
	fi
else
	echo "::warning::No occ at ${SERVER_DIR}/occ — hello-world fixture NOT seeded."
fi

# ── 3b. Make the CI admin a RETURNING user for the first-visit overlays ──────
#
# nc-vue's CnAppRoot auto-mounts two first-visit overlays over the shell, and
# BOTH render a full-viewport backdrop that swallows pointer events:
#
#   - the `CnWalkthrough` product tour declared in src/manifest.json
#     (`walkthrough.trigger: "first-visit"`), and
#   - the `CnSupportDialog` "Support Openbuild" note.
#
# For a real user each is shown once and then never again. For this suite they
# are shown in EVERY test, because Playwright gives every test a FRESH browser
# context — so the localStorage mirror that records "seen" is always empty and
# every single test is, to the app, a first visit. Measured on run 30889538697:
# the tour was up in the failure screenshot of most of the 66 failing tests,
# and the surviving spec files are the ones that already call the per-spec
# `suppressSupportDialog()` / `dismissFirstVisitOverlays()` helpers.
#
# The fix used here is the PRODUCT'S OWN returning-user mechanism, not a test
# hack and not a per-spec workaround: both overlays persist "seen" as a
# per-user preference through `PUT /apps/buildiq/api/preferences/{key}`
# (nc-vue `persistWalkthroughSeenVersion()` and the support dialog's
# `support-dialog-seen`), and read it back on mount. Writing it once here for
# the admin the suite runs as makes every fresh context a returning user.
#
# Deliberately ONLY for the admin. `global-setup.ts` also mints rbac-owner /
# rbac-editor / rbac-viewer / rbac-outsider sessions, and
# `non-admin-access.spec.ts` asserts that a non-admin is never blocked by a
# first-run overlay they cannot complete. Pre-marking those users would make
# that assertion pass without the product doing anything — the exact failure
# mode that file's own header was written to prevent. They stay untouched.
#
# Non-fatal: on failure the overlays reappear and the specs fail visibly, which
# is the honest outcome. The version is deliberately far above any real app
# version so the step-composition filter empties every tour.
set_pref() {
	# $1 = preference key, $2 = value
	code="$(curl -sS -o /dev/null -w '%{http_code}' \
		-u "${USER_NAME}:${USER_PASS}" \
		-X PUT \
		-H 'Content-Type: application/json' \
		-H 'OCS-APIRequest: true' \
		--data "{\"value\":\"$2\"}" \
		"${BASE}/index.php/apps/buildiq/api/preferences/$1" || echo 000)"
	echo "[ci-seed] preference $1=$2 -> HTTP ${code}"
	if [ "$code" != "200" ]; then
		echo "::warning::Could not set the '$1' preference (HTTP ${code}) — the first-visit overlay will re-open in every test and swallow clicks."
	fi
}

# Keys as the manifest and nc-vue declare them. Note the app's own
# PreferencesController::sanitizeKey() strips characters outside [a-z0-9-], so
# the stored key is the sanitised form — but the READ path sanitises
# identically, so writing the declared key is correct.
set_pref 'walkthrough_completed_version' '999.0.0'
set_pref 'support-dialog-seen' '1'

# ── THE THIRD FIRST-RUN OVERLAY: the SETUP WIZARD ───────────────────────────
#
# The two preferences above cover the walkthrough and the support note. They do
# NOT cover the configuration wizard, which is a different mechanism: the
# manifest declares `setup.completionConfigKey`, and CnAppRoot reads it from
# APP CONFIG rather than from a per-user preference.
#
# This did not matter until @conduction/nextcloud-vue 2.21. Before it,
# `CnAppRoot.optionalSetupPending` short-circuited on `/api/setup/status`
# reporting `completed: true` — which it does whenever no REQUIRED step is
# outstanding — so the wizard never opened and nothing here had to suppress it.
# nextcloud-vue#806 fixed that bug. The wizard now opens as designed, lands a
# `role="dialog" aria-modal="true"` over the page, and every click in the suite
# is intercepted by `.modal-container__content`.
#
# The symptom is NOT "the wizard is up". Playwright reports the target element
# as found, visible, enabled and stable, and then retries the click ~55 times
# against the overlay until the test times out — so it reads as a slow or flaky
# page, not as a modal. 38 specs across agents, applications and automations
# failed this way while the app itself was healthy: the bundle served 200 at
# 14MB, every register and schema seeded, `api/applications` answered 200.
#
# Same principle as the block above: use the product's own returning-user
# mechanism rather than a per-spec dismissal. Writing the completed version
# makes the instance one that has already been configured.
#
# Non-fatal on purpose. If this fails the wizard reappears and the specs fail
# visibly, which is the honest outcome.
if [ -f "${SERVER_DIR}/occ" ]; then
	if (cd "${SERVER_DIR}" && php occ config:app:set buildiq setup_completed_version --value=1); then
		echo "[ci-seed] setup wizard marked complete (setup_completed_version=1)."
	else
		echo "::warning::Could not set buildiq setup_completed_version — the configuration wizard will open over every page and swallow clicks."
	fi
fi

# ── 3d. Configure Docudesk's template register, when Docudesk is installed ───
#
# WHY THIS IS HERE AND NOT IN global-setup.ts. It WAS only there
# (`seedDocudeskTemplateFixtures()`), and `global-setup.ts` is a Playwright
# hook — the Newman leg never runs it. So the `Integration Tests (Newman)` job
# booted a Docudesk whose `template_register`/`template_schema` were unset, and
# `buildiq-docudesk-documents.postman_collection.json` items 4 and 6 got a
# **500**, not the 4xx they assert:
#
#   OCA\DocuDesk\Exception\RegisterNotConfiguredException
#   "Template register/schema not configured"
#   OpenRegisterResolver.php:68 → TemplateService.php:171
#     → CorrespondenceService.php:200 → CorrespondenceController.php:107
#
# Item 1 passed throughout, which is what made this look like an buildiq
# defect: Docudesk's TemplatesController CATCHES that exception and answers
# `{results: [], total: 0, notConfigured: true}`, while CorrespondenceController
# has no such catch and lets it escape as a 500. Same missing configuration,
# two different-looking symptoms.
#
# Configuring it makes "unknown templateId" mean what the assertion says it
# means — a template that is not there — rather than "this instance has no
# template storage at all".
#
# Docudesk is OPTIONAL. A 404/501 on the settings probe is a clean skip, not a
# failure: buildiq's own specs must not require a sibling app to be present.
DD_BODY="$(mktemp)"
DD_CODE="$(api_get "$DD_BODY" "/index.php/apps/docudesk/api/settings")"
if [ "$DD_CODE" = "404" ] || [ "$DD_CODE" = "501" ]; then
	echo "[ci-seed] docudesk not installed (HTTP ${DD_CODE}) — template register not configured, skipping."
elif [ "$DD_CODE" != "200" ]; then
	echo "[ci-seed] docudesk settings probe returned HTTP ${DD_CODE} — template register not configured, skipping."
else
	# Resolve Docudesk's own register and its `template` schema BY SLUG. The
	# settings payload already carries the register list with nested schemas, and
	# slugs are stable across instances where the numeric ids are not.
	DD_IDS="$(python3 - "$DD_BODY" <<'PY'
import json, sys
try:
    with open(sys.argv[1]) as fh:
        body = json.load(fh)
except Exception:
    sys.exit(0)
cfg = body.get('configuration') or {}
if cfg.get('template_register') and cfg.get('template_schema'):
    print('ALREADY')
    sys.exit(0)
register = next(
    (r for r in (body.get('availableRegisters') or [])
     if isinstance(r, dict) and r.get('slug') == 'docudesk'),
    None,
)
if register is None:
    sys.exit(0)
schema = next(
    (s for s in (register.get('schemas') or [])
     if isinstance(s, dict) and s.get('slug') == 'template'),
    None,
)
if schema is None:
    sys.exit(0)
print(f"{register.get('id')} {schema.get('id')}")
PY
)"
	if [ "$DD_IDS" = "ALREADY" ]; then
		echo "[ci-seed] docudesk template register already configured — nothing to do."
	elif [ -z "$DD_IDS" ]; then
		echo "[ci-seed] docudesk register or its 'template' schema not found — template register not configured."
	else
		DD_REG="${DD_IDS% *}"
		DD_SCH="${DD_IDS#* }"
		DD_WRITE="$(curl -sS -o /dev/null -w '%{http_code}' -X POST \
			-u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
			-H 'Content-Type: application/json' \
			-d "{\"template_register\":\"${DD_REG}\",\"template_schema\":\"${DD_SCH}\",\"template_source\":\"openregister\"}" \
			"${BASE}/index.php/apps/docudesk/api/settings" || echo 000)"
		echo "[ci-seed] docudesk template register configured (register=${DD_REG}, schema=${DD_SCH}, HTTP ${DD_WRITE})."
		# Read it BACK. A 200 on the write is not evidence the value stuck — this
		# is the same class of mistake as trusting an import's success message.
		DD_CHECK="$(mktemp)"
		DD_CHECK_CODE="$(api_get "$DD_CHECK" "/index.php/apps/docudesk/api/settings")"
		python3 - "$DD_CHECK" "$DD_CHECK_CODE" <<'PY'
import json, sys
path, code = sys.argv[1], sys.argv[2]
if code != '200':
    print(f'::warning::docudesk settings re-read returned HTTP {code}; template configuration unverified.')
    sys.exit(0)
try:
    with open(path) as fh:
        cfg = (json.load(fh).get('configuration') or {})
except Exception:
    print('::warning::docudesk settings re-read was not JSON; template configuration unverified.')
    sys.exit(0)
if cfg.get('template_register') and cfg.get('template_schema'):
    print('[ci-seed] docudesk template configuration verified on read-back.')
else:
    print('::warning::docudesk accepted the settings write but the values did not stick — '
          'correspondence generate will still answer 500 for an unknown templateId.')
PY
	fi
fi

# ── 3c. GATE: the SERVED page must actually advertise pretty URLs ────────────
#
# Setting `htaccess.IgnoreFrontController` above is not evidence that the SPA
# will see it — `occ` writes config.php, but what matters is the value
# Nextcloud renders into `OC.config.modRewriteWorking` on the page the browser
# loads, which is what `generateUrl()` reads to build the router base.
#
# Assert on the SERVED HTML, not on the config we just wrote. Getting this
# wrong is invisible in exactly the way that matters: the app still boots,
# still renders, still returns HTTP 200 — it is simply on the wrong route, and
# every downstream failure then accuses a selector.
PRETTY_HTML="$(mktemp)"
PRETTY_CODE="$(api_get "$PRETTY_HTML" "/index.php/apps/buildiq/")"
if [ "$PRETTY_CODE" != "200" ]; then
	echo "::error::Could not fetch the Buildiq app page to verify pretty URLs (HTTP ${PRETTY_CODE})."
	exit 1
fi
if grep -q '"modRewriteWorking":true\|"modRewriteWorking": *true' "$PRETTY_HTML"; then
	echo "[ci-seed] served page reports modRewriteWorking:true — the SPA router base will be /apps/buildiq."
else
	echo "::error::The served Buildiq page does NOT report modRewriteWorking:true."
	echo "::error::generateUrl() will therefore return '/index.php/apps/buildiq' as the vue-router base,"
	echo "::error::while every spec navigates to the pretty '/apps/buildiq/...' form. Those disagree, so"
	echo "::error::vue-router matches nothing and silently falls back to the Dashboard — the SPA mounts and"
	echo "::error::renders fine, on the wrong page, and every non-Dashboard selector times out blaming itself."
	grep -o 'modRewriteWorking[^,}]*' "$PRETTY_HTML" | head -3 || echo "  (key not present in the page at all)"
	exit 1
fi

# ── 4. Warm the SPA so the first spec doesn't pay the cold start ─────────────
# Failures are ignored on purpose: this is a warm-up, not a gate. The real
# checks are above and in the bundle gate below.
for path in \
	"/index.php/apps/buildiq/" \
	"/index.php/settings/admin/buildiq" \
	"/index.php/apps/buildiq/api/applications" \
	"/index.php/apps/openregister/api/registers?_limit=1"
do
	code="$(curl -sS -o /dev/null -w '%{http_code}' -u "${USER_NAME}:${USER_PASS}" \
		-H 'OCS-APIRequest: true' "${BASE}${path}" || echo 000)"
	echo "[ci-seed] warm ${path} -> ${code}"
done

# Pull the main webpack bundle once so it is in the page cache.
#
# Do NOT hardcode the URL. Nextcloud serves an app's assets from whichever apps
# directory it was installed into — `/apps/buildiq/js/…` on the CI runner,
# `/custom_apps/buildiq/js/…` in the docker dev images — and asking for the
# wrong one does not 404: it returns HTTP 200 with `text/html`, the NC error page
# served through index.php. Read the real src out of the rendered app page.
APP_HTML="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/buildiq/" -o "$APP_HTML" || true

# `|| true` is load-bearing: grep exits 1 when it matches nothing, and under
# `set -euo pipefail` that would abort the script right here — so the very case
# the gate below exists to explain (no bundle) would die with a bare non-zero
# exit and none of the diagnosis.
BUNDLE_SRC="$(grep -oE 'src="[^"]*buildiq-main[^"]*"' "$APP_HTML" \
	| head -1 | sed 's/^src="//; s/"$//' || true)"

if [ -n "$BUNDLE_SRC" ]; then
	BUNDLE_INFO="$(curl -sS -o /dev/null \
		-w '%{http_code} %{content_type} %{size_download}' \
		-u "${USER_NAME}:${USER_PASS}" "${BASE}${BUNDLE_SRC}" || echo '000 - 0')"
	echo "[ci-seed] warm bundle ${BUNDLE_SRC} -> ${BUNDLE_INFO}"
else
	echo "[ci-seed] could not locate the bundle src in the rendered app page."
	BUNDLE_INFO=""
fi

# On CI this is a GATE, not a warm-up.
#
# The single most likely way this job "succeeds" dishonestly is by passing
# without ever loading the app — and the environment hides it well: when the
# bundle is absent, Nextcloud does not 404. It serves its HTML error page with
# HTTP 200 and Content-Type text/html, so `npm run build` producing nothing
# looks, to every status-code check in the pipeline, exactly like success.
#
# This gate reads the SERVED response, not the file on disk, and it is placed at
# the very end so a run that reaches the specs has provably been able to fetch
# real JavaScript for the SPA.
#
# ⚠️ THE CONTENT TYPE ALONE IS NOT ENOUGH — hence the size floor below.
# A TRUNCATED (or zero-byte) bundle still serves `200 application/javascript`,
# so a content-type-only gate passes over exactly the failure it exists to
# catch. Reported live on a sibling repo, where such a gate did precisely that.
# The floor is deliberately far below the real figure (measured on run
# 31040914410: 13,552,436 bytes) so it fires on a truncation or an empty file
# without becoming a second, silently-drifting size budget to maintain.
BUNDLE_MIN_BYTES=100000
#
# SEED_REQUIRE_BUNDLE=0 — for API-ONLY consumers of this script.
#
# The Newman job seeds with this same script (so there is one fixture set, not
# two that can drift), but it never loads the SPA: it makes HTTP calls against
# the REST API and asserts on JSON. It also does not run the frontend build, so
# there is no bundle for it to serve. Requiring one there would fail the seed on
# an artefact that suite neither needs nor produces.
#
# The default is 1 — ENFORCE. Playwright is unchanged, and the gate below stays
# exactly as able to fail as it was. The opt-out has to be set deliberately by
# the caller, and when it is, the skip is ANNOUNCED: a gate that quietly stops
# checking is the failure this whole file was written to prevent, so a skipped
# bundle check must never be mistakable for a passed one.
if [ "${SEED_REQUIRE_BUNDLE:-1}" != "1" ]; then
	echo "[ci-seed] SEED_REQUIRE_BUNDLE=0 — SPA bundle NOT verified by this run."
	echo "[ci-seed] The API fixtures above ARE seeded; only the browser artefact is unchecked."
elif [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
	case "$BUNDLE_INFO" in
		*javascript*)
			echo "[ci-seed] bundle verified as JavaScript."
			;;
		*)
			echo "::error::The Buildiq frontend bundle did not serve as JavaScript (got: ${BUNDLE_INFO:-<not found>})."
			echo "::error::The SPA cannot mount, so every UI spec would fail on a selector timeout with a misleading cause."
			echo "::error::Check the 'Build app frontend' step — a missing bundle returns HTTP 200 text/html, not 404."
			exit 1
			;;
	esac

	# BUNDLE_INFO is "<code> <content_type> <size_download>"; take the third field.
	BUNDLE_BYTES="$(printf '%s\n' "$BUNDLE_INFO" | awk '{print $3}')"
	case "$BUNDLE_BYTES" in
		''|*[!0-9]*)
			echo "::error::Could not read a byte count for the served bundle (got: ${BUNDLE_INFO})."
			exit 1
			;;
	esac
	if [ "$BUNDLE_BYTES" -lt "$BUNDLE_MIN_BYTES" ]; then
		echo "::error::The Buildiq frontend bundle served only ${BUNDLE_BYTES} bytes (floor: ${BUNDLE_MIN_BYTES})."
		echo "::error::Content-Type was JavaScript, so the check above passed — a truncated or empty bundle"
		echo "::error::is served as 200 application/javascript and is indistinguishable from a good one by"
		echo "::error::status and type alone. The SPA will not mount and every UI spec will blame its selector."
		exit 1
	fi
	echo "[ci-seed] bundle size OK (${BUNDLE_BYTES} bytes >= ${BUNDLE_MIN_BYTES})."
fi

echo "[ci-seed] done."
