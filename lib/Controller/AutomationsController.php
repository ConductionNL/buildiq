<?php

/**
 * OpenBuild AutomationsController
 *
 * REST surface for the automation-designer change (spec automation-designer
 * REQ-AUTD-005/006/007/008). This controller owns BOTH the CRUD on the
 * `automation` object and the EFFECTUAL actions that turn a stored automation
 * definition into (or out of) live compiled artifacts:
 *
 *   - POST   /api/automations                — create on an Application version
 *   - PUT    /api/automations/{uuid}         — replace the definition
 *   - DELETE /api/automations/{uuid}         — remove artifacts, then the object
 *   - POST   /api/automations/{uuid}/compile — recompile in place (upsert)
 *   - POST   /api/automations/{uuid}/enable  — flip enabled:true and recompile
 *   - POST   /api/automations/{uuid}/disable — flip enabled:false and recompile
 *   - POST   /api/automations/{uuid}/dry-run — evaluate via the rules engine, no side effects
 *   - GET    /api/automations/{uuid}/status  — recompute drift against live artifacts
 *
 * WHY CRUD IS HERE AND NOT ON OR REST (Conduction/openbuild#173)
 * -------------------------------------------------------------
 * ADR-022 says apps consume OpenRegister's abstractions rather than wrapping
 * them, and that default holds wherever OR's own authorization can express the
 * requirement. For `automation` it cannot, and the mismatch is structural, not
 * cosmetic: OR gates writes with a COARSE, schema-level group ACL
 * (`lib/Settings/register.d/40-automations.json` declares
 * `authorization.create/update/delete: ["admin"]`), while REQ-AUTD-008 needs a
 * FINE-GRAINED, per-object rule — "an editor of THIS Application, or an owner
 * when the version is the production one".
 *
 * The three CRUD writes themselves live in
 * {@see \OCA\OpenBuild\Service\AutomationWriteService} — this controller is the
 * HTTP surface, that service is the write path and the create-side RBAC scope.
 *
 * Before this controller took the writes, the designer POSTed OR REST
 * directly, so the app-level `permissions` block was never consulted on
 * create/update at all and the OR gate refused every non-admin. The `automation`
 * schema stays admin-only ON PURPOSE — that gate is the backstop that makes
 * this controller the only way in for a non-admin, keeping the authorization
 * boundary in one place instead of two. Widening the schema instead would have
 * let any authenticated user rewrite any automation on any application over OR
 * REST, with no per-application filter anywhere.
 *
 * RBAC (design.md Decision 7 / spec REQ-AUTD-008), enforced via the shared
 * `PermissionResolver::matchesCaller()` grammar — never NC-admin auto-granted
 * (`allowAdminBypass: false` throughout, mirroring
 * `VersionPromotionController`'s REQ-OBVP-007 posture): `compile`/`disable`/
 * `dry-run`/`status` require `['owners','editors']` on the parent
 * Application; `enable` requires the same UNLESS the automation's
 * `versionUuid` is the Application's current `productionVersion`, in which
 * case only `['owners']` may enable. Every check runs BEFORE any compile
 * side effect; a rejected call never touches a compiled artifact.
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
 * @spec openspec/changes/automation-designer/tasks.md#3.1
 * @spec openspec/specs/automation-designer/spec.md#req-autd-007
 * @spec openspec/specs/automation-designer/spec.md#req-autd-008
 * @spec openspec/changes/automation-approval-steps/tasks.md#5.1
 * @spec openspec/changes/automation-approval-steps/tasks.md#5.2
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Controller;

use OCA\OpenBuild\AppInfo\Application;
use OCA\OpenBuild\Exception\UnsupportedAutomationCombinationException;
use OCA\OpenBuild\Service\AutomationCompilerService;
use OCA\OpenBuild\Service\AutomationWriteService;
use OCA\OpenBuild\Service\ConditionActionExecutor;
use OCA\OpenBuild\Service\PermissionResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller serving the automation compile/enable/disable/dry-run/status API.
 *
 * @spec openspec/changes/automation-designer/tasks.md#3.1
 */
class AutomationsController extends Controller
{
    /**
     * Roles allowed to author/dry-run/disable, and to enable on a
     * non-production version (design.md Decision 7).
     *
     * @var array<int,string>
     */
    private const WRITE_ROLES = ['owners', 'editors'];

    /**
     * Roles allowed to enable an automation on the Application's current
     * production version (design.md Decision 7 — REQ-OBVP-007 posture, no
     * admin bypass).
     *
     * @var array<int,string>
     */
    private const PRODUCTION_ENABLE_ROLES = ['owners'];

