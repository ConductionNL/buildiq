<?php

/**
 * Unit tests for the rule set export (REQ-BQCV-017).
 *
 * A maker exports a rule set: the export holds the rule set's metadata, its
 * decision table, its condition-action rules and its test cases, and no
 * execution logs, so the file can be imported elsewhere.
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
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/business-rules-engine/spec.md#requirement-a-rule-set-can-be-exported-as-a-file-req-bqcv-017
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\RulesController;
use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\DecisionTableEvaluator;
use OCA\Buildiq\Service\DecisionTableShape;
use OCA\Buildiq\Service\ExpressionEvaluator;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Service\RuleEngineService;
use OCA\Buildiq\Service\RuleObjectReader;
use OCA\Buildiq\Service\RuleSetVersioningService;
use OCA\Buildiq\Service\RuleStorage;
use OCA\Buildiq\Service\StoredDecisionTables;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator as SharedEvaluator;
use OCA\OpenRegister\Service\Dmn\DecisionTableStore;
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
use UnexpectedValueException;

/**
 * Tests for {@see RulesController::export()} and {@see RuleStorage::exportBundle()}.
 */
final class RulesControllerExportTest extends TestCase {

	/**
	 * The schema ids the register holds, by slug.
	 */
	private const SCHEMA_IDS = [
		'rule-set' => 101,
		'condition-action-rule' => 102,
		'rule-test-case' => 103,
		'rule-execution-log' => 104,
	];

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * OpenRegister's decision-table store.
	 *
	 * @var DecisionTableStore&MockObject
	 */
	private DecisionTableStore&MockObject $store;

	/**
	 * The schema ids every object search asked for.
	 *
	 * @var list<int>
	 */
	private array $searchedSchemas = [];

	/**
	 * Build mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->store = $this->createMock(originalClassName: DecisionTableStore::class);
		$this->searchedSchemas = [];

	}//end setUp()

	/**
	 * Construct the controller under test over the mocked register.
	 *
	 * @return RulesController
	 */
	private function controller(): RulesController {
		$register = new class extends Register {
			/**
			 * The register id.
			 *
			 * @return int
			 */
			public function getId(): int {
				return 7;
			}//end getId()

			/**
			 * The schema ids the register holds.
			 *
			 * @return array<int,int>
			 */
			public function getSchemas(): array {
				return [101, 102, 103, 104];
			}//end getSchemas()
		};
		$registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$registerMapper->method('find')->willReturn(value: $register);
		$schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$schemaMapper->method('findIdsBySlugs')->willReturnCallback(
			callback: static fn (array $slugs): array => [$slugs[0] => [(string)self::SCHEMA_IDS[$slugs[0]]]]
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$evaluator = new DecisionTableEvaluator(
			expressionEvaluator: new ExpressionEvaluator(),
			sharedEvaluator: $this->createMock(originalClassName: SharedEvaluator::class)
		);

		return new RulesController(
			request: $this->createMock(originalClassName: IRequest::class),
			logger: $logger,
			ruleEngine: $this->createMock(originalClassName: RuleEngineService::class),
			storage: new RuleStorage(
				reader: new RuleObjectReader(
					objectService: $this->objectService,
					registerMapper: $registerMapper,
					schemaMapper: $schemaMapper,
					logger: $logger
				),
				tables: new StoredDecisionTables(
					store: $this->store,
					objects: $this->objectService,
					shape: new DecisionTableShape(evaluator: $evaluator),
					logger: $logger
				),
				versioningService: $this->createMock(originalClassName: RuleSetVersioningService::class)
			),
			caller: new CallerContext(
				userSession: $this->userSession,
				groupManager: $this->createMock(originalClassName: IGroupManager::class),
				permissionResolver: $this->createMock(originalClassName: PermissionResolver::class)
			),
		);

	}//end controller()

	/**
	 * Sign in as a maker who is not an administrator.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn(value: 'sanne');
		$this->userSession->method('getUser')->willReturn(value: $user);

	}//end signIn()

	/**
	 * A rule set with one decision table and two test cases; the register also
	 * holds an execution log for it.
	 *
	 * @return void
	 */
	private function seedRuleSet(): void {
		$rows = [
			101 => [[
				'slug' => 'loan-eligibility',
				'name' => 'Loan eligibility',
				'version' => '1.0.0',
				'status' => 'active',
				'ruleType' => 'decision-table',
				'@self' => ['id' => 'uuid-rs', 'organisation' => 'org-1'],
			],
			],
			102 => [],
			103 => [
				['ruleSetId' => 'loan-eligibility', 'name' => 'High income passes', '@self' => ['id' => 'uuid-t1']],
				['ruleSetId' => 'loan-eligibility', 'name' => 'Low income fails', '@self' => ['id' => 'uuid-t2']],
			],
			104 => [['ruleSetId' => 'loan-eligibility', 'inputPayload' => ['income' => 1]]],
		];
		$this->objectService->method('searchObjects')->willReturnCallback(
			callback: function (array $query) use ($rows): array {
				$schema = (int)$query['@self']['schema'];
				$this->searchedSchemas[] = $schema;
				return $rows[$schema];
			}
		);
		$this->store->method('find')->willReturnCallback(
			callback: static function (string $reference): array {
				if ($reference !== 'buildiq.org-1.loan-eligibility') {
					throw new UnexpectedValueException(message: 'No decision table has the key "' . $reference . '".');
				}

				return [
					'key' => 'buildiq.org-1.loan-eligibility',
					'name' => 'Loan eligibility',
					'hitPolicy' => 'FIRST',
					'inputs' => [['name' => 'Income', 'type' => 'number', 'expressionPath' => 'income']],
					'outputs' => [['name' => 'eligible', 'type' => 'boolean']],
					'rules' => [['id' => 'rule-0', 'inputEntries' => ['>= 30000'], 'outputEntries' => [true]]],
				];
			}
		);

	}//end seedRuleSet()

	/**
	 * Scenario "A maker exports a rule set": the export holds the rule set,
	 * its one decision table and both test cases, and no execution logs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/business-rules-engine/spec.md#requirement-a-rule-set-can-be-exported-as-a-file-req-bqcv-017
	 */
	public function testAMakerExportsARuleSetWithItsTableAndTestCasesAndNoLogs(): void {
		$this->signIn();
		$this->seedRuleSet();

		$response = $this->controller()->export(ruleSetSlug: 'loan-eligibility');
		$data = $response->getData();

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: RuleStorage::EXPORT_FORMAT, actual: $data['format']);
		self::assertSame(expected: 'loan-eligibility', actual: $data['ruleSet']['slug']);
		self::assertSame(expected: 'decision-table', actual: $data['ruleSet']['ruleType']);
		self::assertCount(expectedCount: 1, haystack: $data['decisionTables']);
		self::assertSame(expected: 'income', actual: $data['decisionTables'][0]['inputColumns'][0]['expressionPath'] ?? null);
		self::assertSame(expected: [], actual: $data['conditionActionRules']);
		self::assertSame(
			expected: ['High income passes', 'Low income fails'],
			actual: array_column(array: $data['testCases'], column_key: 'name')
		);
		self::assertSame(
			expected: ['format', 'ruleSet', 'decisionTables', 'conditionActionRules', 'testCases'],
			actual: array_keys(array: $data)
		);
		self::assertNotContains(needle: self::SCHEMA_IDS['rule-execution-log'], haystack: $this->searchedSchemas);

	}//end testAMakerExportsARuleSetWithItsTableAndTestCasesAndNoLogs()

