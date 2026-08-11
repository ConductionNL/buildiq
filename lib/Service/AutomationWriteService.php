<?php

/**
 * OpenBuild AutomationWriteService
 *
 * Owns the WRITE path of the automation designer — the create/update/delete
 * half of `AutomationsController` (spec REQ-AUTD-008, Conduction/openbuild#173).
 * The controller keeps the effectual actions (compile/enable/disable/dry-run/
 * status) and stays a thin HTTP surface over this service.
 *
 * WHY THESE WRITES EXIST HERE AND NOT ON OR REST
 * ----------------------------------------------
 * ADR-022 says apps consume OpenRegister's abstractions rather than wrapping
 * them, and that default holds wherever OR's own authorization can express the
 * requirement. For `automation` it cannot, and the mismatch is structural, not
 * cosmetic: OR gates writes with a COARSE, schema-level group ACL
 * (`lib/Settings/register.d/40-automations.json` declares
 * `authorization.create/update/delete: ["admin"]`), while REQ-AUTD-008 needs a
 * FINE-GRAINED, per-object rule — "an editor of THIS Application, or an owner
 * when the version is the production one".
 *
 * The `automation` schema stays admin-only ON PURPOSE — that gate is the
 * backstop that makes this service the only way in for a non-admin, keeping the
 * authorization boundary in one place instead of two.
 *
 * AUTHORIZATION INVARIANTS (do not weaken)
 * ----------------------------------------
 *   - Every write authorises via `PermissionResolver::matchesCaller()` with
 *     `allowAdminBypass: false` against the PARENT APPLICATION's `permissions`
 *     block, BEFORE any write or compile side effect.
 *   - `create()` REQUIRES `applicationSlug` AND `versionUuid` in the body and
 *     400s without them — they ARE the authorization scope, so an unscoped
 *     create would be unauthorised by construction and must never be a silent
 *     allow.
 *   - `update()` PINS `applicationSlug`/`versionUuid` to the STORED values, so
 *     a caller holding a role on application A cannot re-parent an automation
 *     belonging to application B by posting A's slug.
 *   - `destroy()` removes the compiled artifacts BEFORE deleting the object: a
 *     deleted definition whose artifacts are still live is the one outcome that
 *     leaves the instance acting on a rule nobody can see or edit any more.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/specs/automation-designer/spec.md#req-autd-008
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Create/update/delete surface for `automation` objects, with the
 * per-Application RBAC that OpenRegister's schema ACL cannot express.
 *
 * @spec openspec/specs/automation-designer/spec.md#req-autd-008
 */
class AutomationWriteService
{
    /**
     * Shared OpenBuild register slug.
     */
    private const REGISTER_SLUG = 'openbuild';
    /**
     * Schema slug of the Automation object.
     */
    private const AUTOMATION_SCHEMA = 'automation';
    /**
     * Schema slug of the parent Application object.
     */
    private const APPLICATION_SCHEMA = 'application';

    /**
     * Constructor.
     *
     * @param LoggerInterface           $logger             PSR logger.
     * @param ObjectService             $objectService      OpenRegister object service.
     * @param AutomationCompilerService $compiler           Compiler/apply/remove/status service.
     * @param PermissionResolver        $permissionResolver Shared permission-grammar resolver.
     * @param IUserSession              $userSession        Current user session.
     *
     * @return void
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ObjectService $objectService,
        private readonly AutomationCompilerService $compiler,
        private readonly PermissionResolver $permissionResolver,
        private readonly IUserSession $userSession,
    ) {

    }//end __construct()

    /**
     * Decode the JSON request body to an associative array.
     *
     * `IRequest::getParams()` already merges a decoded JSON body for
     * `Content-Type: application/json`, and carries the route placeholders
     * with it — those are stripped so they can never be written onto the
     * stored object as if they were properties.
     *
     * @param IRequest $request The current HTTP request.
     *
     * @return array<string,mixed>
     *
     * @spec openspec/specs/automation-designer/spec.md#req-autd-008
     */
    public function requestBody(IRequest $request): array
    {
        $params = $request->getParams();

        unset($params['_route'], $params['uuid']);

        return $params;

    }//end requestBody()

