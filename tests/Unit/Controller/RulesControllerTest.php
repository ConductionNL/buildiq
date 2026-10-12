<?php

/**
 * Unit tests for RulesController.
 *
 * Covers REQ-BRE-006 / REQ-BRE-004: evaluate returns 200 with the result,
 * 404 on an unknown RuleSet, 401 when unauthenticated, and the NoAdminRequired
 * posture (non-admin authenticated users may evaluate).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\RulesController;
use OCA\Buildiq\Service\RuleEngineService;
use OCA\Buildiq\Service\RuleObjectReader;
use OCA\Buildiq\Service\RuleSetVersioningService;
use OCA\Buildiq\Service\DecisionTableEvaluator;
use OCA\Buildiq\Service\DecisionTableShape;
use OCA\Buildiq\Service\ExpressionEvaluator;
use OCA\Buildiq\Service\StoredDecisionTables;
use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator as SharedEvaluator;
use OCA\OpenRegister\Service\Dmn\DecisionTableStore;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use UnexpectedValueException;

/**
 * Tests for {@see RulesController}.
 */
final class RulesControllerTest extends TestCase {

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * @var RuleEngineService&MockObject
	 */
	private RuleEngineService&MockObject $ruleEngine;

	/**
	 * @var RuleSetVersioningService&MockObject
	 */
	private RuleSetVersioningService&MockObject $versioningService;

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * OpenRegister's decision-table store (decision 196).
	 *
	 * @var DecisionTableStore&MockObject
	 */
	private DecisionTableStore&MockObject $store;

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Build mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->ruleEngine = $this->createMock(RuleEngineService::class);
		$this->versioningService = $this->createMock(RuleSetVersioningService::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->store = $this->createMock(DecisionTableStore::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

	}//end setUp()

	/**
	 * Construct the controller under test.
	 *
	 * @return RulesController
	 */
	private function controller(): RulesController {
		$register = new Register();
		$register->setId(7);
		$register->setSchemas([101, 105]);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			static function (string|int $id, bool $_rbac = true, bool $_multitenancy = true) use ($register): Register {
				if ($_multitenancy === true) {
					throw new DoesNotExistException('Register not found in the caller organisation: ' . $id);
				}

				return $register;
			}
		);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findIdsBySlugs')->willReturnCallback(
			static fn (array $slugs): array => [strtolower($slugs[0]) => [($slugs[0] === 'rule-set' ? '101' : '105')]]
		);

