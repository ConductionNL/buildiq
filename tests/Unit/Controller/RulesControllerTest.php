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
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

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
}//end class