    /**
     * Create an automation on an Application version (REQ-AUTD-008).
     *
     * Authorises against the PARENT APPLICATION's `permissions` block — named
     * by the body's `applicationSlug`/`versionUuid`, both mandatory — and only
     * then writes, in system context.
     *
     * @param array<string,mixed> $payload The decoded request body.
     * @param array<int,string>   $roles   Roles required on the parent Application.
     *
     * @return JSONResponse The created Automation, or an error envelope.
     *
     * @spec openspec/specs/automation-designer/spec.md#req-autd-008
     */
    public function create(array $payload, array $roles): JSONResponse
    {
        return $this->withApplication(
            applicationSlug: (string) ($payload['applicationSlug'] ?? ''),
            versionUuid: (string) ($payload['versionUuid'] ?? ''),
            roles: $roles,
            action: function () use ($payload): JSONResponse {
                // No uuid — OR mints one. `_rbac: false` for the reason
                // documented on saveAuthorised().
                $saved = $this->saveAuthorised(automation: $payload, uuid: null);

                return new JSONResponse(data: $this->normalise(object: $saved), statusCode: Http::STATUS_CREATED);
            }
        );

    }//end create()

    /**
     * Replace an automation's definition (REQ-AUTD-008).
     *
     * The caller is authorised by `AutomationsController::withAutomation()`
     * against the STORED record's own Application BEFORE this runs, and the
     * ownership fields are pinned to those stored values here — a client may
     * rewrite the definition, it may not re-parent the record into an
     * application or version it was never authorised against.
     *
     * @param string              $uuid       The Automation object uuid.
     * @param array<string,mixed> $payload    The decoded request body.
     * @param array<string,mixed> $automation The STORED automation (authorization scope).
     *
     * @return JSONResponse The saved Automation.
     *
     * @spec openspec/specs/automation-designer/spec.md#req-autd-008
     */
    public function update(string $uuid, array $payload, array $automation): JSONResponse
    {
        $payload['applicationSlug'] = ($automation['applicationSlug'] ?? null);
        $payload['versionUuid']     = ($automation['versionUuid'] ?? null);

        $saved = $this->saveAuthorised(automation: $payload, uuid: $uuid);

        return new JSONResponse(data: $this->normalise(object: $saved), statusCode: Http::STATUS_OK);

    }//end update()

    /**
     * Delete an automation and remove its compiled artifacts (REQ-AUTD-008).
     *
     * The artifacts are removed FIRST: a deleted definition whose compiled
     * artifacts are still live is the one outcome that leaves the instance
     * acting on a rule nobody can see or edit any more.
     *
     * The caller is authorised by `AutomationsController::withAutomation()`
     * before this runs.
     *
     * @param string              $uuid       The Automation object uuid.
     * @param array<string,mixed> $automation The STORED automation.
     *
     * @return JSONResponse Empty success envelope.
     *
     * @spec openspec/specs/automation-designer/spec.md#req-autd-008
     */
    public function destroy(string $uuid, array $automation): JSONResponse
    {
        $this->compiler->remove(
            automation: $automation,
            provenance: $this->orArray(value: $automation['provenance'] ?? null)
        );

        // `_rbac: false` — see saveAuthorised() for the full rationale; the
        // delete is the same decision as the save.
        $this->objectService->deleteObject(
            uuid: $uuid,
            register: self::REGISTER_SLUG,
            schema: self::AUTOMATION_SCHEMA,
            _rbac: false
        );

        return new JSONResponse(data: ['deleted' => $uuid], statusCode: Http::STATUS_OK);

    }//end destroy()

