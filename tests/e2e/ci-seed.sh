#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 Conduction B.V.
# SPDX-License-Identifier: EUPL-1.2
#
# Provision OpenBuild's OpenRegister register + schemas, the `hello-world`
# virtual-app fixture and the instance settings the e2e suite depends on, for
# the shared `E2E Tests (Playwright)` CI job.
#
# Wired up as the workflow's `playwright-seed-command`. That step runs AFTER
# `php -S` is up and with cwd set to the Nextcloud server root, so this is
# invoked as:
#
#     playwright-seed-command: 'bash apps/openbuild/tests/e2e/ci-seed.sh'
#
# WHY THIS IS NEEDED — four separate reasons, all of which fail SILENTLY.
# ----------------------------------------------------------------------
#
#  1. `occ app:enable openbuild` runs the `InitializeSettings` post-migration
#     repair step, which is supposed to import lib/Settings/openbuild_register.json
#     (plus the ADR-037 register.d/*.json fragments) into OpenRegister. An
#     IRepairStep runs with NO user session, so OpenRegister's RBAC denies the
#     import outright ("User 'Anonymous' does not have permission to 'create'
#     objects in schema '…'"). `InitializeSettings::run()` catches \Throwable and
#     downgrades it to a warning, so `occ app:enable openbuild` still exits 0.
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
if [ -f "${SERVER_DIR}/occ" ]; then
	if (cd "${SERVER_DIR}" && php occ config:system:set ratelimit.protection.enabled --value=false --type=boolean); then
		echo "[ci-seed] rate-limit protection disabled for this instance."
	else
		echo "::warning::Could not disable ratelimit.protection.enabled — expect HTTP 429 from the app-creation wizard after 10 creates."
	fi
else
	echo "::warning::No occ at ${SERVER_DIR}/occ — skipping instance configuration."
fi

# ── 1. Import the OpenBuild configuration ────────────────────────────────────
# openbuild's appinfo/routes.php returns \OCA\OpenRegister\AppHost\Routes::standard(),
# whose canonical table ships `settings#load` at POST /api/settings/load. On
# openbuild that resolves to OCA\OpenBuild\Controller\SettingsController::load(),
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
IMPORT_URL="${BASE}/index.php/apps/openbuild/api/settings/load"
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
	echo "[ci-seed] openbuild settings#load reported success."
else
	echo "[ci-seed] openbuild settings#load did not report success; falling back to the OpenRegister importer."
fi

# ── 1b. Fallback: OpenRegister's generic configuration importer ──────────────
# Independent of openbuild's own controller wiring, so it still provisions the
# base register if `settings#load` is unavailable (e.g. an OpenRegister build
# whose AppHost route table predates it). Admin-only. It reads the upload under
# the literal form key `file`; a raw JSON body is NOT an accepted shape.
#
# ⚠️ This fallback imports the BASE register only — it cannot perform the
# register.d/*.json deep-merge, which lives in openbuild's own SettingsService.
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
			-F 'appId=openbuild' \
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
# wrong slugs here. The register slug is `openbuild`, taken from
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
    'registers': ['openbuild'],
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
    print(f'::error::OpenBuild {kind} missing after import: {missing}')
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
	"${BASE}/index.php/apps/openregister/api/objects/openbuild/application?_limit=1" || echo 000)"
echo "[ci-seed] objects/openbuild/application probe -> ${OBJ_CODE}"
if [ "$OBJ_CODE" -ge 400 ] 2>/dev/null; then
	echo "::error::The openbuild application collection is not readable (HTTP ${OBJ_CODE})."
	exit 1
fi

echo "[ci-seed] OpenBuild register + schemas provisioned."

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
	echo "[ci-seed] occ openbuild:seed-hello-world-fixture"
	if ! (cd "${SERVER_DIR}" && php occ openbuild:seed-hello-world-fixture); then
		echo "::error::Seeding the hello-world fixture failed."
		echo "::error::Specs that open the canonical hello-world virtual app would fail on an empty page."
		exit 1
	fi

	# Prove the fixture is actually there, over HTTP, as the specs will see it.
	# The occ command exiting 0 covers 'already present — skipping' as well as a
	# successful create, so its exit code alone is not evidence of existence.
	HW_BODY="$(mktemp)"
	HW_CODE="$(api_get "$HW_BODY" "/index.php/apps/openbuild/api/applications/hello-world/manifest")"
	echo "[ci-seed] hello-world manifest -> HTTP ${HW_CODE}"
	if [ "$HW_CODE" != "200" ]; then
		head -c 500 "$HW_BODY"; echo
		echo "::error::The hello-world fixture is not resolvable at /api/applications/hello-world/manifest (HTTP ${HW_CODE})."
		exit 1
	fi
else
	echo "::warning::No occ at ${SERVER_DIR}/occ — hello-world fixture NOT seeded."
fi

# ── 4. Warm the SPA so the first spec doesn't pay the cold start ─────────────
# Failures are ignored on purpose: this is a warm-up, not a gate. The real
# checks are above and in the bundle gate below.
for path in \
	"/index.php/apps/openbuild/" \
	"/index.php/settings/admin/openbuild" \
	"/index.php/apps/openbuild/api/applications" \
	"/index.php/apps/openregister/api/registers?_limit=1"
do
	code="$(curl -sS -o /dev/null -w '%{http_code}' -u "${USER_NAME}:${USER_PASS}" \
		-H 'OCS-APIRequest: true' "${BASE}${path}" || echo 000)"
	echo "[ci-seed] warm ${path} -> ${code}"
done

# Pull the main webpack bundle once so it is in the page cache.
#
# Do NOT hardcode the URL. Nextcloud serves an app's assets from whichever apps
# directory it was installed into — `/apps/openbuild/js/…` on the CI runner,
# `/custom_apps/openbuild/js/…` in the docker dev images — and asking for the
# wrong one does not 404: it returns HTTP 200 with `text/html`, the NC error page
# served through index.php. Read the real src out of the rendered app page.
APP_HTML="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/openbuild/" -o "$APP_HTML" || true

# `|| true` is load-bearing: grep exits 1 when it matches nothing, and under
# `set -euo pipefail` that would abort the script right here — so the very case
# the gate below exists to explain (no bundle) would die with a bare non-zero
# exit and none of the diagnosis.
BUNDLE_SRC="$(grep -oE 'src="[^"]*openbuild-main[^"]*"' "$APP_HTML" \
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
if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
	case "$BUNDLE_INFO" in
		*javascript*)
			echo "[ci-seed] bundle verified as JavaScript."
			;;
		*)
			echo "::error::The OpenBuild frontend bundle did not serve as JavaScript (got: ${BUNDLE_INFO:-<not found>})."
			echo "::error::The SPA cannot mount, so every UI spec would fail on a selector timeout with a misleading cause."
			echo "::error::Check the 'Build app frontend' step — a missing bundle returns HTTP 200 text/html, not 404."
			exit 1
			;;
	esac
fi

echo "[ci-seed] done."
