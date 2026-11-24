<?php

/**
 * Plans an outside agent submits before it may write through the builder tools.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Kernel\Contracts\PermissionResolverInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IUser;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stores agent plans as `agent-plan` objects and decides, per write tool call,
 * whether an approved plan covers it.
 *
 * A plan is a list of `{tool, arguments}` steps for one app. It writes nothing
 * when submitted. A person with an owners or editors role on the app approves
 * or rejects it. A write call must then name the plan, come from the user who
 * submitted it, and match one not-yet-used step exactly (tool and arguments);
 * each step can be used once.
 *
 * An approval is signed with the instance secret over the plan id, the
 * approver and the steps, so a plan cannot be marked approved, or have its
 * steps changed after approval, through OpenRegister's generic object API.
 *
 * @spec openspec/changes/archive/2026-11-24-openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
 *
 * @SuppressWarnings(PHPMD.StaticAccess) AgentPlanSteps is pure step logic, AgentPlanStore::asArray() a pure coercion.
 */
class AgentPlanService {

	/**
	 * The builder tools that write. Every other builder tool only reads.
	 */
	public const WRITE_TOOLS = AgentPlanSteps::WRITE_TOOLS;

	public const STATUS_PENDING = 'pending';

	public const STATUS_APPROVED = 'approved';

	public const STATUS_REJECTED = 'rejected';

	/**
	 * The most steps one plan may hold.
	 */
	public const MAX_STEPS = AgentPlanSteps::MAX_STEPS;

	private const REGISTER_SLUG = 'buildiq';

	private const APP_SCHEMA = 'built-app';

	private const WRITE_ROLES = ['owners', 'editors'];

	/**
	 * Constructor.
	 *
	 * @param AgentPlanStore              $store              Reads and writes the plans.
	 * @param ObjectServiceInterface      $objectService      OpenRegister's object service, for the app a plan targets.
	 * @param PermissionResolverInterface $permissionResolver The app role matcher.
	 * @param ICrypto                     $crypto             Signs approvals with the instance secret.
	 * @param LoggerInterface             $logger             Logger.
	 * @param AppOrganisationGuard|null   $organisationGuard  Hides another organisation's scoped app (REQ-BQOS-002).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AgentPlanStore $store,
		private readonly ObjectServiceInterface $objectService,
		private readonly PermissionResolverInterface $permissionResolver,
		private readonly ICrypto $crypto,
		private readonly LoggerInterface $logger,
		private readonly ?AppOrganisationGuard $organisationGuard=null,
	) {
	}//end __construct()

	/**
	 * Store a pending plan for one app. Writes nothing to the app.
	 *
	 * @param string                           $appSlug The app every step targets.
	 * @param array<int,array<string,mixed>>   $steps   The `{tool, arguments}` steps.
	 * @param string                           $userId  The user the agent acts as.
	 *
	 * @return array<string,mixed> `{planId, status, appSlug, steps}` or `{isError, error, message}`.
	 *
	 * @spec openspec/changes/archive/2026-11-24-openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public function submit(string $appSlug, array $steps, string $userId): array {
		if ($userId === '') {
			return self::error(error: 'forbidden', message: 'You must be signed in.');
		}

		$invalid = AgentPlanSteps::stepsError(appSlug: $appSlug, steps: $steps);
		if ($invalid !== null) {
			return self::error(error: 'plan_invalid', message: $invalid);
		}

		$normalised = AgentPlanSteps::normalisedSteps(steps: $steps);
		$planId = $this->store->put(
			object: ['appSlug' => $appSlug, 'steps' => $normalised, 'status' => self::STATUS_PENDING, 'submittedBy' => $userId, 'usedSteps' => []]
		);
		if ($planId === null) {
			return self::error(error: 'plan_store_failed', message: 'The plan could not be stored.');
		}

		return [
			'planId' => $planId,
			'status' => self::STATUS_PENDING,
			'appSlug' => $appSlug,
			'steps' => $normalised,
		];
	}//end submit()

	/**
	 * Approve or reject a pending plan, as a person with a write role on its app.
	 *
	 * Admin power does not stand in for the role. A plan that creates its app
	 * (the app does not exist yet) needs an admin, as `buildiq.createApp` does.
	 *
	 * @param string $planId  The plan uuid.
	 * @param bool   $approve True to approve, false to reject.
	 * @param IUser  $caller  The person deciding.
	 *
	 * @return array<string,mixed> `{planId, status}` or `{isError, error, message}`.
	 *
	 * @spec openspec/changes/archive/2026-11-24-openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public function decide(string $planId, bool $approve, IUser $caller): array {
		$plan = $this->store->load(planId: $planId);
		$refusal = $this->decisionRefusal(plan: $plan, caller: $caller);
		if ($refusal !== null || $plan === null) {
			return ($refusal ?? self::error(error: 'not_found', message: 'No such plan.'));
		}

		$plan['data']['decidedBy'] = $caller->getUID();
		$data = $this->decided(plan: $plan, approve: $approve);
		if ($this->store->put(object: $data, uuid: $plan['uuid']) === null) {
			return self::error(error: 'plan_store_failed', message: 'The plan could not be updated.');
		}

		return ['planId' => $plan['uuid'], 'status' => $data['status']];
	}//end decide()

	/**
	 * The plan's data after the decision: its status and the approval signature (empty on a rejection).
	 *
	 * @param array<string,mixed> $plan    The plan: uuid and data, `decidedBy` already set.
	 * @param bool                $approve Approve, or reject.
	 *
	 * @return array<string,mixed>
	 */
	private function decided(array $plan, bool $approve): array {
		$data = $plan['data'];
		$data['status'] = self::STATUS_REJECTED;
		$data['approval'] = '';
		if ($approve === true) {
			$data['status'] = self::STATUS_APPROVED;
			$data['approval'] = $this->signature(uuid: $plan['uuid'], data: $data);
		}

		return $data;
	}//end decided()