    /**
     * Persist an automation whose artifacts were just (re)compiled.
     *
     * Called from `AutomationsController::recompileAndRespond()` for the
     * compile/enable/disable routes, AFTER `withAutomation()` has authorised
     * the caller against the parent Application. Kept here so that every
     * `_rbac: false` write on the `automation` schema — create, update, this
     * one, and the delete — sits in one file behind one rationale.
     *
     * @param array<string,mixed> $automation The automation with its fresh provenance.
     * @param string              $uuid       The Automation object uuid.
     *
     * @return array<string,mixed> The saved object, normalised.
     *
     * @spec openspec/specs/automation-designer/spec.md#req-autd-008
     */
    public function saveCompiled(array $automation, string $uuid): array
    {
        return $this->normalise(object: $this->saveAuthorised(automation: $automation, uuid: $uuid));

    }//end saveCompiled()

    /**
     * Persist an automation in SYSTEM CONTEXT (`_rbac: false`).
     *
     * `_rbac: false` — and it is the whole point of this service.
     *
     * Every caller of this method has already passed the per-Application check
     * (`withApplication()` here, `withAutomation()` on the controller), which
     * resolved the parent Application and matched the caller against its
     * `permissions` block with `allowAdminBypass: false`. That is the
     * authorization decision for this write, and it is finer-grained than
     * anything OpenRegister can express: OR's schema gate is a coarse group ACL
     * (`authorization.update: ["admin"]` on the `automation` schema —
     * lib/Settings/register.d/40-automations.json), while the requirement is
     * "an OWNER of THIS Application on THIS version".
     *
     * Leaving the default `_rbac: true` here made OR re-litigate a decision
     * openbuild had already made and reach the opposite answer. MEASURED on a
     * live instance (NC 34, openregister 0.2.17-unstable.36) before this
     * change, with the Application's `permissions` granting
     * `owners: ['user:rbac-owner']`:
     *
     * - as rbac-editor: POST /api/automations/{uuid}/enable -> 403
     *   insufficient_permission, which is correct.
     * - as rbac-owner: the same call -> 500 internal_error, "User 'rbac-owner'
     *   does not have permission to 'update' objects in schema 'Automation'".
     *
     * i.e. the legitimate owner was refused, and the refusal surfaced as a 500
     * because OR throws and the outer `catch (Throwable)` maps anything
     * unrecognised to internal_error. A permission failure that reads as a
     * server fault is worse than either a 200 or a 403, because it accuses the
     * wrong component.
     *
     * This is NOT a widening: the routes are `#[NoAdminRequired]` but the
     * per-Application check is unconditional, runs before this line is reached,
     * and grants nothing to NC admins on its own (`allowAdminBypass: false`).
     * See Conduction/openbuild#173.
     *
     * @param array<string,mixed> $automation The automation to persist.
     * @param string|null         $uuid       Existing uuid, or null to let OR mint one.
     *
     * @return mixed The OR result entry.
     *
     * @spec openspec/specs/automation-designer/spec.md#req-autd-008
     */
    private function saveAuthorised(array $automation, ?string $uuid): mixed
    {
        // `uuid: null` is exactly what omitting the argument does (OR declares
        // `?string $uuid = null`) — on create, OR mints the uuid itself.
        return $this->objectService->saveObject(
            object: $automation,
            register: self::REGISTER_SLUG,
            schema: self::AUTOMATION_SCHEMA,
            uuid: $uuid,
            _rbac: false
        );

    }//end saveAuthorised()