	/**
	 * The export carries no instance metadata: `@self` is dropped from the
	 * rule set and from each test case, so the file imports cleanly elsewhere.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/business-rules-engine/spec.md#requirement-a-rule-set-can-be-exported-as-a-file-req-bqcv-017
	 */
	public function testTheExportDropsTheInstanceMetadata(): void {
		$this->signIn();
		$this->seedRuleSet();

		$data = $this->controller()->export(ruleSetSlug: 'loan-eligibility')->getData();

		self::assertArrayNotHasKey(key: '@self', array: $data['ruleSet']);
		foreach ($data['testCases'] as $testCase) {
			self::assertArrayNotHasKey(key: '@self', array: $testCase);
		}

	}//end testTheExportDropsTheInstanceMetadata()

	/**
	 * A rule set the caller cannot resolve is not found, and nothing else is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/business-rules-engine/spec.md#requirement-a-rule-set-can-be-exported-as-a-file-req-bqcv-017
	 */
	public function testAnUnknownRuleSetIsNotFound(): void {
		$this->signIn();
		$this->objectService->method('searchObjects')->willReturn(value: []);
		$this->store->expects(matcher: self::never())->method('find');

		$response = $this->controller()->export(ruleSetSlug: 'ghost');

		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());

	}//end testAnUnknownRuleSetIsNotFound()

	/**
	 * Without a signed-in user the export is refused before any read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/business-rules-engine/spec.md#requirement-a-rule-set-can-be-exported-as-a-file-req-bqcv-017
	 */
	public function testTheExportNeedsASignedInUser(): void {
		$this->userSession->method('getUser')->willReturn(value: null);
		$this->objectService->expects(matcher: self::never())->method('searchObjects');

		$response = $this->controller()->export(ruleSetSlug: 'loan-eligibility');

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());

	}//end testTheExportNeedsASignedInUser()

	/**
	 * A maker who is not an administrator may export (ADR-005 posture).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/business-rules-engine/spec.md#requirement-a-rule-set-can-be-exported-as-a-file-req-bqcv-017
	 */
	public function testTheExportIsNoAdminRequired(): void {
		$method = new ReflectionMethod(objectOrMethod: RulesController::class, method: 'export');

		self::assertCount(expectedCount: 1, haystack: $method->getAttributes(name: NoAdminRequired::class));

	}//end testTheExportIsNoAdminRequired()
}//end class