    /**
     * Constructor.
     *
     * @param IRequest                  $request            The current HTTP request.
     * @param LoggerInterface           $logger             PSR logger.
     * @param AutomationCompilerService $compiler           Compiler/apply/remove/status service.
     * @param ConditionActionExecutor   $conditionExecutor  Rules engine executor (dry-run panel).
     * @param PermissionResolver        $permissionResolver Shared permission-grammar resolver.
     * @param IUserSession              $userSession        Current user session.
     * @param AutomationWriteService    $writeService       Create/update/delete write path.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly LoggerInterface $logger,
        private readonly AutomationCompilerService $compiler,
        private readonly ConditionActionExecutor $conditionExecutor,
        private readonly PermissionResolver $permissionResolver,
        private readonly IUserSession $userSession,
        private readonly AutomationWriteService $writeService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Create an automation on an Application version (REQ-AUTD-008).
     *
     * Delegates to {@see AutomationWriteService::create()}, which authorises
     * against the PARENT APPLICATION's `permissions` block (named by the body's
     * mandatory `applicationSlug`/`versionUuid`) and only then writes, in
     * system context. See the class docblock for why this route exists rather
     * than the designer POSTing OR REST directly.
     *
     * @return JSONResponse The created Automation, or an error envelope.
     *
     * @spec openspec/specs/automation-designer/spec.md#req-autd-008
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function create(): JSONResponse
    {
        return $this->writeService->create(
            payload: $this->writeService->requestBody(request: $this->request),
            roles: self::WRITE_ROLES
        );

    }//end create()

    /**
     * Replace an automation's definition (REQ-AUTD-008).
     *
     * The stored record's OWN `applicationSlug`/`versionUuid` decide the
     * authorization scope, never the client's body — otherwise a caller who
     * holds a role on application A could move an automation belonging to
     * application B by posting A's slug.
     *
     * @param string $uuid The Automation object uuid.
     *
     * @return JSONResponse The saved Automation, or an error envelope.
     *
     * @spec openspec/specs/automation-designer/spec.md#req-autd-008
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function update(string $uuid): JSONResponse
    {
        $payload = $this->writeService->requestBody(request: $this->request);

        return $this->withAutomation(
            uuid: $uuid,
            roles: self::WRITE_ROLES,
            productionRoles: null,
            action: fn (array $automation): JSONResponse => $this->writeService->update(
                uuid: $uuid,
                payload: $payload,
                automation: $automation
            )
        );

    }//end update()

    /**
     * Delete an automation and remove its compiled artifacts (REQ-AUTD-008).
     *
     * The artifacts are removed FIRST: a deleted definition whose compiled
     * artifacts are still live is the one outcome that leaves the instance
     * acting on a rule nobody can see or edit any more.
     *
     * @param string $uuid The Automation object uuid.
     *
     * @return JSONResponse Empty success envelope, or an error envelope.
     *
     * @spec openspec/specs/automation-designer/spec.md#req-autd-008
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function destroy(string $uuid): JSONResponse
    {
        return $this->withAutomation(
            uuid: $uuid,
            roles: self::WRITE_ROLES,
            productionRoles: null,
            action: fn (array $automation): JSONResponse => $this->writeService->destroy(
                uuid: $uuid,
                automation: $automation
            )
        );

    }//end destroy()

    /**
     * Recompile an automation in place (upsert its artifacts).
     *
     * @param string $uuid The Automation object uuid.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/automation-designer/tasks.md#3.1
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function compile(string $uuid): JSONResponse
    {
        return $this->withAutomation(
            uuid: $uuid,
            roles: self::WRITE_ROLES,
            productionRoles: null,
            action: function (array $automation): JSONResponse {
                return $this->recompileAndRespond(automation: $automation);
            }
        );

    }//end compile()

    /**
     * Enable an automation (owners-only when its version is the Application's
     * current production version — spec REQ-AUTD-008).
     *
     * @param string $uuid The Automation object uuid.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/automation-designer/tasks.md#3.1
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function enable(string $uuid): JSONResponse
    {
        return $this->withAutomation(
            uuid: $uuid,
            roles: self::WRITE_ROLES,
            productionRoles: self::PRODUCTION_ENABLE_ROLES,
            action: function (array $automation): JSONResponse {
                $automation['enabled'] = true;
                return $this->recompileAndRespond(automation: $automation);
            }
        );

    }//end enable()

    /**
     * Disable an automation — recompiles with every artifact inert
     * (design.md Decision 5).
     *
     * @param string $uuid The Automation object uuid.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/automation-designer/tasks.md#3.1
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function disable(string $uuid): JSONResponse
    {
        return $this->withAutomation(
            uuid: $uuid,
            roles: self::WRITE_ROLES,
            productionRoles: null,
            action: function (array $automation): JSONResponse {
                $automation['enabled'] = false;
                return $this->recompileAndRespond(automation: $automation);
            }
        );

    }//end disable()

    /**
     * Evaluate an automation via the rules engine with `dryRun: true` —
     * dispatches no side effect and mutates no compiled artifact (spec
     * REQ-AUTD-007 / design.md Decision 9).
     *
     * @param string $uuid The Automation object uuid.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/automation-designer/tasks.md#3.1
     * @spec openspec/changes/automation-approval-steps/tasks.md#5.2
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function dryRun(string $uuid): JSONResponse
    {
        return $this->withAutomation(
            uuid: $uuid,
            roles: self::WRITE_ROLES,
            productionRoles: null,
            // `use ($uuid)`: the closure logs $uuid on failure, but never captured it —
            // so the one line that tells you WHICH automation blew up was reading an
            // undefined variable. Pre-existing; caught by Psalm.
            action: function (array $automation) use ($uuid): JSONResponse {
                $params  = $this->request->getParams();
                $payload = ($params['payload'] ?? []);
                if (is_array($payload) === false) {
                    return $this->error(code: 'invalid_payload', detail: 'payload must be an object', status: Http::STATUS_UNPROCESSABLE_ENTITY);
                }

                $rule      = $this->compiler->compileDryRunRule(automation: $automation);
                $startedAt = microtime(true);

                try {
                    $outcome = $this->conditionExecutor->execute([$rule], $payload, true, null);
                } catch (Throwable $e) {
                    $this->logger->error('OpenBuild: automation dry-run failed for '.$uuid.': '.$e->getMessage());
                    return $this->error(code: 'dry_run_failed', detail: 'Dry-run evaluation failed', status: Http::STATUS_UNPROCESSABLE_ENTITY);
                }

                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

                // Automation-approval-steps REQ-AUTD-007: an automation
                // carrying an `approval` action reports its live aggregate
                // approval state alongside the dry-run result (never a real
                // ApprovalStep — compileDryRunRule()/the executor above never
                // touch OR's approval tables, only ConditionActionExecutor's
                // in-memory dry-run marking).
                $provenance = $this->orArray(value: $automation['provenance'] ?? null);

                return new JSONResponse(
                    data: [
                        'conditionMatched' => (count($outcome['triggeredRules']) > 0),
                        'actions'          => ($outcome['triggeredRules'][0]['actions_executed'] ?? []),
                        'errors'           => $outcome['errors'],
                        'durationMs'       => $durationMs,
                        'approvalState'    => $this->compiler->approvalState(automation: $automation, provenance: $provenance),
                    ],
                    statusCode: Http::STATUS_OK
                );
            }
        );

    }//end dryRun()

    /**
     * Recompute drift against the last-applied provenance (spec REQ-AUTD-005).
     *
     * @param string $uuid The Automation object uuid.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/automation-designer/tasks.md#3.1
     * @spec openspec/changes/automation-approval-steps/tasks.md#5.1
     */
    #[NoAdminRequired]
    public function status(string $uuid): JSONResponse
    {
        return $this->withAutomation(
            uuid: $uuid,
            roles: self::WRITE_ROLES,
            productionRoles: null,
            action: function (array $automation): JSONResponse {
                $provenance = $this->orArray(value: $automation['provenance'] ?? null);
                $status     = $this->compiler->status(automation: $automation, provenance: $provenance);
                // Automation-approval-steps REQ-AUTD-007: aggregate approval
                // state (none|pending|approved|rejected) for the automation's
                // most recently initialised approval chain instantiation.
                $status['approvalState'] = $this->compiler->approvalState(automation: $automation, provenance: $provenance);
                return new JSONResponse(data: $status, statusCode: Http::STATUS_OK);
            }
        );

    }//end status()

