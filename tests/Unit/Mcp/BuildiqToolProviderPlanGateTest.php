<?php

/**
 * The approved-plan gate on BuildiqToolProvider's MCP entry.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Mcp
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

namespace OCA\Buildiq\Tests\Unit\Mcp;

use OCA\Buildiq\Mcp\BuildiqToolProvider;
use OCA\Buildiq\Service\AgentPlanService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins that every write tool called over MCP is refused, and writes nothing,
 * without a matching approved plan; that a call without planId is submitted
 * as a one-step plan instead; and that an authorised step reaches the
 * Copilot's own handler with planId removed.
 *
 * @covers \OCA\Buildiq\Mcp\BuildiqToolProvider
 * @uses   \OCA\Buildiq\Service\AgentPlanService
 * @uses   \OCA\Buildiq\Mcp\Handler\AbstractToolHandler
 * @uses   \OCA\Buildiq\Mcp\Handler\UpsertPageHandler
 * @uses   \OCA\Buildiq\Mcp\Handler\ListAppsHandler
 */
class BuildiqToolProviderPlanGateTest extends TestCase {

	/**
	 * One valid-looking call per write tool.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:string}>
	 */
	public static function writeCalls(): array {
		return [
			'createApp' => ['buildiq.createApp', ['slug' => 'my-app', 'name' => 'My app'], 'my-app'],
			'promoteVersion' => ['buildiq.promoteVersion', ['appSlug' => 'my-app', 'sourceVersionSlug' => 'development'], 'my-app'],
			'upsertSchema' => ['buildiq.upsertSchema', ['appSlug' => 'my-app', 'slug' => 'loan', 'title' => 'Loan', 'properties' => ['a' => []]], 'my-app'],
			'upsertPage' => ['buildiq.upsertPage', ['appSlug' => 'my-app', 'pageId' => 'p', 'title' => 'P', 'type' => 'dashboard', 'route' => '/p'], 'my-app'],
			'addWidget' => ['buildiq.addWidget', ['appSlug' => 'my-app', 'pageId' => 'p', 'widgetType' => 'text'], 'my-app'],
			'upsertMenuItem' => ['buildiq.upsertMenuItem', ['appSlug' => 'my-app', 'id' => 'm', 'label' => 'M', 'route' => 'p'], 'my-app'],
		];
	}//end writeCalls()

	/**
	 * Without the plan store every write is refused and no handler is built.
	 *
	 * @dataProvider writeCalls
	 *
	 * @param string              $tool      The tool id.
	 * @param array<string,mixed> $arguments The call.
	 *
	 * @return void
	 */
	public function testWithoutAPlanStoreEveryWriteIsRefused(string $tool, array $arguments): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects(self::never())->method(self::anything());

		$result = $this->provider(objects: $objects, plans: null)->invokeTool($tool, $arguments + ['planId' => 'any']);

		self::assertTrue($result['isError']);
		self::assertSame('plan_required', $result['error']);
	}//end testWithoutAPlanStoreEveryWriteIsRefused()

	/**
	 * A write without planId is submitted as a one-step plan and nothing is written.
	 *
	 * @dataProvider writeCalls
	 *
	 * @param string              $tool      The tool id.
	 * @param array<string,mixed> $arguments The call.
	 * @param string              $appSlug   The app it targets.
	 *
	 * @return void
	 */
	public function testAWriteWithoutPlanIdBecomesAPendingPlan(string $tool, array $arguments, string $appSlug): void {
		$plans = $this->createMock(AgentPlanService::class);
		$plans->expects(self::once())->method('submit')
			->with($appSlug, [['tool' => $tool, 'arguments' => $arguments]], 'alice')
			->willReturn(['planId' => 'plan-1', 'status' => 'pending']);
		$plans->expects(self::never())->method('authorizeStep');
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects(self::never())->method(self::anything());

		$result = $this->provider(objects: $objects, plans: $plans)->invokeTool($tool, $arguments);

		self::assertSame('plan_required', $result['error']);
		self::assertSame('plan-1', $result['planId']);
		self::assertStringContainsString('Nothing was written', $result['message']);
	}//end testAWriteWithoutPlanIdBecomesAPendingPlan()

	/**
	 * A refused step check is returned as is and no handler runs.
	 *
	 * @dataProvider writeCalls
	 *
	 * @param string              $tool      The tool id.
	 * @param array<string,mixed> $arguments The call.
	 *
	 * @return void
	 */
	public function testARefusedStepWritesNothing(string $tool, array $arguments): void {
		$refusal = ['isError' => true, 'error' => 'plan_step_mismatch', 'message' => 'no'];
		$plans = $this->createMock(AgentPlanService::class);
		$plans->expects(self::once())->method('authorizeStep')
			->with('plan-1', $tool, $arguments, 'alice')
			->willReturn($refusal);
		$plans->expects(self::never())->method('submit');
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects(self::never())->method(self::anything());

		self::assertSame($refusal, $this->provider(objects: $objects, plans: $plans)->invokeTool($tool, $arguments + ['planId' => 'plan-1']));
	}//end testARefusedStepWritesNothing()

	/**
	 * An authorised step runs the Copilot's handler: its route guard still refuses a javascript: route.
	 *
	 * @return void
	 */
	public function testAnAuthorisedStepRunsTheSameHandler(): void {
		$arguments = ['appSlug' => 'my-app', 'pageId' => 'p', 'title' => 'P', 'type' => 'dashboard', 'route' => 'javascript:alert(1)'];
		$plans = $this->createMock(AgentPlanService::class);
		$plans->expects(self::once())->method('authorizeStep')->with('plan-1', 'buildiq.upsertPage', $arguments, 'alice')->willReturn(null);

		$result = $this->provider(objects: $this->createMock(ObjectServiceInterface::class), plans: $plans)
			->invokeTool('buildiq.upsertPage', $arguments + ['planId' => 'plan-1']);

		$copilot = $this->provider(objects: $this->createMock(ObjectServiceInterface::class), plans: null)
			->invokeApprovedTool('buildiq.upsertPage', $arguments);

		self::assertTrue($result['isError']);
		self::assertStringContainsString('route', strtolower((string)$result['message']));
		self::assertSame($copilot, $result);
	}//end testAnAuthorisedStepRunsTheSameHandler()

	/**
	 * Reads never touch the plan store.
	 *
	 * @return void
	 */
	public function testReadsNeedNoPlan(): void {
		$plans = $this->createMock(AgentPlanService::class);
		$plans->expects(self::never())->method(self::anything());

		$result = $this->provider(objects: $this->createMock(ObjectServiceInterface::class), plans: $plans)
			->invokeTool('buildiq.listApps', ['limit' => 999]);

		self::assertNotSame('plan_required', ($result['error'] ?? null));
	}//end testReadsNeedNoPlan()

	/**
	 * The provider acting as alice.
	 *
	 * @param ObjectServiceInterface $objects The object service.
	 * @param AgentPlanService|null  $plans   The plan store.
	 *
	 * @return BuildiqToolProvider
	 */
	private function provider(ObjectServiceInterface $objects, ?AgentPlanService $plans): BuildiqToolProvider {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new BuildiqToolProvider(
			userSession: $session,
			groupManager: $this->createMock(IGroupManager::class),
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class),
			objectService: $objects,
			planService: $plans
		);
	}//end provider()
}//end class
