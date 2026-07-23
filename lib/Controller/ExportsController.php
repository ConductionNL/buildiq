<?php

/**
 * OpenBuild Exports Controller
 *
 * Thin controller: queues an ExportJob and streams the resulting ZIP.
 * Standard CRUD on ExportJob (list/get for polling) goes through OR REST
 * per ADR-022 — this controller deliberately omits those.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-33
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-34
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-35
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-37
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-47
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Controller;

use OCA\OpenBuild\AppInfo\Application;
use OCA\OpenBuild\Service\ExportJobService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for the OpenBuild export pipeline.
 *
 * @spec openspec/changes/openbuild-exporter/tasks.md#task-7.2
 * @spec openspec/changes/openbuild-exporter/tasks.md#task-5.2
 */
class ExportsController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request          Request.
     * @param ExportJobService   $exportJobService Job-orchestration service.
     * @param IUserSession       $userSession      Current user session.
     * @param ContainerInterface $container        Container for optional OR services.
     * @param LoggerInterface    $logger           Logger.
     * @param IGroupManager      $groupManager     Group membership resolver (RBAC, issue #158).
     */
    public function __construct(
        IRequest $request,
        private ExportJobService $exportJobService,
        private IUserSession $userSession,
        private ContainerInterface $container,
        private LoggerInterface $logger,
        private IGroupManager $groupManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Authorize the caller for an action on a given source Application slug.
     *
     * IDOR / ADR-005 Rule 3 guard: `#[NoAdminRequired]` makes the route
     * reachable to any authenticated user; we MUST then prove the caller
     * holds at least viewer permission on the specific Application before
     * acting on it (otherwise any authed user can export anyone's app by
     * guessing its slug — issue #158).
     *
     * Checks (in order):
     *   1. Caller is authenticated.
     *   2. Application exists in OR.
     *   3. Caller's UID appears in owners|editors|viewers, OR caller is an
     *      NC admin (same bypass policy as ApplicationsController).
     *
     * @param string $applicationSlug Slug of the source Application.
     *
     * @return bool True when the caller is allowed.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-47
     */
    private function isAuthorisedForApplication(string $applicationSlug): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        $uid = $user->getUID();

        // NC admin bypass FIRST — same policy as ApplicationsController
        // (REQ-OBRBAC-006). An NC admin may export any application regardless
        // of whether the OR slug lookup resolves: wizard-built apps may not be
        // indexed by searchObjectsBySlug(), and returning false on an empty
        // lookup before the bypass wrongly 403s the owning admin.
        if ($this->groupManager->isInGroup($uid, 'admin') === true) {
            return true;
        }

        try {
            if ($this->container->has('OCA\\OpenRegister\\Service\\ObjectService') === false) {
                return false;
            }

            $service = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
            if (method_exists($service, 'searchObjectsBySlug') === false) {
                return false;
            }

            $apps = $service->searchObjectsBySlug('openbuild', 'application', ['slug' => $applicationSlug]);
            if (is_array($apps) === false || $apps === []) {
                return false;
            }

            // OpenRegister hands back ObjectEntity objects here. This used to read the
            // permissions only `if (is_array($app))` — which is never true — so every
            // non-admin fell through to an empty bucket list and was denied. The admin
            // bypass above masked it.
            $app         = $this->toArray(object: $apps[0]);
            $permissions = ($app['permissions'] ?? []);

            if (is_array($permissions) === false) {
                $permissions = [];
            }

            // Check all three role buckets: owners, editors, viewers.
            foreach (['owners', 'editors', 'viewers'] as $role) {
                $bucket = ($permissions[$role] ?? []);
                if (is_array($bucket) === false) {
                    continue;
                }

                foreach ($bucket as $principal) {
                    if (is_string($principal) === false || $principal === '') {
                        continue;
                    }

                    if (str_starts_with($principal, 'user:') === true) {
                        if (substr($principal, 5) === $uid) {
                            return true;
                        }

                        continue;
                    }

                    // Back-compat / group: prefix.
                    if (str_starts_with($principal, 'group:') === true) {
                        $gid = substr($principal, 6);
                    } else {
                        $gid = $principal;
                    }

                    if ($gid !== '' && $this->groupManager->isInGroup($uid, $gid) === true) {
                        return true;
                    }
                }//end foreach
            }//end foreach

            // NC admin already short-circuited to true above; a non-admin who
            // is not in any role bucket is not authorised.
            return false;
        } catch (\Throwable $e) {
            $this->logger->debug('OpenBuild export: authz lookup failed: '.$e->getMessage());
            return false;
        }//end try
    }//end isAuthorisedForApplication()

    /**
     * Authorize the caller for an ExportJob UUID.
     *
     * Looks up the ExportJob record and verifies the persisted `requestedBy`
     * field (fallback `@self.owner`) matches the calling user's UID, or the
     * caller is an NC admin. Existence alone is not sufficient authorisation
     * (IDOR, issue #158).
     *
     * @param string $jobUuid ExportJob UUID.
     *
     * @return bool True when the caller is allowed.
     */
    private function isAuthorisedForJob(string $jobUuid): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        $uid = $user->getUID();

        // NC admin bypass.
        if ($this->groupManager->isInGroup($uid, 'admin') === true) {
            return true;
        }

        try {
            if ($this->container->has('OCA\\OpenRegister\\Service\\ObjectService') === false) {
                return false;
            }

            $service = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
            if (method_exists($service, 'find') === false) {
                return false;
            }

            $found = $service->find($jobUuid);
            if ($found === null) {
                return false;
            }

            // Verify the job was requested by this user.
            if (is_array($found) === true) {
                $job = $found;
            } else if (method_exists($found, 'jsonSerialize') === true) {
                $job = (array) $found->jsonSerialize();
            } else {
                $job = (array) $found;
            }

            // Read the requester identity actually persisted on the record
            // (`requestedBy`, set by ExportJobService::queue), falling back to the
            // OR owner. The previous `submittedBy` key was never written, so the
            // check silently always fell through to `@self.owner`
            // (harden-rules-authz-and-audit-parity, L8).
            $requestedBy = (string) ($job['requestedBy'] ?? ($job['@self']['owner'] ?? ''));
            return $requestedBy !== '' && $requestedBy === $uid;
        } catch (\Throwable $e) {
            $this->logger->debug('OpenBuild export: job authz lookup failed: '.$e->getMessage());
            return false;
        }//end try
    }//end isAuthorisedForJob()

    /**
     * Resolve an Application's UUID from its slug.
     *
     * The ExportJob record needs the UUID, and the client does not send one. Taking it
     * from the same slug lookup the authorisation check uses means a queued job can
     * never name an application other than the one the caller was cleared for.
     *
     * @param string $applicationSlug Application slug.
     *
     * @return string The UUID, or '' when the slug does not resolve.
     *
     * @spec openspec/changes/export-github-broker/tasks.md#task-9-fix-the-unpersistable-export-job
     */
    private function resolveApplicationUuid(string $applicationSlug): string
    {
        try {
            if ($this->container->has('OCA\\OpenRegister\\Service\\ObjectService') === false) {
                return '';
            }

            $service = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
            if (method_exists($service, 'searchObjectsBySlug') === false) {
                return '';
            }

            $apps = $service->searchObjectsBySlug('openbuild', 'application', ['slug' => $applicationSlug]);
            if (is_array($apps) === false || $apps === []) {
                return '';
            }

            $app = $this->toArray(object: $apps[0]);

            // OR serialises the object id both at the top level and under `@self`.
            $uuid = ($app['id'] ?? ($app['@self']['id'] ?? ''));
            if (is_string($uuid) === false) {
                return '';
            }

            return $uuid;
        } catch (\Throwable $e) {
            $this->logger->warning('OpenBuild export: could not resolve application UUID: '.$e->getMessage());
            return '';
        }//end try
    }//end resolveApplicationUuid()

    /**
     * Normalise an OpenRegister search hit to a plain array.
     *
     * `searchObjectsBySlug()` returns ObjectEntity OBJECTS, not arrays. Both call sites
     * here used to guard with `is_array($app)` and fall through on failure — which meant
     * they never actually read the object. In `isAuthorisedForApplication()` that
     * silently produced an empty permissions set, so every non-admin was denied; only
     * the admin bypass above it kept the endpoint working at all.
     *
     * @param mixed $object An ObjectEntity, or an already-plain array.
     *
     * @return array<string,mixed> The serialised object, or [] when it is neither.
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialised = $object->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        return [];
    }//end toArray()

    /**
     * Validate the submit() request body.
     *
     * @param array<string,mixed> $body Decoded body params.
     *
     * @return JSONResponse|null JSONResponse on validation error, null on success.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-37
     */
    private function validateSubmitBody(array $body): ?JSONResponse
    {
        $target = $this->readStringField(body: $body, field: 'target', default: 'zip');
        if (in_array($target, ['zip', 'github'], true) === false) {
            return new JSONResponse(
                ['error' => 'Invalid target: must be zip or github.'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $applicationVersion = $this->readStringField(body: $body, field: 'applicationVersion', default: '');
        if ($applicationVersion === '') {
            return new JSONResponse(
                ['error' => 'applicationVersion is required.'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        if ($target === 'github') {
            return $this->validateGithubFields(body: $body);
        }

        return null;
    }//end validateSubmitBody()

    /**
     * Validate the GitHub-specific required fields.
     *
     * @param array<string,mixed> $body Decoded body params.
     *
     * @return JSONResponse|null
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-34
     */
    private function validateGithubFields(array $body): ?JSONResponse
    {
        $org  = $this->readStringField(body: $body, field: 'githubOrg', default: '');
        $repo = $this->readStringField(body: $body, field: 'githubRepo', default: '');
        if ($org === '' || $repo === '') {
            return new JSONResponse(
                ['error' => 'githubOrg and githubRepo are required for target=github.'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        return null;
    }//end validateGithubFields()

    /**
     * Pull a string field from the request body with a default.
     *
     * @param array<string,mixed> $body    Body.
     * @param string              $field   Field name.
     * @param string              $default Default when missing/non-string.
     *
     * @return string
     */
    private function readStringField(array $body, string $field, string $default): string
    {
        if (is_string($body[$field] ?? null) === true) {
            return (string) $body[$field];
        }

        return $default;
    }//end readStringField()

    /**
     * Queue an export of an Application version.
     *
     * @param string $slug Application slug.
     *
     * @return JSONResponse 202 Accepted with `{ uuid }` on success.
     *
     * State-changing POST: `#[NoCSRFRequired]` is DELIBERATELY NOT applied here.
     * The SPA posts via `@nextcloud/axios`, which attaches the `requesttoken`
     * header automatically, so the CSRF check costs the legitimate caller nothing
     * while blocking a forged cross-site POST that could queue an export on behalf
     * of an authenticated user. The body no longer carries a PAT (it names a broker
     * credential instead), but it can still push a repository into existence under
     * the user's GitHub identity, so it stays CSRF-protected.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-33
     * @spec openspec/changes/openbuild-export-csrf-hardening/tasks.md#task-11
     */
    #[NoAdminRequired]
    public function submit(string $slug): JSONResponse
    {
        // ADR-005 Rule 3 guard: per-object authorization on a #[NoAdminRequired]
        // endpoint. Without this any authed user could POST to any slug.
        if ($this->isAuthorisedForApplication(applicationSlug: $slug) === false) {
            return new JSONResponse(
                ['error' => 'Forbidden.'],
                Http::STATUS_FORBIDDEN
            );
        }

        $body            = $this->request->getParams();
        $validationError = $this->validateSubmitBody(body: $body);
        if ($validationError !== null) {
            return $validationError;
        }

        // No secret is accepted here any more. A rejected legacy client may still
        // send `githubPat`; drop it defensively so it can never reach a log or the
        // job record. The push path authenticates via `githubCredentialId` (a broker
        // credential UUID) carried in the body and validated by the broker.
        unset($body['githubPat']);

        // Resolve applicationUuid from the slug SERVER-SIDE.
        //
        // Pre-existing bug: ExportDialog.vue never sent `applicationUuid`, but
        // ExportJobService::queue() reads it straight off the payload — so it persisted
        // as '', OR rejected the record ("Property 'applicationUuid' should match format
        // 'uuid'"), and queue() logged the failure and returned a job UUID anyway. The
        // caller got a cheerful 202 for an export that had not been recorded and would
        // never run.
        //
        // Resolving it here rather than adding the field to the dialog is also the safer
        // shape: the slug is already the authorisation subject just checked above, so
        // taking the UUID from the same lookup means the record cannot name a different
        // application than the one the caller was authorised for.
        $applicationUuid = $this->resolveApplicationUuid(applicationSlug: $slug);
        if ($applicationUuid === '') {
            return new JSONResponse(
                ['error' => 'Unknown application: '.$slug],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $body['applicationUuid'] = $applicationUuid;

        // The queueing user's UID travels to the session-less background job so the
        // broker's ownership guard has an identity to check the credential against.
        $requestedBy = null;
        $user        = $this->userSession->getUser();
        if ($user !== null) {
            $requestedBy = $user->getUID();
        }

        try {
            $jobUuid = $this->exportJobService->queue(
                applicationSlug: $slug,
                payload: $body,
                requestedBy: $requestedBy
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        } catch (\Throwable $e) {
            $this->logger->error('OpenBuild export submit failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Internal error queueing export.'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return new JSONResponse(
            ['uuid' => $jobUuid],
            Http::STATUS_ACCEPTED
        );
    }//end submit()

    /**
     * Stream the ZIP for a completed ExportJob.
     *
     * @param string $uuid ExportJob UUID.
     *
     * @return Response 200 with the ZIP body, 410 Gone after expiry, 404 unknown.
     *
     * `#[NoCSRFRequired]` is INTENTIONAL here: this is a plain `<a href>`
     * navigation download (idempotent GET, no state change), gated by
     * `isAuthorisedForJob` with 404-masking of unauthorised job UUIDs. Do
     * NOT remove this attribute in a future security sweep — a GET-only
     * download link cannot carry the `requesttoken` header a CSRF check
     * requires, and removing it would break the download link entirely.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-35
     * @spec openspec/changes/openbuild-export-csrf-hardening/tasks.md#task-13
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function download(string $uuid): Response
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->isAuthorisedForJob(jobUuid: $uuid) === false) {
            // Mask non-authorised as 404 to avoid revealing job UUIDs to
            // unauthorised callers (defence in depth on the IDOR vector).
            return new JSONResponse(['error' => 'Unknown export job.'], Http::STATUS_NOT_FOUND);
        }

        $resolved = $this->exportJobService->resolveDownload($uuid);
        if ($resolved === null) {
            return new JSONResponse(['error' => 'Unknown export job.'], Http::STATUS_NOT_FOUND);
        }

        if ($resolved['expired'] === true) {
            return new JSONResponse(['error' => 'Export has expired.'], Http::STATUS_GONE);
        }

        $body = file_get_contents($resolved['path']);
        if ($body === false) {
            return new JSONResponse(['error' => 'Unable to read export.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new DataDownloadResponse($body, basename($resolved['path']), 'application/zip');
    }//end download()
}//end class
