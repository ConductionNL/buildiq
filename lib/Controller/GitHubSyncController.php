<?php

/**
 * OpenBuild GitHubSyncController
 *
 * Owner-gated HTTP surface for the GitHub owner round-trip (github-app-sync).
 * Four endpoints, all `#[NoAdminRequired]` with a per-object owner/viewer guard in
 * the method body (no-admin-idor: each loads the Application by `{slug}` — 404 when
 * absent — and requires the caller be an OWNER of that Application for the write
 * operations; a Nextcloud admin NOT listed in `permissions.owners` also gets 403,
 * matching the release endpoint — admin power does not auto-grant a GitHub write):
 *   - POST /api/applications/{slug}/github/link   — store the repo linkage.
 *   - POST /api/applications/{slug}/github/push   — serialize + broker-routed push.
 *   - POST /api/applications/{slug}/github/pull   — fetch + parse → new draft version.
 *   - GET  /api/applications/{slug}/github/status — viewer-readable feature/status.
 *
 * Every GitHub write is broker-routed by the service (the token never enters
 * OpenBuild); a broker denial / moved head / parse failure surfaces as a generic,
 * hint-bearing outcome.
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
 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Controller;

use OCA\OpenBuild\AppInfo\Application;
use OCA\OpenBuild\Exception\AppRepoParseException;
use OCA\OpenBuild\Service\GitHubAppSyncService;
use OCA\OpenBuild\Service\PermissionResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Owner-gated controller for the GitHub link/push/pull/status endpoints.
 *
 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
 */
class GitHubSyncController extends Controller
{
    /**
     * Safe GitHub owner/repo/org pattern.
     */
    private const OWNER_REPO_PATTERN = '/^[A-Za-z0-9._-]{1,100}$/';

    /**
     * Safe git-ref pattern.
     */
    private const REF_PATTERN = '/^[A-Za-z0-9._\/-]{1,255}$/';

    /**
     * Safe credential-id pattern (UUID-ish; no path/query metacharacters).
     */
    private const CREDENTIAL_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

    /**
     * Constructor.
     *
     * @param IRequest             $request            The current HTTP request.
     * @param IUserSession         $userSession        Current NC user session.
     * @param GitHubAppSyncService $syncService        The link/push/pull service.
     * @param PermissionResolver   $permissionResolver Shared RBAC grammar resolver.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly GitHubAppSyncService $syncService,
        private readonly PermissionResolver $permissionResolver,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Link the app to a GitHub repository (owner-only).
     *
     * @param string $slug The Application slug.
     *
     * @return JSONResponse 200 with the stored linkage; 400/401/403/404 on failure.
     *
     * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
     */
    #[NoAdminRequired]
    public function link(string $slug): JSONResponse
    {
        $gate = $this->requireOwner(slug: $slug);
        if ($gate instanceof JSONResponse) {
            return $gate;
        }

        $owner = (string) ($this->request->getParam('owner') ?? '');
        $name  = (string) ($this->request->getParam('name') ?? '');
        $org   = (string) ($this->request->getParam('org') ?? '');
        if (preg_match(self::OWNER_REPO_PATTERN, $owner) !== 1 || preg_match(self::OWNER_REPO_PATTERN, $name) !== 1) {
            return $this->error(code: 'invalid_repo', status: Http::STATUS_BAD_REQUEST);
        }

        if ($org !== '' && preg_match(self::OWNER_REPO_PATTERN, $org) !== 1) {
            return $this->error(code: 'invalid_org', status: Http::STATUS_BAD_REQUEST);
        }

        $linkage = $this->syncService->link(
            application: $gate,
            owner: $owner,
            name: $name,
            credentialId: $this->credentialParam(),
            actingUserId: $this->uid()
        );

        return new JSONResponse(data: $linkage, statusCode: Http::STATUS_OK);
    }//end link()