    /**
     * Shared load → RBAC → action pipeline for every route in this controller.
     *
     * Resolves the automation and its parent Application, enforces RBAC
     * BEFORE invoking `$action` (no compile side effect on a rejected call),
     * and maps any exception to a uniform error envelope.
     *
     * @param string                 $uuid            The Automation object uuid.
     * @param array<int,string>      $roles           Roles required on a non-production version.
     * @param array<int,string>|null $productionRoles Roles required INSTEAD when the automation's
     *                                                version is the Application's production version
     *                                                (null = same roles as `$roles` apply everywhere).
     * @param callable               $action          `fn(array $automation): JSONResponse`.
     *
     * @return JSONResponse
     */
    private function withAutomation(string $uuid, array $roles, ?array $productionRoles, callable $action): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return $this->error(code: 'unauthenticated', detail: null, status: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $automation = $this->writeService->loadAutomation(uuid: $uuid);
            if ($automation === null) {
                return $this->error(code: 'not_found', detail: 'Automation '.$uuid.' not found', status: Http::STATUS_NOT_FOUND);
            }

            $applicationSlug = (string) ($automation['applicationSlug'] ?? '');
            $application     = $this->writeService->loadApplication(slug: $applicationSlug);
            if ($application === null) {
                return $this->error(code: 'not_found', detail: 'Application '.$applicationSlug.' not found', status: Http::STATUS_NOT_FOUND);
            }

            $effectiveRoles      = $roles;
            $onProductionVersion = $this->isProductionVersion(
                application: $application,
                versionUuid: (string) ($automation['versionUuid'] ?? '')
            );
            if ($productionRoles !== null && $onProductionVersion === true) {
                $effectiveRoles = $productionRoles;
            }

            $permissions = $this->orArray(value: $application['permissions'] ?? null);
            $userGroups  = $this->permissionResolver->resolveUserGroups(user: $user);
            $allowed     = $this->permissionResolver->matchesCaller(
                permissions: $permissions,
                caller: $user,
                userGroups: $userGroups,
                allowAdminBypass: false,
                roles: $effectiveRoles
            );

            if ($allowed === false) {
                return $this->error(code: 'insufficient_permission', detail: null, status: Http::STATUS_FORBIDDEN);
            }

            return $action($automation);
        } catch (Throwable $e) {
            $this->logger->error('OpenBuild: AutomationsController failed for '.$uuid.': '.$e->getMessage(), ['exception' => $e]);
            return $this->error(code: 'internal_error', detail: $e->getMessage(), status: Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end withAutomation()

    /**
     * Compile + apply + persist an automation's provenance, returning the
     * uniform success envelope. Fail-closed matrix rejections map to 422.
     *
     * @param array<string,mixed> $automation The (possibly enabled/disabled-mutated) automation.
     *
     * @return JSONResponse
     */
    private function recompileAndRespond(array $automation): JSONResponse
    {
        try {
            $plan = $this->compiler->compile(automation: $automation);
        } catch (UnsupportedAutomationCombinationException $e) {
            return $this->error(code: $e->getErrorCode(), detail: $e->getMessage(), status: Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        $priorProvenance = $this->orArray(value: $automation['provenance'] ?? null);
        $provenance      = $this->compiler->apply(automation: $automation, plan: $plan, priorProvenance: $priorProvenance);

        $automation['provenance'] = $provenance;
        $uuid = (string) ($automation['id'] ?? $automation['uuid'] ?? '');

        // SYSTEM CONTEXT (`_rbac: false`). Every caller of this method has
        // already passed `withAutomation()`, which resolved the parent
        // Application and matched the caller against its `permissions` block
        // with `allowAdminBypass: false` — that is the authorization decision
        // for this write. The full rationale, including the live measurement
        // that forced it, lives with the write itself on
        // AutomationWriteService::saveAuthorised().
        $saved = $this->writeService->saveCompiled(automation: $automation, uuid: $uuid);

        return new JSONResponse(data: $saved, statusCode: Http::STATUS_OK);

    }//end recompileAndRespond()

    /**
     * Whether an ApplicationVersion uuid is the Application's current
     * production version.
     *
     * @param array<string,mixed> $application The Application object.
     * @param string              $versionUuid The ApplicationVersion uuid to test.
     *
     * @return bool
     */
    private function isProductionVersion(array $application, string $versionUuid): bool
    {
        if ($versionUuid === '') {
            return false;
        }

        $productionVersion = ($application['productionVersion'] ?? null);
        if (is_array($productionVersion) === true) {
            $productionUuid = (string) ($productionVersion['id'] ?? $productionVersion['uuid'] ?? '');
        } else {
            $productionUuid = (string) ($productionVersion ?? '');
        }

        return $productionUuid !== '' && $productionUuid === $versionUuid;

    }//end isProductionVersion()

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
