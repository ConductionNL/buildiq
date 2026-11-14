<?php

/**
 * Tests for AgentPlanService: submit, decide and the per-call step check.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
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

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Kernel\Contracts\PermissionResolverInterface;
use OCA\Buildiq\Service\AgentPlanService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IUser;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins that a plan writes nothing to the app, that only an owner or editor
 * (not an admin by bypass) decides it, and that a write call runs only for an
 * approved, correctly signed plan of the same user, matching an unused step.
 *
 * @covers \OCA\Buildiq\Service\AgentPlanService
 */
class AgentPlanServiceTest extends TestCase {

	private const PLAN_ID = '11111111-2222-3333-4444-555555555555';

	/**
	 * The stored plan, as the object service holds it (null = none).
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $stored = null;

	/**
	 * Every saveObject() call: [object, register, schema, uuid].
	 *
	 * @var array<int,array{0:array,1:mixed,2:mixed,3:mixed}>
	 */
	private array $saves = [];

	/**
	 * What searchObjectsBySlug returns for the app.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $apps = [];

	/**
	 * The role matcher double.
	 *
	 * @var PermissionResolverInterface&MockObject
	 */
	private PermissionResolverInterface&MockObject $resolver;

	/**
	 * Fresh state per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->stored = null;
		$this->saves = [];
		$this->apps = [['slug' => 'my-app', 'permissions' => ['owners' => ['user:alice'], 'viewers' => ['user:victor']]]];
		$this->resolver = $this->createMock(PermissionResolverInterface::class);
		$this->resolver->method('resolveUserGroups')->willReturn([]);
		$this->resolver->method('matchesCaller')->willReturnCallback(
			static function (array $permissions, IUser $caller, array $userGroups, ?bool $allowAdminBypass, array $roles): bool {
				self::assertFalse($allowAdminBypass);
				self::assertSame(['owners', 'editors'], $roles);
				foreach ($roles as $role) {
					if (in_array('user:' . $caller->getUID(), (array)($permissions[$role] ?? []), true) === true) {
						return true;
					}
				}

				return false;
			}
		);
		$this->resolver->method('isAdmin')->willReturnCallback(static fn (IUser $caller): bool => $caller->getUID() === 'root');
	}//end setUp()

	/**
	 * A valid plan is stored pending, with planId stripped from its steps.
	 *
	 * @return void
	 */
	public function testSubmitStoresAPendingPlanAndWritesNothingElse(): void {
		$result = $this->service()->submit(
			appSlug: 'my-app',
			steps: [['tool' => 'buildiq.upsertPage', 'arguments' => ['appSlug' => 'my-app', 'pageId' => 'overview', 'planId' => 'x']]],
			userId: 'alice'
		);

		self::assertSame(self::PLAN_ID, $result['planId']);
		self::assertSame('pending', $result['status']);
		self::assertCount(1, $this->saves);
		[$object, $register, $schema, $uuid] = $this->saves[0];
		self::assertSame(['buildiq', 'agent-plan', null], [$register, $schema, $uuid]);
		self::assertSame(
			[
				'appSlug' => 'my-app',
				'steps' => [['tool' => 'buildiq.upsertPage', 'arguments' => ['appSlug' => 'my-app', 'pageId' => 'overview']]],
				'status' => 'pending',
				'submittedBy' => 'alice',
				'usedSteps' => [],
			],
			$object
		);
	}//end testSubmitStoresAPendingPlanAndWritesNothingElse()

	/**
	 * Malformed plans are refused and nothing is stored.
	 *
	 * @return void
	 */
	public function testSubmitRefusesMalformedPlans(): void {
		$page = ['tool' => 'buildiq.upsertPage', 'arguments' => ['appSlug' => 'my-app']];
		$cases = [
			'bad slug' => ['My App', [$page], 'alice', 'plan_invalid'],
			'no steps' => ['my-app', [], 'alice', 'plan_invalid'],
			'too many steps' => ['my-app', array_fill(0, 51, $page), 'alice', 'plan_invalid'],
			'a read tool' => ['my-app', [['tool' => 'buildiq.listApps', 'arguments' => []]], 'alice', 'plan_invalid'],
			'unknown tool' => ['my-app', [['tool' => 'buildiq.dropApp', 'arguments' => []]], 'alice', 'plan_invalid'],
			'arguments not an array' => ['my-app', [['tool' => 'buildiq.upsertPage', 'arguments' => 'x']], 'alice', 'plan_invalid'],
			'step for another app' => ['my-app', [['tool' => 'buildiq.upsertPage', 'arguments' => ['appSlug' => 'other']]], 'alice', 'plan_invalid'],
			'createApp of another slug' => ['my-app', [['tool' => 'buildiq.createApp', 'arguments' => ['slug' => 'other']]], 'alice', 'plan_invalid'],
			'keyed steps' => ['my-app', ['a' => $page], 'alice', 'plan_invalid'],
			'anonymous' => ['my-app', [$page], '', 'forbidden'],
		];
		$service = $this->service();
		foreach ($cases as $name => [$slug, $steps, $user, $error]) {
			$result = $service->submit(appSlug: $slug, steps: $steps, userId: $user);
			self::assertTrue($result['isError'], $name);
			self::assertSame($error, $result['error'], $name);
		}

		self::assertSame([], $this->saves);
	}//end testSubmitRefusesMalformedPlans()