		return new RulesController(
			$this->request,
			$this->createMock(LoggerInterface::class),
			$this->ruleEngine,
			$this->versioningService,
			new RuleObjectReader($this->objectService, $registerMapper, $schemaMapper, $this->createMock(LoggerInterface::class)),
			$this->userSession,
			new StoredDecisionTables(
				$this->store,
				$this->objectService,
				new DecisionTableShape(new DecisionTableEvaluator(new ExpressionEvaluator(), $this->createMock(SharedEvaluator::class))),
				$this->createMock(LoggerInterface::class),
			),
			$this->groupManager,
		);

	}//end controller()

	/**
	 * Authenticate the session as a non-admin user.
	 *
	 * @return void
	 */
	private function authenticate(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$this->userSession->method('getUser')->willReturn($user);

	}//end authenticate()

	/**
	 * evaluate returns 200 and the engine outcome for an authenticated user.
	 *
	 * @return void
	 */
	public function testEvaluateOk(): void {
		$this->authenticate();
		$this->request->method('getParams')->willReturn(['payload' => ['x' => 1]]);
		$this->ruleEngine->method('evaluate')->willReturn(
			['result' => ['decision' => 'approve'], 'triggeredRules' => ['r1'], 'executionTime' => 3, 'errors' => []]
		);

		$response = $this->controller()->evaluate('loan-eligibility');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('approve', $response->getData()['result']['decision']);

	}//end testEvaluateOk()

	/**
	 * REQ-BQLV-004: `mode: preview` reaches the engine as a preview, after the
	 * same authentication and size guard; any other call is a logged one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-live-evaluation-leaves-no-log-trail-req-bqlv-004
	 */
	public function testEvaluatePreviewModeReachesTheEngine(): void {
		$this->authenticate();
		$this->request->method('getParams')->willReturn(['payload' => ['x' => 1], 'mode' => 'preview']);
		$this->ruleEngine->expects($this->once())
			->method('evaluate')
			->with('fee', ['x' => 1], null, false, true, true)
			->willReturn(['result' => ['fee' => 40], 'triggeredRules' => [], 'executionTime' => 1, 'errors' => []]);

		$response = $this->controller()->evaluate('fee');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testEvaluatePreviewModeReachesTheEngine()

	/**
	 * A call without `mode: preview` stays a logged evaluation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-live-evaluation-leaves-no-log-trail-req-bqlv-004
	 */
	public function testEvaluateWithoutPreviewIsLogged(): void {
		$this->authenticate();
		$this->request->method('getParams')->willReturn(['payload' => ['x' => 1], 'mode' => 'anything']);
		$this->ruleEngine->expects($this->once())
			->method('evaluate')
			->with('fee', ['x' => 1], null, false, true, false)
			->willReturn(['result' => [], 'triggeredRules' => [], 'executionTime' => 1, 'errors' => []]);

		$this->controller()->evaluate('fee');

	}//end testEvaluateWithoutPreviewIsLogged()

	/**
	 * evaluate returns 404 when the engine reports the RuleSet missing.
	 *
	 * @return void
	 */
	public function testEvaluateNotFound(): void {
		$this->authenticate();
		$this->request->method('getParams')->willReturn(['payload' => []]);
		$this->ruleEngine->method('evaluate')->willThrowException(new RuntimeException('missing', 404));

		$response = $this->controller()->evaluate('ghost');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testEvaluateNotFound()

	/**
	 * evaluate returns 401 when there is no authenticated user.
	 *
	 * @return void
	 */
	public function testEvaluateUnauthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$response = $this->controller()->evaluate('loan-eligibility');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testEvaluateUnauthenticated()

	/**
	 * evaluate surfaces a 408 when the engine reports a timeout.
	 *
	 * @return void
	 */
	public function testEvaluateTimeout(): void {
		$this->authenticate();
		$this->request->method('getParams')->willReturn(['payload' => []]);
		$this->ruleEngine->method('evaluate')->willReturn(
			['result' => [], 'triggeredRules' => [], 'executionTime' => 999, 'errors' => ['Evaluation exceeded the 500ms soft timeout (999ms).']]
		);

		$response = $this->controller()->evaluate('slow');
		$this->assertSame(Http::STATUS_REQUEST_TIMEOUT, $response->getStatus());

	}//end testEvaluateTimeout()

	/**
	 * The evaluate method declares #[NoAdminRequired] (ADR-005 posture).
	 *
	 * @return void
	 */
	public function testEvaluateIsNoAdminRequired(): void {
		$method = new ReflectionMethod(RulesController::class, 'evaluate');
		$attributes = $method->getAttributes(NoAdminRequired::class);
		$this->assertCount(1, $attributes);

	}//end testEvaluateIsNoAdminRequired()

	/**
	 * DoS guard: an evaluate payload larger than the maximum size is rejected
	 * with 413 before it reaches the engine or the audit log.
	 *
	 * @return void
	 */
	public function testEvaluateRejectsOversizedPayload(): void {
		$this->authenticate();
		$this->request->method('getParams')->willReturn(
			['payload' => ['blob' => str_repeat('a', 70000)]]
		);

		// The engine must never be reached for an oversized payload.
		$this->ruleEngine->expects($this->never())->method('evaluate');

		$response = $this->controller()->evaluate('loan-eligibility');
		$this->assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $response->getStatus());

	}//end testEvaluateRejectsOversizedPayload()

	/**
	 * testAll: anonymous callers are rejected before any test gate runs.
	 *
	 * Wire-contract test for the `testAll` endpoint (gate-25).
	 *
	 * @return void
	 */
	public function testTestAllRejectsAnonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->versioningService->expects($this->never())->method('runTestGate');

		$response = $this->controller()->testAll('loan-eligibility');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testTestAllRejectsAnonymous()

	/**
	 * testAll: a missing RuleSet is a 404 and the gate is never run.
	 *
	 * @return void
	 */
	public function testTestAllReturns404WhenRuleSetMissing(): void {
		$this->authenticate();
		$this->objectService->method('searchObjects')->willReturn([]);
		$this->versioningService->expects($this->never())->method('runTestGate');

		$response = $this->controller()->testAll('does-not-exist');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testTestAllReturns404WhenRuleSetMissing()

	/**
	 * testAll: an existing RuleSet runs the gate and reports the tally.
	 *
	 * @return void
	 */
	public function testTestAllRunsTheGateAndReportsTotals(): void {
		$this->authenticate();
		$this->objectService->method('searchObjects')->willReturn(
			[['id' => 'rs-1', 'slug' => 'loan-eligibility']]
		);
		$this->versioningService->expects($this->once())
			->method('runTestGate')
			->willReturn([]);

		$response = $this->controller()->testAll('loan-eligibility');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testTestAllRunsTheGateAndReportsTotals()

	/**
	 * testAll: a throwing gate is translated to 422, not a framework 500.
	 *
	 * @return void
	 */
	public function testTestAllTranslatesGateFailure(): void {
		$this->authenticate();
		$this->objectService->method('searchObjects')->willReturn(
			[['id' => 'rs-1', 'slug' => 'loan-eligibility']]
		);
		$this->versioningService->method('runTestGate')
			->willThrowException(new \RuntimeException('engine exploded'));

		$response = $this->controller()->testAll('loan-eligibility');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testTestAllTranslatesGateFailure()

	/**
	 * REQ-BQLV-002: the rule set schema lists the inputs a calculated form
	 * field reads and the outputs it can show, from the decision table.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
	 */
	public function testSchemaListsTheDecisionTableInputsAndOutputs(): void {
		$this->authenticate();
		$this->objectService->method('searchObjects')->willReturnCallback(
			static function (array $query): array {
				if ((string)$query['@self']['schema'] === '101') {
					return [['slug' => 'event-fee', 'name' => 'Event fee', 'version' => '1.0.0', 'status' => 'active', 'ruleType' => 'decision-table']];
				}

				return [];
			}
		);
		// The table is OpenRegister's row under the rule set's key (decision 196).
		$this->store->method('find')->willReturnCallback(
			static function (string $reference): array {
				if ($reference !== 'buildiq.event-fee') {
					throw new UnexpectedValueException('No decision table has the key or id "' . $reference . '".');
				}

				return [
					'key' => 'buildiq.event-fee',
					'name' => 'Event fee',
					'hitPolicy' => 'FIRST',
					'inputs' => [['name' => 'Attendees', 'type' => 'integer', 'expressionPath' => 'attendees']],
					'outputs' => [['name' => 'fee', 'type' => 'number'], ['name' => 'band', 'type' => 'string']],
					'rules' => [['id' => 'rule-0', 'inputEntries' => ['-'], 'outputEntries' => [10, 'small']]],
				];
			}
		);

		$data = $this->controller()->schema('event-fee')->getData();

		$this->assertSame([['name' => 'Attendees', 'path' => 'attendees', 'type' => 'integer']], $data['inputs']);
		$this->assertSame([['name' => 'fee', 'type' => 'number'], ['name' => 'band', 'type' => 'string']], $data['outputs']);

	}//end testSchemaListsTheDecisionTableInputsAndOutputs()

	/**
	 * REQ-BRE-007: the schema of a rule set held by another organisation is a
	 * 404 for a caller in organisation B, as the spec's scenario says.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-007-per-tenant-isolation-and-multitenancy
	 */
	public function testSchemaOfAnotherOrganisationsRuleSetIsNotFound(): void {
		$this->authenticate();
		$this->modelOrganisationB();

		$response = $this->controller()->schema('loan-eligibility');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testSchemaOfAnotherOrganisationsRuleSetIsNotFound()

	/**
	 * REQ-BRE-007: test-all on a rule set held by another organisation is a
	 * 404 and runs no test case.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-007-per-tenant-isolation-and-multitenancy
	 */
	public function testTestAllOnAnotherOrganisationsRuleSetIsNotFound(): void {
		$this->authenticate();
		$this->modelOrganisationB();
		$this->versioningService->expects($this->never())->method('runTestGate');

		$response = $this->controller()->testAll('loan-eligibility');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testTestAllOnAnotherOrganisationsRuleSetIsNotFound()

	/**
	 * OpenRegister as a caller in organisation B meets it, with the rule set
	 * and the `buildiq` register held by organisation A: with the organisation
	 * filter on nothing is visible and the filtered register lookup throws.
	 *
	 * @return void
	 */
	private function modelOrganisationB(): void {
		$ruleSet = ['id' => 'rs-1', 'slug' => 'loan-eligibility', 'version' => '1.0.0', 'inputSchema' => []];
		$this->objectService->method('searchObjectsBySlug')->willReturnCallback(
			static function (string $registerSlug, string $schema, array $filters = [], bool $_rbac = true, bool $_multitenancy = true) use ($ruleSet): array {
				if ($_multitenancy === true) {
					throw new DoesNotExistException('searchObjectsBySlug: register slug not found in caller organisation: ' . $registerSlug);
				}

				return [$ruleSet];
			}
		);
		$this->objectService->method('searchObjects')->willReturnCallback(
			static function (array $query = [], bool $_rbac = true, bool $_multitenancy = true) use ($ruleSet): array {
				if ($_multitenancy === true) {
					return [];
				}

				return [$ruleSet];
			}
		);

	}//end modelOrganisationB()
	/**
	 * A rule set the caller's organisation holds, and the editor's table body.
	 *
	 * @param bool $admin Whether the caller is an administrator.
	 *
	 * @return void
	 */
	private function editorSavesATable(bool $admin): void {
		$this->authenticate();
		$this->groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => $admin === true && $group === 'admin'
		);
		$this->objectService->method('searchObjects')->willReturnCallback(
			static function (array $query): array {
				if ((string)$query['@self']['schema'] === '101' && ($query['slug'] ?? '') === 'event-fee') {
					return [['slug' => 'event-fee', 'name' => 'Event fee', '@self' => ['organisation' => 'ORG-A']]];
				}

				return [];
			}
		);
		$this->objectService->method('searchObjectsBySlug')->willReturn([]);
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				$body = [
					'hitPolicy' => 'rule-order',
					'inputColumns' => [['name' => 'attendees', 'type' => 'integer', 'expressionPath' => 'event.attendees']],
					'rules' => [
						['conditions' => ['attendees' => '1..50'], 'values' => ['fee' => 10], 'label' => 'small'],
						['conditions' => [], 'values' => ['fee' => 25], 'label' => 'large'],
					],
				];

				return ($body[$key] ?? $default);
			}
		);

	}//end editorSavesATable()

	/**
	 * Decision 196: the editor's table is written into OpenRegister's
	 * decision-tables register, in the shape OpenRegister evaluates, under the
	 * key of the rule set the caller resolved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function testAnAdministratorsTableIsStoredInOpenRegister(): void {
		$this->editorSavesATable(admin: true);

		$written = null;
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true) use (&$written) {
				$this->assertSame('decision-tables', $register);
				$this->assertSame('decision-table', $schema);
				$this->assertNull($uuid);
				$written = $object;
				return $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
			}
		);

		$response = $this->controller()->saveDecisionTable('event-fee');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('buildiq.org-a.event-fee', $written['key']);
		$this->assertSame('FIRST', $written['hitPolicy']);
		$this->assertSame('buildiq', $written['owner']);
		$this->assertSame([['name' => 'attendees', 'type' => 'integer', 'expressionPath' => 'event.attendees']], $written['inputs']);
		$this->assertSame([['name' => 'fee', 'type' => 'string']], $written['outputs']);
		$this->assertSame(['[1..50]'], $written['rules'][0]['inputEntries']);
		$this->assertSame([10], $written['rules'][0]['outputEntries']);
		$this->assertSame(['-'], $written['rules'][1]['inputEntries']);
		$this->assertSame('large', $written['rules'][1]['label']);

	}//end testAnAdministratorsTableIsStoredInOpenRegister()

	/**
	 * Writing a decision table stays admin-only, as it was in buildiq's own register.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function testANonAdministratorCannotWriteATable(): void {
		$this->editorSavesATable(admin: false);
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller()->saveDecisionTable('event-fee');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testANonAdministratorCannotWriteATable()

	/**
	 * A table OpenRegister refuses comes back as 422 with OpenRegister's reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function testATableOpenRegisterRefusesIsUnprocessable(): void {
		$this->editorSavesATable(admin: true);
		$this->objectService->method('saveObject')->willThrowException(
			new RuntimeException('The decision table cannot be run: rule rule-0 has 1 input entries for 2 declared inputs.')
		);

		$response = $this->controller()->saveDecisionTable('event-fee');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertStringContainsString('cannot be run', (string)$response->getData()['detail']);

	}//end testATableOpenRegisterRefusesIsUnprocessable()

	/**
	 * A rule set the caller cannot resolve has no table to write: 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function testATableForAnUnknownRuleSetIsNotFound(): void {
		$this->editorSavesATable(admin: true);
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller()->saveDecisionTable('no-such-set');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testATableForAnUnknownRuleSetIsNotFound()
}//end class