    /**
     * Authorise against an Application + version, then run `$action`.
     *
     * The create-side counterpart to `AutomationsController::withAutomation()`:
     * there is no stored Automation yet, so the scope comes from the request's
     * `applicationSlug` and `versionUuid`. Both are REQUIRED — an unscoped
     * create would have no `permissions` block to check against and would
     * therefore be unauthorised by construction, which must be a 400 and never
     * a silent allow.
     *
     * @param string            $applicationSlug Parent Application slug.
     * @param string            $versionUuid     ApplicationVersion uuid the automation belongs to.
     * @param array<int,string> $roles           Roles required on the parent Application.
     * @param callable          $action          `fn(): JSONResponse`.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/automation-designer/spec.md#req-autd-008
     */
    private function withApplication(
        string $applicationSlug,
        string $versionUuid,
        array $roles,
        callable $action
    ): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return $this->error(code: 'unauthenticated', detail: null, status: Http::STATUS_UNAUTHORIZED);
        }

        if ($applicationSlug === '' || $versionUuid === '') {
            return $this->error(
                code: 'invalid_request',
                detail: 'applicationSlug and versionUuid are required — they are the authorization scope.',
                status: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $application = $this->loadApplication(slug: $applicationSlug);
            if ($application === null) {
                return $this->error(
                    code: 'not_found',
                    detail: 'Application '.$applicationSlug.' not found',
                    status: Http::STATUS_NOT_FOUND
                );
            }

            $allowed = $this->permissionResolver->matchesCaller(
                permissions: $this->orArray(value: $application['permissions'] ?? null),
                caller: $user,
                userGroups: $this->permissionResolver->resolveUserGroups(user: $user),
                allowAdminBypass: false,
                roles: $roles
            );

            if ($allowed === false) {
                return $this->error(code: 'insufficient_permission', detail: null, status: Http::STATUS_FORBIDDEN);
            }

            return $action();
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuild: AutomationWriteService failed for application '.$applicationSlug.': '.$e->getMessage(),
                ['exception' => $e]
            );
            return $this->error(code: 'internal_error', detail: $e->getMessage(), status: Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end withApplication()

    /**
     * Load an Automation object by uuid.
     *
     * Shared with `AutomationsController::withAutomation()` so the RBAC
     * pipeline and the writes it guards read the object through exactly one
     * code path — two resolvers could disagree about what "the stored
     * automation" is, and the authorization scope is read off that object.
     *
     * @param string $uuid The Automation object uuid.
     *
     * @return array<string,mixed>|null
     *
     * @spec openspec/specs/automation-designer/spec.md#req-autd-008
     */
    public function loadAutomation(string $uuid): ?array
    {
        try {
            $entity = $this->objectService->find(id: $uuid, register: self::REGISTER_SLUG, schema: self::AUTOMATION_SCHEMA);
        } catch (Throwable $e) {
            return null;
        }

        if ($entity === null) {
            return null;
        }

        return $this->normalise(object: $entity);

    }//end loadAutomation()

    /**
     * Load the parent Application by slug.
     *
     * @param string $slug The Application slug.
     *
     * @return array<string,mixed>|null
     *
     * @spec openspec/specs/automation-designer/spec.md#req-autd-008
     */
    public function loadApplication(string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }

        try {
            $entity = $this->objectService->find(id: $slug, register: self::REGISTER_SLUG, schema: self::APPLICATION_SCHEMA);
        } catch (Throwable $e) {
            return null;
        }

        if ($entity === null) {
            return null;
        }

        return $this->normalise(object: $entity);

    }//end loadApplication()

    /**
     * Return `$value` when it is an array, otherwise an empty array.
     *
     * @param mixed $value The candidate value.
     *
     * @return array<string,mixed>
     */
    private function orArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        return [];

    }//end orArray()

    /**
     * Coerce an OR result entry to a plain associative array.
     *
     * @param mixed $object The OR object/result entry.
     *
     * @return array<string,mixed>
     */
    private function normalise(mixed $object): array
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

        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $inner = $object->getObject();
            if (is_array($inner) === true) {
                return $inner;
            }
        }

        return [];

    }//end normalise()

    /**
     * Build a uniform error envelope.
     *
     * @param string      $code   Error code.
     * @param string|null $detail Optional detail.
     * @param int         $status HTTP status code.
     *
     * @return JSONResponse
     */
    private function error(string $code, ?string $detail, int $status): JSONResponse
    {
        $body = ['error' => $code];
        if ($detail !== null) {
            $body['detail'] = $detail;
        }

        return new JSONResponse(data: $body, statusCode: $status);

    }//end error()
}//end class
