<?php

/**
 * OpenBuild AppOverrideController
 *
 * HTTP surface for the per-instance shared manifest override of an EXISTING
 * Nextcloud fleet app. Three routes:
 *   - GET    /api/app-overrides/{appId} — return the stored manifestDelta raw
 *            (or an empty delta `{}` when none). NEVER merges server-side —
 *            the fleet app's loader merges client-side over its bundled base
 *            (design D2).
 *   - PUT    /api/app-overrides/{appId} — validate the delta shape + non-blank
 *            guard, upsert the AppOverride, record updatedBy from the session.
 *            CSRF-enforced; rejects anonymous (401) and out-of-OpenBuild-scope
 *            callers (403); rejects malformed / app-blanking deltas (422).
 *   - DELETE /api/app-overrides/{appId} — idempotently clear the override.
 *            CSRF-enforced; same auth posture as PUT.
 *
 * The write/delete authorization guard is "the caller has OpenBuild access"
 * (the app is enabled for them under its NC app group-restriction), NOT a
 * per-object owner check — an AppOverride is a per-instance shared
 * customization with no owner (design D1 / no-admin-idor for a shared
 * resource).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenBuild\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/unify-apps-with-app-type/specs/app-override-persistence/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Controller;

use OCA\OpenBuild\AppInfo\Application;
use OCA\OpenBuild\Service\AppOverrideService;
use OCA\OpenBuild\Service\PermissionResolver;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for the fleet-app manifest-override store-and-serve endpoints.
 *
 * @spec openspec/changes/unify-apps-with-app-type/specs/app-override-persistence/spec.md
 */
class AppOverrideController extends Controller
{
    /**
     * Kebab-case Nextcloud app-id pattern (matches the route requirement and
     * the AppOverride schema's `appId` pattern).
     */
    private const APP_ID_PATTERN = '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/';

