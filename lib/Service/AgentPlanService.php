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
use OCA\OpenRegister\Contract\ObjectEntityInterface;
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
 * @spec openspec/changes/openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
 */
class AgentPlanService {

	/**
	 * The builder tools that write. Every other builder tool only reads.
	 */
	public const WRITE_TOOLS = [
		'buildiq.createApp',
		'buildiq.promoteVersion',
		'buildiq.upsertSchema',
		'buildiq.upsertPage',
		'buildiq.addWidget',
		'buildiq.upsertMenuItem',
	];

	public const STATUS_PENDING = 'pending';

	public const STATUS_APPROVED = 'approved';

	public const STATUS_REJECTED = 'rejected';

	/**
	 * The most steps one plan may hold.
	 */
	public const MAX_STEPS = 50;

	private const REGISTER_SLUG = 'buildiq';

	private const PLAN_SCHEMA = 'agent-plan';

	private const APP_SCHEMA = 'built-app';

	private const WRITE_ROLES = ['owners', 'editors'];

	private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface      $objectService      OpenRegister's object service.
	 * @param PermissionResolverInterface $permissionResolver The app role matcher.
	 * @param ICrypto                     $crypto             Signs approvals with the instance secret.
	 * @param LoggerInterface             $logger             Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly PermissionResolverInterface $permissionResolver,
		private readonly ICrypto $crypto,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a builder tool writes.
	 *
	 * @param string $toolId The tool id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public static function isWriteTool(string $toolId): bool {
		return in_array(needle: $toolId, haystack: self::WRITE_TOOLS, strict: true);
	}//end isWriteTool()

	/**
	 * Store a pending plan for one app. Writes nothing to the app.
	 *
	 * @param string                           $appSlug The app every step targets.
	 * @param array<int,array<string,mixed>>   $steps   The `{tool, arguments}` steps.
	 * @param string                           $userId  The user the agent acts as.
	 *
	 * @return array<string,mixed> `{planId, status, appSlug, steps}` or `{isError, error, message}`.
	 *
	 * @spec openspec/changes/openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public function submit(string $appSlug, array $steps, string $userId): array {
		if ($userId === '') {
			return self::error(error: 'forbidden', message: 'You must be signed in.');
		}

		$invalid = self::stepsError(appSlug: $appSlug, steps: $steps);
		if ($invalid !== null) {
			return self::error(error: 'plan_invalid', message: $invalid);
		}

		$normalised = [];
		foreach ($steps as $step) {
			$normalised[] = ['tool' => (string)$step['tool'], 'arguments' => self::withoutPlanId(arguments: (array)$step['arguments'])];
		}

		try {
			$saved = $this->objectService->saveObject(
				object: [
					'appSlug' => $appSlug,
					'steps' => $normalised,
					'status' => self::STATUS_PENDING,
					'submittedBy' => $userId,
					'usedSteps' => [],
				],
				register: self::REGISTER_SLUG,
				schema: self::PLAN_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: storing an agent plan failed: ' . $e->getMessage());
			return self::error(error: 'plan_store_failed', message: 'The plan could not be stored.');
		}

		return [
			'planId' => (string)$saved->getUuid(),
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
	 * @spec openspec/changes/openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public function decide(string $planId, bool $approve, IUser $caller): array {
		$plan = $this->loadPlan(planId: $planId);
		if ($plan === null) {
			return self::error(error: 'not_found', message: 'No such plan.');
		}

		if (($plan['data']['status'] ?? '') !== self::STATUS_PENDING) {
			return self::error(error: 'plan_decided', message: 'This plan was already approved or rejected.');
		}

		if ($this->mayDecide(appSlug: (string)($plan['data']['appSlug'] ?? ''), caller: $caller) === false) {
			return self::error(error: 'forbidden', message: 'Only an owner or editor of this app can approve or reject its plans.');
		}

		$data = $plan['data'];
		$data['status'] = self::STATUS_REJECTED;
		if ($approve === true) {
			$data['status'] = self::STATUS_APPROVED;
		}
		$data['decidedBy'] = $caller->getUID();
		$data['approval'] = '';
		if ($approve === true) {
			$data['approval'] = $this->signature(uuid: $plan['uuid'], data: $data);
		}

		if ($this->savePlan(data: $data, uuid: $plan['uuid']) === false) {
			return self::error(error: 'plan_store_failed', message: 'The plan could not be updated.');
		}

		return ['planId' => $plan['uuid'], 'status' => $data['status']];
	}//end decide()

	/**
	 * A plan, for a person with a write role on its app.
	 *
	 * @param string $planId The plan uuid.
	 * @param IUser  $caller The person reading it.
	 *
	 * @return array<string,mixed> The plan's fields or `{isError, error, message}`.
	 *
	 * @spec openspec/changes/openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public function show(string $planId, IUser $caller): array {
		$plan = $this->loadPlan(planId: $planId);
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
	 * @spec openspec/changes/openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public function authorizeStep(string $planId, string $toolId, array $arguments, string $userId): ?array {
		$plan = $this->loadPlan(planId: $planId);
		if ($plan === null) {
			return self::error(error: 'plan_required', message: 'No approved plan with this planId.');
		}

		$data = $plan['data'];
		if (($data['status'] ?? '') !== self::STATUS_APPROVED
			|| hash_equals($this->signature(uuid: $plan['uuid'], data: $data), (string)($data['approval'] ?? '')) === false
		) {
			return self::error(error: 'plan_not_approved', message: 'This plan is not approved. Ask an owner or editor of the app to approve it in Buildiq.');
		}

		if ($userId === '' || ($data['submittedBy'] ?? null) !== $userId) {
			return self::error(error: 'plan_required', message: 'This plan was submitted by another user.');
		}

		$index = $this->matchingStep(data: $data, toolId: $toolId, arguments: $arguments);
		if ($index === null) {
			return self::error(error: 'plan_step_mismatch', message: 'This call does not match an unused step of the approved plan.');
		}

		$data['usedSteps'] = array_values(array_merge((array)($data['usedSteps'] ?? []), [$index]));
		if ($this->savePlan(data: $data, uuid: $plan['uuid']) === false) {
			return self::error(error: 'plan_store_failed', message: 'The plan could not be updated.');
		}

		return null;
	}//end authorizeStep()

	/**
	 * The arguments without the `planId` an MCP call carries.
	 *
	 * @param array<string,mixed> $arguments The call's arguments.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public static function withoutPlanId(array $arguments): array {
		unset($arguments['planId']);
		return $arguments;
	}//end withoutPlanId()

	/**
	 * Why a step list is not a valid plan for the app, or null when it is.
	 *
	 * @param string $appSlug The app every step targets.
	 * @param array  $steps   The steps.
	 *
	 * @return string|null
	 */
	private static function stepsError(string $appSlug, array $steps): ?string {
		if (preg_match(self::SLUG_PATTERN, $appSlug) !== 1) {
			return 'appSlug must be a kebab-case app slug.';
		}

		if ($steps === [] || count($steps) > self::MAX_STEPS || array_is_list($steps) === false) {
			return 'A plan holds 1 to ' . self::MAX_STEPS . ' steps.';
		}

		foreach ($steps as $index => $step) {
			if (is_array($step) === false) {
				$step = [];
			}

			$tool = ($step['tool'] ?? null);
			$arguments = ($step['arguments'] ?? null);
			if (is_string($tool) === false || self::isWriteTool(toolId: $tool) === false || is_array($arguments) === false) {
				return 'Step ' . $index . ' must be {tool, arguments} with tool one of: ' . implode(', ', self::WRITE_TOOLS) . '.';
			}

			if (self::targetApp(toolId: $tool, arguments: $arguments) !== $appSlug) {
				return 'Step ' . $index . ' targets another app than ' . $appSlug . '.';
			}
		}

		return null;
	}//end stepsError()