    /**
     * Publish (push) the chosen version to GitHub through the broker (owner-only).
     *
     * @param string $slug The Application slug.
     *
     * @return JSONResponse 200 with the commit sha; 401/403/404/422 or a generic outcome on failure.
     *
     * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
     */
    #[NoAdminRequired]
    public function push(string $slug): JSONResponse
    {
        $gate = $this->requireOwner(slug: $slug);
        if ($gate instanceof JSONResponse) {
            return $gate;
        }

        $credentialId = $this->credentialParam();
        if ($credentialId === null || preg_match(self::CREDENTIAL_PATTERN, $credentialId) !== 1) {
            return $this->error(code: 'invalid_credential', status: Http::STATUS_BAD_REQUEST);
        }

        $versionSlugRaw = $this->request->getParam('versionSlug');
        $versionSlug    = null;
        if (is_string($versionSlugRaw) === true && $versionSlugRaw !== '') {
            $versionSlug = $versionSlugRaw;
        }

        $repoOverride = $this->repoOverrideParam();

        $result = $this->syncService->push(
            application: $gate,
            versionSlug: $versionSlug,
            credentialId: $credentialId,
            repoOverride: $repoOverride,
            actingUserId: $this->uid(),
            visibility: $this->visibilityParam()
        );

        return $this->outcomeResponse(result: $result, okStatus: Http::STATUS_OK);
    }//end push()