	/**
	 * Why a plan cannot be decided by this caller, or null when it can.
	 *
	 * @param array{uuid: string, data: array<string, mixed>}|null $plan   The plan, null when there is none.
	 * @param IUser                                               $caller The deciding user.
	 *
	 * @return array<string, mixed>|null
	 */
	private function decisionRefusal(?array $plan, IUser $caller): ?array {
		if ($plan === null) {
			return self::error(error: 'not_found', message: 'No such plan.');
		}

		if (($plan['data']['status'] ?? '') !== self::STATUS_PENDING) {
			return self::error(error: 'plan_decided', message: 'This plan was already approved or rejected.');
		}

		if ($this->mayDecide(appSlug: (string)($plan['data']['appSlug'] ?? ''), caller: $caller) === false) {
			return self::error(error: 'forbidden', message: 'Only an owner or editor of this app can approve or reject its plans.');
		}

		return null;
	}//end decisionRefusal()

	/**
	 * A plan, for a person with a write role on its app.
	 *
	 * @param string $planId The plan uuid.
	 * @param IUser  $caller The person reading it.
	 *
	 * @return array<string,mixed> The plan's fields or `{isError, error, message}`.
	 *
	 * @spec openspec/changes/archive/2026-11-24-openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public function show(string $planId, IUser $caller): array {
		$plan = $this->store->load(planId: $planId);
		if ($plan === null) {
			return self::error(error: 'not_found', message: 'No such plan.');
		}

		if ($this->mayDecide(appSlug: (string)($plan['data']['appSlug'] ?? ''), caller: $caller) === false) {
			return self::error(error: 'forbidden', message: 'Only an owner or editor of this app can see its plans.');
		}

		$data = $plan['data'];
		unset($data['approval']);
		return ['planId' => $plan['uuid']] + $data;
	}//end show()

	/**
	 * Decide whether an approved plan covers one write call, and use up its step.
	 *
	 * @param string              $planId    The plan the call names.
	 * @param string              $toolId    The write tool called.
	 * @param array<string,mixed> $arguments The call's arguments, without `planId`.
	 * @param string              $userId    The user the agent acts as.
	 *
	 * @return array<string,mixed>|null Null when the call may run; else `{isError, error, message}`.
	 *
	 * @spec openspec/changes/archive/2026-11-24-openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public function authorizeStep(string $planId, string $toolId, array $arguments, string $userId): ?array {
		$plan = $this->store->load(planId: $planId);
		$refusal = $this->useRefusal(plan: $plan, userId: $userId);
		if ($refusal !== null || $plan === null) {
			return ($refusal ?? self::error(error: 'plan_required', message: 'No approved plan with this planId.'));
		}

		$data = $plan['data'];
		$wanted = AgentPlanSteps::callKey(toolId: $toolId, arguments: AgentPlanSteps::withoutPlanId(arguments: $arguments));
		$index = AgentPlanSteps::matchingStep(data: $data, wanted: $wanted);
		if ($index === null) {
			return self::error(error: 'plan_step_mismatch', message: 'This call does not match an unused step of the approved plan.');
		}

		return $this->markUsed(plan: $plan, index: $index);
	}//end authorizeStep()

	/**
	 * Record a step as used; a refusal when the plan cannot be saved, else null.
	 *
	 * @param array<string,mixed> $plan  The plan: uuid and data.
	 * @param int                 $index The step used.
	 *
	 * @return array<string,mixed>|null
	 */
	private function markUsed(array $plan, int $index): ?array {
		$data = $plan['data'];
		$data['usedSteps'] = array_values(array_merge((array)($data['usedSteps'] ?? []), [$index]));
		if ($this->store->put(object: $data, uuid: $plan['uuid']) === null) {
			return self::error(error: 'plan_store_failed', message: 'The plan could not be updated.');
		}

		return null;
	}//end markUsed()