	/**
	 * The app a write call targets: createApp names it `slug`, the others `appSlug`.
	 *
	 * @param string              $toolId    The tool id.
	 * @param array<string,mixed> $arguments The call's arguments.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public static function targetApp(string $toolId, array $arguments): string {
		$key = 'appSlug';
		if ($toolId === 'buildiq.createApp') {
			$key = 'slug';
		}

		return (string)($arguments[$key] ?? '');
	}//end targetApp()

	/**
	 * The index of the first unused step equal to this call, or null.
	 *
	 * @param array<string,mixed> $data      The plan.
	 * @param string              $toolId    The tool called.
	 * @param array<string,mixed> $arguments The call's arguments.
	 *
	 * @return int|null
	 */
	private function matchingStep(array $data, string $toolId, array $arguments): ?int {
		$used = array_map('intval', (array)($data['usedSteps'] ?? []));
		$wanted = self::canonical(value: self::withoutPlanId(arguments: $arguments));
		foreach ((array)($data['steps'] ?? []) as $index => $step) {
			if (in_array((int)$index, $used, true) === true || is_array($step) === false) {
				continue;
			}

			if (($step['tool'] ?? null) === $toolId
				&& self::canonical(value: (array)($step['arguments'] ?? [])) === $wanted
			) {
				return (int)$index;
			}
		}

		return null;
	}//end matchingStep()

	/**
	 * A key-order independent encoding of a value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function canonical(mixed $value): string {
		return (string)json_encode(self::sorted(value: $value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}//end canonical()

	/**
	 * The value with every object's keys sorted, lists kept in order.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private static function sorted(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$out = [];
		foreach ($value as $key => $item) {
			$out[$key] = self::sorted(value: $item);
		}

		if (array_is_list($out) === false) {
			ksort($out, SORT_STRING);
		}

		return $out;
	}//end sorted()

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
			self::canonical(value: (array)($data['steps'] ?? [])),
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

		$app = self::toArray(item: $apps[0]);
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
	 * Load a plan by uuid.
	 *
	 * @param string $planId The plan uuid.
	 *
	 * @return array{uuid:string,data:array<string,mixed>}|null
	 */
	private function loadPlan(string $planId): ?array {
		if ($planId === '') {
			return null;
		}

		try {
			$entity = $this->objectService->find(
				id: $planId,
				register: self::REGISTER_SLUG,
				schema: self::PLAN_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		$data = self::toArray(item: $entity);
		$data = array_intersect_key($data, array_flip(['appSlug', 'steps', 'status', 'submittedBy', 'decidedBy', 'usedSteps', 'approval']));
		return ['uuid' => (string)$entity->getUuid(), 'data' => $data];
	}//end loadPlan()

	/**
	 * Save a plan's fields back.
	 *
	 * @param array<string,mixed> $data The plan fields.
	 * @param string              $uuid The plan uuid.
	 *
	 * @return bool Whether it was saved.
	 */
	private function savePlan(array $data, string $uuid): bool {
		try {
			$this->objectService->saveObject(
				object: $data,
				register: self::REGISTER_SLUG,
				schema: self::PLAN_SCHEMA,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: updating an agent plan failed: ' . $e->getMessage());
			return false;
		}

		return true;
	}//end savePlan()

	/**
	 * An object as an array.
	 *
	 * @param mixed $item An object entity or an array.
	 *
	 * @return array<string,mixed>
	 */
	private static function toArray(mixed $item): array {
		if ($item instanceof ObjectEntityInterface) {
			return (array)$item->getObject();
		}

		if (is_array($item) === true) {
			return $item;
		}

		return [];
	}//end toArray()

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