    /**
     * Constructor.
     *
     * @param IRequest           $request            The current HTTP request.
     * @param LoggerInterface    $logger             PSR logger for diagnostics.
     * @param AppOverrideService $appOverrideService OR-backed override store.
     * @param IUserSession       $userSession        Current Nextcloud user session.
     * @param IAppManager        $appManager         Resolves OpenBuild-access for a user.
     * @param PermissionResolver $permissionResolver Per-Application principal matcher (maintainer gate).
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly LoggerInterface $logger,
        private readonly AppOverrideService $appOverrideService,
        private readonly IUserSession $userSession,
        private readonly IAppManager $appManager,
        private readonly PermissionResolver $permissionResolver,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the stored manifest delta for a fleet app, raw, for client-side merge.
     *
     * Returns the stored `manifestDelta` unchanged, or an empty delta `{}`
     * (status 200) when no override exists, so the loader's delta merge is a
     * no-op and the bundled manifest passes through. NEVER merges server-side
     * (OpenBuild lacks the fleet base — design D2). Login-required (the
     * capability/edit flow is for authenticated users); `#[NoCSRFRequired]` is
     * set because this is a read-only GET commonly fetched by the loader.
     *
     * @param string $appId The kebab-case fleet-app id from the URL.
     *
     * @return JSONResponse 200 with the stored delta or an empty object; 400 on a bad appId.
     *
     * @no-admin-idor-exempt Reads a per-instance SHARED, non-owned manifest delta
     *  (one hybrid app per fleet appId, shared by all users) served raw to fleet-app
     *  loaders for client-side merge — there is no per-object owner to scope against,
     *  so this is not an IDOR vector (design D1). Any authenticated user who can see
     *  the fleet app must be able to read its delta.
     *
     * @spec openspec/changes/unify-apps-with-app-type/specs/app-override-persistence/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function get(string $appId): JSONResponse
    {
        if ($this->isValidAppId(appId: $appId) === false) {
            return $this->error(code: 'invalid_app_id', status: Http::STATUS_BAD_REQUEST);
        }

        // `?scope=admin` returns the RAW shared admin delta (what the admin
        // editor diffs/edits); the default returns the LAYERED resolution
        // (admin ⊕ the calling user's own delta) for the loader to merge over
        // the bundled base (layered-versioned-app-deltas). For an anonymous
        // caller, or an app without per-user overrides, the layered result is
        // exactly the admin delta — fully back-compatible.
        $rawAdmin = ((string) $this->request->getParam('scope', '') === 'admin');

        try {
            if ($rawAdmin === true) {
                $record = $this->appOverrideService->findByAppId(appId: $appId);
            } else {
                $user = $this->userSession->getUser();
                $uid  = null;
                if ($user !== null) {
                    $uid = (string) $user->getUID();
                }

                $record = $this->appOverrideService->resolveLayeredDelta(appId: $appId, uid: $uid);
            }
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuild: AppOverride get failed for appId '.$appId.': '.$e->getMessage(),
                ['exception' => $e]
            );
            return $this->error(code: 'internal_error', status: Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($record === null) {
            // No override — return an empty delta so the client merge is a no-op.
            return new JSONResponse(data: (object) [], statusCode: Http::STATUS_OK);
        }

        $delta = ($record['manifestDelta'] ?? []);
        if (is_array($delta) === false) {
            $delta = [];
        }

        // Cast to object so an empty delta serialises as `{}` not `[]`.
        return new JSONResponse(data: (object) $delta, statusCode: Http::STATUS_OK);

    }//end get()

    /**
     * Return the calling user's OWN user-scoped delta for a fleet app (for editing).
     *
     * Login-required. Always owner-scoped to the session UID — a caller can
     * only ever read their own user delta (the UID is never a request param), so
     * this is not an IDOR vector. Returns `{ allowed, exists, manifestDelta,
     * versionUuid }` where `versionUuid` drives the Manifest detail page's OR
     * version history.
     *
     * @param string $appId The kebab-case fleet-app id from the URL.
     *
     * @return JSONResponse 200 with the caller's user-delta summary; 400/401 on rejection.
     *
     * @spec openspec/changes/layered-versioned-app-deltas/specs/application-delta-layers-ui/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getUser(string $appId): JSONResponse
    {
        if ($this->isValidAppId(appId: $appId) === false) {
            return $this->error(code: 'invalid_app_id', status: Http::STATUS_BAD_REQUEST);
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return $this->error(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $record = $this->appOverrideService->getUserDelta(appId: $appId, uid: (string) $user->getUID());
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuild: user-delta get failed for appId '.$appId.': '.$e->getMessage(),
                ['exception' => $e]
            );
            return $this->error(code: 'internal_error', status: Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(
            data: [
                'appId'         => $appId,
                'allowed'       => ($record['allowed'] ?? false),
                'exists'        => ($record['exists'] ?? false),
                'manifestDelta' => (object) ($record['manifestDelta'] ?? []),
                'versionUuid'   => ($record['versionUuid'] ?? null),
            ],
            statusCode: Http::STATUS_OK
        );

    }//end getUser()

    /**
     * Create or update the calling user's OWN user-scoped delta (the write endpoint).
     *
     * CSRF-enforced. Rejects anonymous (401) and out-of-scope (403) callers,
     * a malformed / app-blanking delta (422), and an app that does not permit
     * per-user overrides (403). The owner is always the session UID — a caller
     * can only ever write their own user delta (no-admin-idor).
     *
     * @param string $appId The kebab-case fleet-app id from the URL.
     *
     * @return JSONResponse 200 on success; 400/401/403/422 on rejection.
     *
     * @spec openspec/changes/layered-versioned-app-deltas/specs/application-delta-layers-ui/spec.md
     */
    #[NoAdminRequired]
    public function saveUser(string $appId): JSONResponse
    {
        if ($this->isValidAppId(appId: $appId) === false) {
            return $this->error(code: 'invalid_app_id', status: Http::STATUS_BAD_REQUEST);
        }

        $guard = $this->requireOpenBuildAccess();
        if ($guard !== null) {
            return $guard;
        }

        $uid = (string) $this->userSession->getUser()->getUID();

        $delta = $this->readDeltaBody();
        if ($delta === null) {
            return $this->error(
                code: 'invalid_delta',
                status: Http::STATUS_UNPROCESSABLE_ENTITY,
                detail: 'request body must be a keyed delta object'
            );
        }

        $rejection = $this->validateDelta(delta: $delta);
        if ($rejection !== null) {
            return $rejection;
        }

        try {
            $saved = $this->appOverrideService->upsertUserDelta(appId: $appId, delta: $delta, uid: $uid);
        } catch (\RuntimeException $e) {
            // Flag-disabled / no-app — a deliberate authorization refusal.
            return $this->error(
                code: 'user_overrides_not_allowed',
                status: Http::STATUS_FORBIDDEN,
                detail: $e->getMessage()
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuild: user-delta save failed for appId '.$appId.': '.$e->getMessage(),
                ['exception' => $e]
            );
            return $this->error(code: 'internal_error', status: Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(
            data: [
                'appId'       => $appId,
                'owner'       => ($saved['owner'] ?? $uid),
                'updatedAt'   => ($saved['updatedAt'] ?? null),
                'versionUuid' => ($saved['versionUuid'] ?? null),
            ],
            statusCode: Http::STATUS_OK
        );

    }//end saveUser()

    /**
     * Clear the calling user's OWN user-scoped delta (the reset endpoint).
     *
     * CSRF-enforced. Rejects anonymous (401) and out-of-scope (403). Idempotent —
     * a non-existent user delta is a success. Owner-scoped to the session UID.
     *
     * @param string $appId The kebab-case fleet-app id from the URL.
     *
     * @return JSONResponse 200 on success; 400/401/403 on rejection.
     *
     * @spec openspec/changes/layered-versioned-app-deltas/specs/application-delta-layers-ui/spec.md
     */
    #[NoAdminRequired]
    public function clearUser(string $appId): JSONResponse
    {
        if ($this->isValidAppId(appId: $appId) === false) {
            return $this->error(code: 'invalid_app_id', status: Http::STATUS_BAD_REQUEST);
        }

        $guard = $this->requireOpenBuildAccess();
        if ($guard !== null) {
            return $guard;
        }

        $uid = (string) $this->userSession->getUser()->getUID();

        try {
            $this->appOverrideService->deleteUserDelta(appId: $appId, uid: $uid);
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuild: user-delta clear failed for appId '.$appId.': '.$e->getMessage(),
                ['exception' => $e]
            );
            return $this->error(code: 'internal_error', status: Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(data: ['appId' => $appId, 'cleared' => true], statusCode: Http::STATUS_OK);

    }//end clearUser()

    /**
     * List ALL users' overrides for a fleet app (the maintainer view).
     *
     * Unlike {@see getUser()} (which is owner-scoped to the caller's own delta),
     * this returns every user-scoped delta for the app so a maintainer can see
     * who has a personal override from the OpenBuild management screen. Gated to
     * an OWNER/EDITOR of the parent Application or an NC admin — a regular user
     * may NOT enumerate other users' deltas (no-admin-idor). Returns owner +
     * version metadata only, never the private delta bodies.
     *
     * @param string $appId The kebab-case fleet-app id from the URL.
     *
     * @return JSONResponse 200 with `{ appId, total, overrides: [...] }`; 400/401/403 on rejection.
     *
     * @spec openspec/changes/layered-versioned-app-deltas/specs/application-delta-layers-ui/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listUserOverrides(string $appId): JSONResponse
    {
        if ($this->isValidAppId(appId: $appId) === false) {
            return $this->error(code: 'invalid_app_id', status: Http::STATUS_BAD_REQUEST);
        }

        $denied = $this->requireAppMaintainer(appId: $appId);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $overrides = $this->appOverrideService->listUserDeltas(appId: $appId);
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuild: user-delta list failed for appId '.$appId.': '.$e->getMessage(),
                ['exception' => $e]
            );
            return $this->error(code: 'internal_error', status: Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(
            data: [
                'appId'     => $appId,
                'total'     => count($overrides),
                'overrides' => $overrides,
            ],
            statusCode: Http::STATUS_OK
        );

    }//end listUserOverrides()

    /**
     * Guard: the caller must be an owner/editor of the parent Application, or an
     * NC admin — the authorization for enumerating ALL users' overrides.
     *
     * Fail-closed: an anonymous caller (401), an unresolvable app, or a caller
     * who is neither a maintainer nor an admin (403) is denied. An app with no
     * `permissions` block grants only the audited NC-admin escape hatch (same
     * posture as ApplicationVersionOwnerGuard).
     *
     * @param string $appId The fleet app id.
     *
     * @return JSONResponse|null Null on allow; 401/403 JSONResponse on deny.
     *
     * @spec openspec/changes/layered-versioned-app-deltas/specs/application-delta-layers-ui/spec.md
     */
    private function requireAppMaintainer(string $appId): ?JSONResponse
    {
        $caller = $this->userSession->getUser();
        if ($caller === null) {
            return $this->error(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED);
        }

        $application = $this->appOverrideService->findHybridApplication(appId: $appId);
        $permissions = ($application['permissions'] ?? []);

        // With a permissions block: owners/editors (or an admin) may view. Without
        // one: only the audited NC-admin escape hatch.
        if (is_array($permissions) === true && $permissions !== []) {
            $userGroups = $this->permissionResolver->resolveUserGroups(user: $caller);
            $allowed    = $this->permissionResolver->matchesCaller(
                permissions: $permissions,
                caller: $caller,
                userGroups: $userGroups,
                allowAdminBypass: true,
                roles: ['owners', 'editors']
            );
            if ($allowed === true) {
                return null;
            }
        } else if ($this->permissionResolver->isAdmin(caller: $caller) === true) {
            return null;
        }

        return $this->error(
            code: 'forbidden',
            status: Http::STATUS_FORBIDDEN,
            detail: 'only an owner/editor of this app (or an administrator) may view all user overrides'
        );

    }//end requireAppMaintainer()

    /**
     * Upsert the manifest delta for a fleet app (the write endpoint).
     *
     * CSRF is enforced (no `#[NoCSRFRequired]`). Rejects anonymous callers
     * (401) and callers without OpenBuild access (403). Validates the delta
     * shape and rejects an app-blanking delta (422) before persisting. Records
     * `updatedBy` from the session UID.
     *
     * @param string $appId The kebab-case fleet-app id from the URL.
     *
     * @return JSONResponse 200 on success; 400/401/403/422 on rejection.
     *
     * @spec openspec/changes/unify-apps-with-app-type/specs/app-override-persistence/spec.md
     */
    #[NoAdminRequired]
    public function save(string $appId): JSONResponse
    {
        if ($this->isValidAppId(appId: $appId) === false) {
            return $this->error(code: 'invalid_app_id', status: Http::STATUS_BAD_REQUEST);
        }

        $guard = $this->requireOpenBuildAccess();
        if ($guard !== null) {
            return $guard;
        }

        // The session user is guaranteed non-null here (the guard rejected
        // anonymous callers above).
        $uid = (string) $this->userSession->getUser()->getUID();

        $delta = $this->readDeltaBody();
        if ($delta === null) {
            return $this->error(
                code: 'invalid_delta',
                status: Http::STATUS_UNPROCESSABLE_ENTITY,
                detail: 'request body must be a keyed delta object'
            );
        }

        $rejection = $this->validateDelta(delta: $delta);
        if ($rejection !== null) {
            return $rejection;
        }

        try {
            $saved = $this->appOverrideService->upsert(
                appId: $appId,
                delta: $delta,
                baseRef: $this->readBaseRef(),
                updatedBy: $uid
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuild: AppOverride save failed for appId '.$appId.': '.$e->getMessage(),
                ['exception' => $e]
            );
            return $this->error(code: 'internal_error', status: Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(
            data: [
                'appId'           => $appId,
                'updatedBy'       => ($saved['updatedBy'] ?? $uid),
                'updatedAt'       => ($saved['updatedAt'] ?? null),
                'applicationUuid' => ($saved['applicationUuid'] ?? null),
            ],
            statusCode: Http::STATUS_OK
        );

    }//end save()

    /**
     * Run the delta-shape + non-blank guards (design D4).
     *
     * Returns null when the delta passes both guards, or a 422 JSONResponse
     * (malformed shape or app-blanking) when it does not.
     *
     * @param array<string, mixed> $delta The candidate delta body.
     *
     * @return JSONResponse|null Null on pass; 422 JSONResponse on rejection.
     *
     * @spec openspec/changes/unify-apps-with-app-type/specs/app-override-persistence/spec.md
     */
    private function validateDelta(array $delta): ?JSONResponse
    {
        $violations = $this->appOverrideService->validateDeltaShape(delta: $delta);
        if ($violations !== []) {
            return new JSONResponse(
                data: ['error' => 'invalid_delta', 'violations' => $violations],
                statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        // An empty delta is the valid "no customization yet" state — it leaves
        // the fleet app's bundled manifest untouched (the merge is a no-op) and
        // is how the wizard creates a fresh hybrid app shell. Only a non-empty
        // delta can app-blank, so skip the blank guard for an empty delta.
        if ($delta === []) {
            return null;
        }

        if ($this->appOverrideService->wouldBlankApp(delta: $delta) === true) {
            return $this->error(
                code: 'app_blanking_delta',
                status: Http::STATUS_UNPROCESSABLE_ENTITY,
                detail: 'delta resolves to a manifest with no renderable pages or menu'
            );
        }

        return null;

    }//end validateDelta()

    /**
     * Read the optional `baseRef` provenance note from the request, JSON-encoded.
     *
     * Returns a JSON string (whether the client sent a JSON string or a decoded
     * object), or null when absent. The value is non-load-bearing (design D5).
     *
     * @return string|null The JSON-encoded baseRef, or null when absent.
     */
    private function readBaseRef(): ?string
    {
        $raw = $this->request->getParam('baseRef');
        if (is_string($raw) === true && $raw !== '') {
            return $raw;
        }

        if (is_array($raw) === true) {
            $encoded = json_encode($raw);
            if (is_string($encoded) === true) {
                return $encoded;
            }
        }

        return null;

    }//end readBaseRef()

    /**
     * Clear the manifest override for a fleet app (the reset endpoint).
     *
     * CSRF is enforced (no `#[NoCSRFRequired]`). Rejects anonymous (401) and
     * out-of-scope (403). Idempotent — a non-existent override is a success.
     *
     * @param string $appId The kebab-case fleet-app id from the URL.
     *
     * @return JSONResponse 200 on success; 400/401/403 on rejection.
     *
     * @spec openspec/changes/unify-apps-with-app-type/specs/app-override-persistence/spec.md
     */
    #[NoAdminRequired]
    public function clear(string $appId): JSONResponse
    {
        if ($this->isValidAppId(appId: $appId) === false) {
            return $this->error(code: 'invalid_app_id', status: Http::STATUS_BAD_REQUEST);
        }

        $guard = $this->requireOpenBuildAccess();
        if ($guard !== null) {
            return $guard;
        }

        try {
            $this->appOverrideService->delete(appId: $appId);
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuild: AppOverride clear failed for appId '.$appId.': '.$e->getMessage(),
                ['exception' => $e]
            );
            return $this->error(code: 'internal_error', status: Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(data: ['appId' => $appId, 'cleared' => true], statusCode: Http::STATUS_OK);

    }//end clear()

    /**
     * Guard: the caller must be logged in AND have OpenBuild access.
     *
     * Returns null when the caller passes, or a JSONResponse (401 anonymous /
     * 403 out-of-scope) when they do not. OpenBuild access is "the OpenBuild
     * app is enabled for this user under its NC app group-restriction"
     * (IAppManager::isEnabledForUser), the same condition that makes the
     * in-app edit button reachable (design D1/D6). This is the load-bearing
     * write boundary — the `canEdit` capability is only a UI hint.
     *
     * @return JSONResponse|null Null on allow; 401/403 JSONResponse on deny.
     *
     * @spec openspec/changes/unify-apps-with-app-type/specs/app-override-persistence/spec.md
     */
    private function requireOpenBuildAccess(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return $this->error(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED);
        }

        if ($this->appManager->isEnabledForUser(Application::APP_ID, $user) === false) {
            return $this->error(code: 'forbidden', status: Http::STATUS_FORBIDDEN);
        }

        return null;

    }//end requireOpenBuildAccess()

    /**
     * Read and validate the PUT body as a keyed delta object.
     *
     * Returns the delta array, or null when the body is not a plain object
     * (e.g. a JSON list, a scalar, or `null`). The framework merges JSON body
     * params into the request param bag; the `appId` route param is excluded so
     * it does not leak into the delta.
     *
     * @return array<string, mixed>|null The delta object, or null when the body is not a keyed delta.
     */
    private function readDeltaBody(): ?array
    {
        $params = $this->request->getParams();

        // Strip routing / framework keys so they do not pollute the delta.
        unset($params['appId'], $params['baseRef'], $params['_route']);

        // A keyed delta is an associative object (or empty). A list-shaped body
        // (a whole-manifest POST mistake) is rejected.
        if ($params !== [] && array_is_list($params) === true) {
            return null;
        }

        return $params;

    }//end readDeltaBody()

    /**
     * Validate an appId against the kebab-case NC-app-id pattern.
     *
     * @param string $appId The candidate app id.
     *
     * @return bool True when the appId is a valid kebab-case NC app id.
     */
    private function isValidAppId(string $appId): bool
    {
        return preg_match(self::APP_ID_PATTERN, $appId) === 1;

    }//end isValidAppId()

    /**
     * Build a uniform error JSONResponse.
     *
     * @param string      $code   The error code.
     * @param int         $status The HTTP status code.
     * @param string|null $detail Optional detail message.
     *
     * @return JSONResponse
     */
    private function error(string $code, int $status, ?string $detail=null): JSONResponse
    {
        $body = ['error' => $code];
        if ($detail !== null) {
            $body['detail'] = $detail;
        }

        return new JSONResponse(data: $body, statusCode: $status);

    }//end error()
}//end class