	/**
	 * Why this user may not use the plan, or null when they may: it must be approved, untampered and theirs.
	 *
	 * @param array{uuid: string, data: array<string, mixed>}|null $plan   The plan, null when there is none.
	 * @param string                                              $userId The calling user.
	 *
	 * @return array<string, mixed>|null
	 */
	private function useRefusal(?array $plan, string $userId): ?array {
		if ($plan === null) {
			return self::error(error: 'plan_required', message: 'No approved plan with this planId.');
		}

		if ($this->isApproved(plan: $plan) === false) {
			return self::error(error: 'plan_not_approved', message: 'This plan is not approved. Ask an owner or editor of the app to approve it in Buildiq.');
		}

		if (self::isSubmitter(plan: $plan, userId: $userId) === false) {
			return self::error(error: 'plan_required', message: 'This plan was submitted by another user.');
		}

		return null;
	}//end useRefusal()

	/**
	 * Whether this user, a signed-in one, submitted the plan.
	 *
	 * @param array<string,mixed> $plan   The plan: uuid and data.
	 * @param string              $userId The calling user; '' when nobody is signed in.
	 *
	 * @return bool
	 */
	private static function isSubmitter(array $plan, string $userId): bool {
		return $userId !== '' && ($plan['data']['submittedBy'] ?? null) === $userId;
	}//end isSubmitter()

	/**
	 * Whether a plan is approved and its approval signature matches its content.
	 *
	 * @param array<string,mixed> $plan The plan: uuid and data.
	 *
	 * @return bool
	 */
	private function isApproved(array $plan): bool {
		$data = $plan['data'];
		if (($data['status'] ?? '') !== self::STATUS_APPROVED) {
			return false;
		}

		return hash_equals($this->signature(uuid: $plan['uuid'], data: $data), (string)($data['approval'] ?? ''));
	}//end isApproved()

	/**
	 * The approval signature over the plan id, its app, approver and steps.
	 *
	 * @param string              $uuid The plan uuid.
	 * @param array<string,mixed> $data The plan.
	 *
	 * @return string
	 */
	private function signature(string $uuid, array $data): string {
		$message = implode("\n", [
			'buildiq-agent-plan-approval',
			$uuid,
			(string)($data['appSlug'] ?? ''),
			(string)($data['submittedBy'] ?? ''),
			(string)($data['decidedBy'] ?? ''),
			AgentPlanSteps::canonical(value: (array)($data['steps'] ?? [])),
		]);

		return bin2hex($this->crypto->calculateHMAC(message: $message));
	}//end signature()

	/**
	 * Whether the caller holds a write role on the app, or may create it.
	 *
	 * @param string $appSlug The app slug.
	 * @param IUser  $caller  The caller.
	 *
	 * @return bool
	 */
	private function mayDecide(string $appSlug, IUser $caller): bool {
		try {
			$apps = $this->objectService->searchObjectsBySlug(self::REGISTER_SLUG, self::APP_SCHEMA, ['slug' => $appSlug], _rbac: true, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: resolving the app of an agent plan failed: ' . $e->getMessage());
			return false;
		}

		if (is_array($apps) === false || $apps === []) {
			// The plan creates its app: only someone who may create apps decides.
			return $this->permissionResolver->isAdmin(caller: $caller);
		}

		$app = AgentPlanStore::asArray(item: $apps[0]);
		// Organisation scope before any role: another organisation's scoped app
		// answers as one this caller cannot decide for (REQ-BQOS-002).
		if ($this->visibleToOrganisation(application: $app, caller: $caller) === false) {
			return false;
		}

		$permissions = ($app['permissions'] ?? []);
		if (is_array($permissions) === false) {
			return false;
		}

		return $this->permissionResolver->matchesCaller(
			permissions: $permissions,
			caller: $caller,
			userGroups: $this->permissionResolver->resolveUserGroups(user: $caller),
			allowAdminBypass: false,
			roles: self::WRITE_ROLES
		);
	}//end mayDecide()

	/**
	 * Whether the app is shared, or the caller belongs to the organisation that holds it.
	 *
	 * @param array<string, mixed> $application The Application.
	 * @param IUser                $caller      The caller.
	 *
	 * @return bool
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AppOrganisationGuard::isShared() is a pure scope check with no collaborators.
	 *
	 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
	 */
	private function visibleToOrganisation(array $application, IUser $caller): bool {
		if (AppOrganisationGuard::isShared(application: $application) === true) {
			return true;
		}

		return $this->organisationGuard?->canSee(application: $application, user: $caller) === true;
	}//end visibleToOrganisation()

	/**
	 * An error result in the builder tools' envelope.
	 *
	 * @param string $error   The error code.
	 * @param string $message The message.
	 *
	 * @return array{isError:true,error:string,message:string}
	 */
	private static function error(string $error, string $message): array {
		return ['isError' => true, 'error' => $error, 'message' => $message];
	}//end error()
}//end class