	/**
	 * An owner approves: status, approver and a signature are saved.
	 *
	 * @return void
	 */
	public function testAnOwnerApprovesAndTheApprovalIsSigned(): void {
		$this->stored = $this->plan(status: 'pending');

		$result = $this->service()->decide(planId: self::PLAN_ID, approve: true, caller: $this->user(uid: 'alice'));

		self::assertSame(['planId' => self::PLAN_ID, 'status' => 'approved'], $result);
		[$object, , , $uuid] = $this->saves[0];
		self::assertSame(self::PLAN_ID, $uuid);
		self::assertSame('approved', $object['status']);
		self::assertSame('alice', $object['decidedBy']);
		self::assertSame(64, strlen($object['approval']));
	}//end testAnOwnerApprovesAndTheApprovalIsSigned()

	/**
	 * A rejection carries no signature, and a decided plan cannot be decided again.
	 *
	 * @return void
	 */
	public function testARejectionIsUnsignedAndFinal(): void {
		$this->stored = $this->plan(status: 'pending');
		$service = $this->service();

		self::assertSame('rejected', $service->decide(planId: self::PLAN_ID, approve: false, caller: $this->user(uid: 'alice'))['status']);
		self::assertSame('', $this->saves[0][0]['approval']);

		$this->stored = $this->saves[0][0];
		$again = $service->decide(planId: self::PLAN_ID, approve: true, caller: $this->user(uid: 'alice'));
		self::assertSame('plan_decided', $again['error']);
		self::assertCount(1, $this->saves);
	}//end testARejectionIsUnsignedAndFinal()

	/**
	 * A viewer, an outsider and an admin without a role cannot decide; a missing plan is not found.
	 *
	 * @return void
	 */
	public function testOnlyAnOwnerOrEditorDecides(): void {
		$this->stored = $this->plan(status: 'pending');
		$service = $this->service();
		foreach (['victor', 'eve', 'root'] as $uid) {
			self::assertSame('forbidden', $service->decide(planId: self::PLAN_ID, approve: true, caller: $this->user(uid: $uid))['error'], $uid);
			self::assertSame('forbidden', $service->show(planId: self::PLAN_ID, caller: $this->user(uid: $uid))['error'], $uid);
		}

		self::assertSame([], $this->saves);
		$this->stored = null;
		self::assertSame('not_found', $service->decide(planId: self::PLAN_ID, approve: true, caller: $this->user(uid: 'alice'))['error']);
	}//end testOnlyAnOwnerOrEditorDecides()

	/**
	 * A plan that creates its app is decided by an admin only.
	 *
	 * @return void
	 */
	public function testAPlanForAMissingAppNeedsAnAdmin(): void {
		$this->apps = [];
		$this->stored = $this->plan(status: 'pending');
		$service = $this->service();

		self::assertSame('forbidden', $service->decide(planId: self::PLAN_ID, approve: true, caller: $this->user(uid: 'alice'))['error']);
		self::assertSame('approved', $service->decide(planId: self::PLAN_ID, approve: true, caller: $this->user(uid: 'root'))['status']);
	}//end testAPlanForAMissingAppNeedsAnAdmin()

	/**
	 * show() returns the plan without its signature.
	 *
	 * @return void
	 */
	public function testShowLeavesOutTheSignature(): void {
		$this->stored = $this->plan(status: 'approved') + ['approval' => 'sig'];

		$shown = $this->service()->show(planId: self::PLAN_ID, caller: $this->user(uid: 'alice'));

		self::assertSame(self::PLAN_ID, $shown['planId']);
		self::assertSame('approved', $shown['status']);
		self::assertArrayNotHasKey('approval', $shown);
	}//end testShowLeavesOutTheSignature()

	/**
	 * An approved step runs once, in any key order, for the submitter only.
	 *
	 * @return void
	 */
	public function testAnApprovedStepRunsOnceForTheSubmitter(): void {
		$this->stored = $this->approvedPlan();
		$service = $this->service();
		$call = ['pageId' => 'overview', 'title' => 'Overview', 'appSlug' => 'my-app', 'planId' => self::PLAN_ID];

		self::assertSame('plan_required', $service->authorizeStep(planId: self::PLAN_ID, toolId: 'buildiq.upsertPage', arguments: $call, userId: 'mallory')['error']);
		self::assertNull($service->authorizeStep(planId: self::PLAN_ID, toolId: 'buildiq.upsertPage', arguments: $call, userId: 'alice'));
		self::assertSame([0], $this->saves[0][0]['usedSteps']);

		$this->stored = $this->saves[0][0];
		$replay = $service->authorizeStep(planId: self::PLAN_ID, toolId: 'buildiq.upsertPage', arguments: $call, userId: 'alice');
		self::assertSame('plan_step_mismatch', $replay['error']);
	}//end testAnApprovedStepRunsOnceForTheSubmitter()

