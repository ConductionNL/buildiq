<?php

/**
 * OpenBuild Job Owner Impersonator
 *
 * Impersonates an OR object's OWNER on the current session for the
 * duration of a piece of work — the fleet-wide pattern for background jobs
 * that must drive an OR write requiring an acting-user context, mirroring
 * hermiq's ScheduleService::runAgentAsOwner().
 *
 * @category Service
 * @package  OCA\OpenBuild\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-38
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs a unit of work impersonating the OWNER of an OR object.
 *
 * Background jobs (Nextcloud `QueuedJob`/`TimedJob`) run with NO HTTP
 * session. When such a job needs to drive an OR write that is gated on the
 * acting user (e.g. OR's TransitionEngine + PermissionHandler RBAC check),
 * the caller has no session user to present and the write fails closed for
 * an anonymous caller — see {@see ExportJobService::transitionJob()} (#105)
 * for the concrete case this class was extracted to serve.
 *
 * `ObjectService::saveObject()` already stamps an OR object's `owner`
 * field with the creating user's UID at save-time
 * (`applyOwnerAttribution()`), and OR's `PermissionHandler` grants an
 * object's owner full access to their own object regardless of the
 * schema's group-based authorization rules. So resolving that owner and
 * impersonating them for the duration of the background job's write lets
 * the write succeed without relaxing the schema's authorization for any
 * other caller.
 *
 * Extracted into its own class (rather than living inline in
 * ExportJobService) purely to keep ExportJobService's own class complexity
 * under the project's PHPMD threshold — the impersonation concern is
 * self-contained and has no other coupling to ExportJobService's PAT/queue
 * responsibilities.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-38
 */
class JobOwnerImpersonator
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container   Container — used to lazily fetch the OR ObjectService.
     * @param IUserSession       $userSession Session impersonated for the duration of the work.
     * @param IUserManager       $userManager Resolves the owner UID to an IUser.
     * @param LoggerInterface    $logger      Logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IUserSession $userSession,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the OR object's owner, impersonate them for the duration of
     * `$work`, then ALWAYS restore the pre-impersonation session user —
     * success, failure, or thrown exception (hermiq
     * ScheduleService::runAgentAsOwner precedent).
     *
     * When the owner cannot be resolved (missing owner, deleted user, OR
     * unavailable, etc.) `$work` still runs, just without impersonation —
     * this helper never turns a resolution failure into a hard error; the
     * downstream OR call is left to fail (or succeed, e.g. if a session
     * user is already active) on its own terms.
     *
     * @param string   $objectId OR object id/uuid/slug whose owner should be impersonated.
     * @param callable $work     Zero-argument callback to run while impersonating.
     *
     * @return mixed Whatever `$work` returns.
     *
     * @throws \Throwable Whatever `$work` throws — propagated after the
     *                    session user is restored.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-38
     */
    public function runAsOwner(string $objectId, callable $work): mixed
    {
        [$priorUser, $impersonating] = $this->impersonate(objectId: $objectId);

        try {
            return $work();
        } finally {
            // ALWAYS restore — a background-job process can be reused
            // across multiple queued jobs, so leaking an impersonated
            // identity forward would misattribute the NEXT job's actions.
            if ($impersonating === true) {
                $this->userSession->setUser($priorUser);
            }
        }
    }//end runAsOwner()

    /**
     * Resolve the object's owner and swap the session user, if possible.
     *
     * Best-effort: any failure to resolve the object, its owner, or the
     * corresponding IUser is logged and treated as "do not impersonate".
     *
     * @param string $objectId OR object id/uuid/slug.
     *
     * @return array{0: ?\OCP\IUser, 1: bool} Tuple of [priorSessionUser,
     *                                        didImpersonate].
     */
    private function impersonate(string $objectId): array
    {
        try {
            if ($this->container->has('OCA\\OpenRegister\\Service\\ObjectService') === false) {
                return [null, false];
            }

            $service = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
            if (method_exists($service, 'find') === false) {
                return [null, false];
            }

            $object = $service->find($objectId);
            if ($object === null) {
                return [null, false];
            }

            // NOTE: no `method_exists($object, 'getOwner')` guard here —
            // unlike getObject()/find(), `getOwner()` is NOT a declared
            // method on ObjectEntity (real OR class or the test stub); it
            // only resolves via NC Entity's magic __call(), which
            // method_exists() cannot see (it returns false for magic-only
            // accessors). Calling it directly is safe: the surrounding
            // try/catch handles the (unexpected) case where $object isn't
            // an Entity that supports it.
            $ownerUid = $object->getOwner();
            if ($ownerUid === null || $ownerUid === '') {
                $this->logger->warning(
                    'OpenBuild: object '.$objectId.' has no recorded owner — '
                    .'cannot impersonate for this background-job write.'
                );
                return [null, false];
            }

            $user = $this->userManager->get($ownerUid);
            if ($user === null) {
                $this->logger->warning(
                    'OpenBuild: object '.$objectId.' owner "'.$ownerUid.'" '
                    .'no longer resolves to a Nextcloud user — cannot impersonate.'
                );
                return [null, false];
            }

            $priorUser = $this->userSession->getUser();
            $this->userSession->setUser($user);

            return [$priorUser, true];
        } catch (\Throwable $e) {
            $this->logger->warning(
                'OpenBuild: owner impersonation lookup failed for object '
                .$objectId.': '.$e->getMessage()
            );
            return [null, false];
        }//end try
    }//end impersonate()
}//end class