    /**
     * Pull a ref into a new draft version (owner-only, never overwrites production).
     *
     * @param string $slug The Application slug.
     *
     * @return JSONResponse 200 with the draft version; 401/403/404/422 or a generic outcome on failure.
     *
     * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
     */
    #[NoAdminRequired]
    public function pull(string $slug): JSONResponse
    {
        $gate = $this->requireOwner(slug: $slug);
        if ($gate instanceof JSONResponse) {
            return $gate;
        }

        $ref = (string) ($this->request->getParam('ref') ?? '');
        if (preg_match(self::REF_PATTERN, $ref) !== 1) {
            return $this->error(code: 'invalid_ref', status: Http::STATUS_BAD_REQUEST);
        }

        $credentialId = $this->credentialParam();
        if ($credentialId !== null && preg_match(self::CREDENTIAL_PATTERN, $credentialId) !== 1) {
            return $this->error(code: 'invalid_credential', status: Http::STATUS_BAD_REQUEST);
        }

        try {
            $result = $this->syncService->pull(
                application: $gate,
                ref: $ref,
                credentialId: $credentialId,
                actingUserId: $this->uid()
            );
        } catch (AppRepoParseException $e) {
            return new JSONResponse(data: $e->toArray(), statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        return $this->outcomeResponse(result: $result, okStatus: Http::STATUS_OK);
    }//end pull()

    /**
     * Report linkage, provenance, and feature-detection flags (viewer-readable).
     *
     * @param string $slug The Application slug.
     *
     * @return JSONResponse 200 with the status; 401/403/404 on failure.
     *
     * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
     */
    #[NoAdminRequired]
    public function status(string $slug): JSONResponse
    {
        $application = $this->requireRole(slug: $slug, roles: ['owners', 'editors', 'viewers']);
        if ($application instanceof JSONResponse) {
            return $application;
        }

        $brokerAvailable = $this->syncService->isBrokerAvailable();

        return new JSONResponse(
            data: [
                'githubRepo'                => ($application['githubRepo'] ?? null),
                'githubDefaultBranch'       => ($application['githubDefaultBranch'] ?? null),
                'lastPushedSha'             => $this->lastPushedSha(application: $application),
                'lastPulledSha'             => null,
                'brokerCredentialAvailable' => $brokerAvailable,
                'publishAvailable'          => $brokerAvailable,
            ],
            statusCode: Http::STATUS_OK
        );
    }//end status()

    /**
     * Owner-gate helper: load the app (404) then require the OWNER role (403).
     *
     * @param string $slug The Application slug.
     *
     * @return array<string,mixed>|JSONResponse The Application object, or an error response.
     */
    private function requireOwner(string $slug): array|JSONResponse
    {
        return $this->requireRole(slug: $slug, roles: ['owners']);
    }//end requireOwner()

    /**
     * Load the app (404) and require the caller hold any of the given roles (403).
     * Admin power does NOT auto-grant (allowAdminBypass=false), matching REQ-OBV-110.
     *
     * @param string            $slug  The Application slug.
     * @param array<int,string> $roles The role buckets that authorise the call.
     *
     * @return array<string,mixed>|JSONResponse The Application object, or an error response.
     */
    private function requireRole(string $slug, array $roles): array|JSONResponse
    {
        $caller = $this->userSession->getUser();
        if ($caller === null) {
            return $this->error(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED);
        }

        $application = $this->syncService->loadApplicationBySlug(slug: $slug);
        if ($application === null) {
            return $this->error(code: 'application_not_found', status: Http::STATUS_NOT_FOUND);
        }

        $permissions = ($application['permissions'] ?? []);
        if (is_array($permissions) === false) {
            $permissions = [];
        }

        $allowed = $this->permissionResolver->matchesCaller(
            permissions: $permissions,
            caller: $caller,
            userGroups: $this->permissionResolver->resolveUserGroups(user: $caller),
            allowAdminBypass: false,
            roles: $roles
        );
        if ($allowed === false) {
            return $this->error(code: 'forbidden', status: Http::STATUS_FORBIDDEN);
        }

        return $application;
    }//end requireRole()

    /**
     * Map a service outcome array to a JSONResponse (ok → okStatus, else 4xx).
     *
     * @param array<string,mixed> $result   The service result carrying `outcome`.
     * @param int                 $okStatus The status for a successful outcome.
     *
     * @return JSONResponse
     */
    private function outcomeResponse(array $result, int $okStatus): JSONResponse
    {
        $outcome = (string) ($result['outcome'] ?? GitHubAppSyncService::OUTCOME_UNREACHABLE);
        if ($outcome === GitHubAppSyncService::OUTCOME_OK) {
            return new JSONResponse(data: $result, statusCode: $okStatus);
        }

        $status = match ($outcome) {
            GitHubAppSyncService::OUTCOME_NOT_LINKED, 'version_not_found' => Http::STATUS_BAD_REQUEST,
            GitHubAppSyncService::OUTCOME_PUSH_CONFLICT => Http::STATUS_CONFLICT,
            GitHubAppSyncService::OUTCOME_BROKER_UNAVAILABLE, GitHubAppSyncService::OUTCOME_BROKER_DENIED => Http::STATUS_FORBIDDEN,
            // GitHub refused on permissions grounds — a 403 answer, not a 502
            // transport failure. Reporting it as a gateway error is what sends
            // the reader at the network instead of at the token's scopes.
            GitHubAppSyncService::OUTCOME_FORBIDDEN => Http::STATUS_FORBIDDEN,
            default => Http::STATUS_BAD_GATEWAY,
        };

        return new JSONResponse(data: ['error' => $outcome], statusCode: $status);
    }//end outcomeResponse()

    /**
     * Optional `{ repo: { owner, name, org? } }` push override, pattern-validated.
     *
     * @return array{owner:string,name:string,org:string}|null
     */
    private function repoOverrideParam(): ?array
    {
        $repo = $this->request->getParam('repo');
        if (is_array($repo) === false) {
            return null;
        }

        $owner = (string) ($repo['owner'] ?? '');
        $name  = (string) ($repo['name'] ?? '');
        $org   = (string) ($repo['org'] ?? '');
        if (preg_match(self::OWNER_REPO_PATTERN, $name) !== 1) {
            return null;
        }

        if ($owner !== '' && preg_match(self::OWNER_REPO_PATTERN, $owner) !== 1) {
            return null;
        }

        if ($org !== '' && preg_match(self::OWNER_REPO_PATTERN, $org) !== 1) {
            return null;
        }

        return ['owner' => $owner, 'name' => $name, 'org' => $org];
    }//end repoOverrideParam()

    /**
     * Read the optional `visibility` request param for a freshly created repo.
     * Defaults to 'public' (shop discoverability); only an explicit 'private'
     * flips it — any other/absent value falls back to 'public'.
     *
     * @return string 'public' or 'private'.
     */
    private function visibilityParam(): string
    {
        $visibility = $this->request->getParam('visibility');
        if (is_string($visibility) === true && strtolower($visibility) === 'private') {
            return 'private';
        }

        return 'public';
    }//end visibilityParam()

    /**
     * Read the optional `credentialId` request param.
     *
     * @return string|null
     */
    private function credentialParam(): ?string
    {
        $credentialId = $this->request->getParam('credentialId');
        if (is_string($credentialId) === true && $credentialId !== '') {
            return $credentialId;
        }

        return null;
    }//end credentialParam()

    /**
     * The acting session UID, or null.
     *
     * @return string|null
     */
    private function uid(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        return $user->getUID();
    }//end uid()

    /**
     * Best-effort last-pushed sha from the app's production version provenance.
     *
     * @param array<string,mixed> $application The Application object.
     *
     * @return string|null
     */
    private function lastPushedSha(array $application): ?string
    {
        $sha = ($application['lastPushedSha'] ?? null);
        if (is_string($sha) === false) {
            return null;
        }

        return $sha;
    }//end lastPushedSha()

    /**
     * Build a uniform error JSONResponse.
     *
     * @param string $code   The error code.
     * @param int    $status The HTTP status code.
     *
     * @return JSONResponse
     */
    private function error(string $code, int $status): JSONResponse
    {
        return new JSONResponse(data: ['error' => $code], statusCode: $status);
    }//end error()
}//end class