	/**
	 * A call that differs from the approved step, or uses another tool, is refused.
	 *
	 * @return void
	 */
	public function testACallThatDiffersFromTheStepIsRefused(): void {
		$this->stored = $this->approvedPlan();
		$service = $this->service();
		$cases = [
			'other page' => ['buildiq.upsertPage', ['appSlug' => 'my-app', 'pageId' => 'admin', 'title' => 'Overview']],
			'extra argument' => ['buildiq.upsertPage', ['appSlug' => 'my-app', 'pageId' => 'overview', 'title' => 'Overview', 'route' => '/x']],
			'other tool' => ['buildiq.upsertMenuItem', ['appSlug' => 'my-app', 'pageId' => 'overview', 'title' => 'Overview']],
		];
		foreach ($cases as $name => [$tool, $arguments]) {
			self::assertSame('plan_step_mismatch', $service->authorizeStep(planId: self::PLAN_ID, toolId: $tool, arguments: $arguments, userId: 'alice')['error'], $name);
		}

		self::assertSame([], $this->saves);
	}//end testACallThatDiffersFromTheStepIsRefused()

	/**
	 * Pending, rejected, unknown and forged-approved plans let nothing through.
	 *
	 * @return void
	 */
	public function testOnlyASignedApprovalLetsAStepThrough(): void {
		$call = ['appSlug' => 'my-app', 'pageId' => 'overview', 'title' => 'Overview'];
		$service = $this->service();
		$cases = [
			'pending' => [$this->plan(status: 'pending'), 'plan_not_approved'],
			'rejected' => [$this->plan(status: 'rejected'), 'plan_not_approved'],
			'approved without signature' => [$this->plan(status: 'approved') + ['decidedBy' => 'alice'], 'plan_not_approved'],
			'approved with a wrong signature' => [$this->plan(status: 'approved') + ['decidedBy' => 'alice', 'approval' => str_repeat('0', 64)], 'plan_not_approved'],
			'steps changed after approval' => [array_replace($this->approvedPlan(), ['steps' => [['tool' => 'buildiq.upsertPage', 'arguments' => $call + ['route' => '/evil']]]]), 'plan_not_approved'],
			'unknown plan' => [null, 'plan_required'],
		];
		foreach ($cases as $name => [$stored, $error]) {
			$this->stored = $stored;
			self::assertSame($error, $service->authorizeStep(planId: self::PLAN_ID, toolId: 'buildiq.upsertPage', arguments: $call, userId: 'alice')['error'], $name);
		}

		self::assertSame([], $this->saves);
	}//end testOnlyASignedApprovalLetsAStepThrough()

	/**
	 * The write tools and the planId stripping.
	 *
	 * @return void
	 */
	public function testWriteToolsAndPlanIdStripping(): void {
		self::assertTrue(AgentPlanService::isWriteTool(toolId: 'buildiq.promoteVersion'));
		self::assertFalse(AgentPlanService::isWriteTool(toolId: 'buildiq.getAppManifest'));
		self::assertSame(['a' => 1], AgentPlanService::withoutPlanId(arguments: ['a' => 1, 'planId' => 'p']));
	}//end testWriteToolsAndPlanIdStripping()

	/**
	 * A plan in a given status with one upsertPage step.
	 *
	 * @param string $status The status.
	 *
	 * @return array<string,mixed>
	 */
	private function plan(string $status): array {
		return [
			'appSlug' => 'my-app',
			'steps' => [['tool' => 'buildiq.upsertPage', 'arguments' => ['appSlug' => 'my-app', 'pageId' => 'overview', 'title' => 'Overview']]],
			'status' => $status,
			'submittedBy' => 'alice',
			'usedSteps' => [],
		];
	}//end plan()

	/**
	 * A plan approved through decide(), as stored.
	 *
	 * @return array<string,mixed>
	 */
	private function approvedPlan(): array {
		$this->stored = $this->plan(status: 'pending');
		$this->service()->decide(planId: self::PLAN_ID, approve: true, caller: $this->user(uid: 'alice'));
		$approved = $this->saves[0][0];
		$this->saves = [];
		return $approved;
	}//end approvedPlan()

	/**
	 * A user double.
	 *
	 * @param string $uid The UID.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * The service over recording doubles.
	 *
	 * @return AgentPlanService
	 */
	private function service(): AgentPlanService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntityInterface {
				$this->saves[] = [$object, $register, $schema, $uuid];
				return $this->entity(data: $object);
			}
		);
		$objects->method('find')->willReturnCallback(
			fn (): ?ObjectEntityInterface => $this->stored === null ? null : $this->entity(data: $this->stored + ['@self' => ['owner' => 'alice']])
		);
		$objects->method('searchObjectsBySlug')->willReturnCallback(fn (): array => $this->apps);

		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback(static fn (string $message): string => hash_hmac('sha256', $message, 'secret', true));

		return new AgentPlanService(objectService: $objects, permissionResolver: $this->resolver, crypto: $crypto, logger: new NullLogger());
	}//end service()

	/**
	 * An object entity double.
	 *
	 * @param array<string,mixed> $data The object.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn(self::PLAN_ID);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()
}//end class
