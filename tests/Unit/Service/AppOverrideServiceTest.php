<?php

/**
 * Unit tests for AppOverrideService (unified hybrid-app store).
 *
 * Covers unify-apps-with-app-type (spec app-override-persistence +
 * unified-app-model): upsert-creates-a-hybrid-app, re-save-updates-the-version
 * -delta-in-place, delete-idempotent, delete-removes-app-and-version, plus the
 * unchanged delta-shape / app-blanking validation surface.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/unify-apps-with-app-type/specs/app-override-persistence/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Service;

use OCA\OpenBuild\Service\AppOverrideService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AppOverrideService on the unified app model.
 */
class AppOverrideServiceTest extends TestCase {
	/**
	 * Mock OR object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock app manager.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * Service under test.
	 */
	private AppOverrideService $service;

	/**
	 * Set up shared mocks + the SUT.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(ObjectService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);

		$this->service = new AppOverrideService(
			objectService: $this->objectService,
			logger: $this->logger,
			appManager: $this->appManager
		);

	}//end setUp()

	/**
	 * Upsert with no existing hybrid app creates the Application + delta-only
	 * version and returns the new applicationUuid.
	 *
	 * @return void
	 */
	public function testUpsertCreatesHybridAppWhenNone(): void {
		// findHybridApplication → no results.
		$this->objectService->method('searchObjectsBySlug')->willReturn([]);
		$this->appManager->method('getAppInfo')->willReturn(['name' => 'OpenCatalogi']);

		// saveObject is called 3×: Application, ApplicationVersion, Application(update).
		$appEntity = $this->mockEntity(['id' => 'app-1', 'slug' => 'opencatalogi', 'appType' => 'hybrid']);
		$versionEntity = $this->mockEntity(['id' => 'ver-1']);
		$this->objectService->expects(self::exactly(3))
			->method('saveObject')
			->willReturnOnConsecutiveCalls($appEntity, $versionEntity, $appEntity);

		$result = $this->service->upsert(
			appId: 'opencatalogi',
			delta: ['pages' => [['id' => 'home', 'title' => 'Start']]],
			baseRef: null,
			updatedBy: 'alice'
		);

		self::assertSame('opencatalogi', $result['appId']);
		self::assertSame('alice', $result['updatedBy']);
		self::assertSame('app-1', $result['applicationUuid']);

	}//end testUpsertCreatesHybridAppWhenNone()

	/**
	 * On create, the hybrid Application's productionVersion is linked to the new
	 * version (regression: the `+` array-union kept the existing null key, so the
	 * link was silently dropped and the GET shim returned an empty delta).
	 *
	 * @return void
	 */
	public function testCreateLinksProductionVersion(): void {
		$this->objectService->method('searchObjectsBySlug')->willReturn([]);
		$this->appManager->method('getAppInfo')->willReturn(['name' => 'OpenCatalogi']);

		$appEntity = $this->mockEntity(['id' => 'app-1', 'slug' => 'opencatalogi', 'appType' => 'hybrid']);
		$versionEntity = $this->mockEntity(['id' => 'ver-1']);

		$savedObjects = [];
		$this->objectService->method('saveObject')
			->willReturnCallback(function ($object) use (&$savedObjects, $appEntity, $versionEntity) {
				// The object payload is always the first positional parameter of
				// saveObject(), regardless of named-argument call style.
				$savedObjects[] = $object;
				// 2nd call is the version create; 1st + 3rd are the Application.
				return (count($savedObjects) === 2) ? $versionEntity : $appEntity;
			});

		$this->service->upsert(
			appId: 'opencatalogi',
			delta: ['pages' => [['id' => 'home', 'title' => 'X']]],
			baseRef: null,
			updatedBy: 'alice'
		);

		// The final saveObject (Application update) must carry productionVersion
		// = the version uuid, with the OR @self envelope stripped.
		$last = end($savedObjects);
		self::assertSame('ver-1', $last['productionVersion'] ?? null);
		self::assertArrayNotHasKey('@self', $last);

	}//end testCreateLinksProductionVersion()

	/**
	 * Re-saving an existing hybrid app updates its production version delta in
	 * place (one saveObject on the version, no second Application created).
	 *
	 * @return void
	 */
	public function testUpsertUpdatesExistingHybridVersionInPlace(): void {
		$existingApp = $this->mockEntity(
			['id' => 'app-1', 'slug' => 'pipelinq', 'appType' => 'hybrid', 'productionVersion' => 'ver-1']
		);
		$this->objectService->method('searchObjectsBySlug')->willReturn([$existingApp]);

		$existingVersion = $this->mockEntity(['id' => 'ver-1', 'manifestDelta' => []]);
		$this->objectService->method('find')->willReturn($existingVersion);

		// Exactly one saveObject — the version delta update (uuid reused). The
		// uuid is the 5th positional arg of OR's saveObject signature
		// (object, extend, register, schema, uuid, …); match it there.
		$this->objectService->expects(self::once())
			->method('saveObject')
			->with(self::anything(), self::anything(), self::anything(), self::anything(), 'ver-1')
			->willReturn($existingVersion);

		$result = $this->service->upsert(
			appId: 'pipelinq',
			delta: ['menu' => [['id' => 'a', 'label' => 'A']]],
			baseRef: null,
			updatedBy: 'bob'
		);

		self::assertSame('bob', $result['updatedBy']);
		self::assertSame('app-1', $result['applicationUuid']);

	}//end testUpsertUpdatesExistingHybridVersionInPlace()

	/**
	 * Delete with no existing hybrid app is idempotent (no deleteObject call).
	 *
	 * @return void
	 */
	public function testDeleteIsIdempotentWhenNoRecord(): void {
		$this->objectService->method('searchObjectsBySlug')->willReturn([]);
		$this->objectService->expects(self::never())->method('deleteObject');

		$this->service->delete(appId: 'neverhadone');

		self::assertTrue(true);

	}//end testDeleteIsIdempotentWhenNoRecord()

	/**
	 * Delete removes both the hybrid Application and its production version.
	 *
	 * @return void
	 */
	public function testDeleteRemovesHybridAppAndVersion(): void {
		$existingApp = $this->mockEntity(
			['id' => 'app-1', 'slug' => 'decidesk', 'appType' => 'hybrid', 'productionVersion' => 'ver-1']
		);
		$this->objectService->method('searchObjectsBySlug')->willReturn([$existingApp]);

		$existingVersion = $this->mockEntity(['id' => 'ver-1']);
		$this->objectService->method('find')->willReturn($existingVersion);

		$deleted = [];
		$this->objectService->expects(self::exactly(2))
			->method('deleteObject')
			->willReturnCallback(static function ($uuid) use (&$deleted): void {
				$deleted[] = $uuid;
			});

		$this->service->delete(appId: 'decidesk');

		self::assertContains('ver-1', $deleted);
		self::assertContains('app-1', $deleted);

	}//end testDeleteRemovesHybridAppAndVersion()

	/**
	 * findByAppId returns the production version's manifestDelta for a hybrid app.
	 *
	 * @return void
	 */
	public function testFindByAppIdReturnsVersionDelta(): void {
		$app = $this->mockEntity(
			['id' => 'app-1', 'slug' => 'opencatalogi', 'appType' => 'hybrid', 'productionVersion' => 'ver-1']
		);
		$this->objectService->method('searchObjectsBySlug')->willReturn([$app]);

		$version = $this->mockEntity(['id' => 'ver-1', 'manifestDelta' => ['pages' => [['id' => 'home', 'title' => 'X']]]]);
		$this->objectService->method('find')->willReturn($version);

		$record = $this->service->findByAppId(appId: 'opencatalogi');

		self::assertNotNull($record);
		self::assertSame('opencatalogi', $record['appId']);
		self::assertSame([['id' => 'home', 'title' => 'X']], $record['manifestDelta']['pages']);

	}//end testFindByAppIdReturnsVersionDelta()

	/**
	 * findByAppId returns null when no hybrid app exists for the appId.
	 *
	 * @return void
	 */
	public function testFindByAppIdReturnsNullWhenNone(): void {
		$this->objectService->method('searchObjectsBySlug')->willReturn([]);

		self::assertNull($this->service->findByAppId(appId: 'somefleetapp'));

	}//end testFindByAppIdReturnsNullWhenNone()

	/**
	 * A whole-manifest / list-shaped body is rejected by validateDeltaShape.
	 *
	 * @return void
	 */
	public function testValidateDeltaShapeRejectsListShapedBody(): void {
		$violations = $this->service->validateDeltaShape(delta: ['a', 'b', 'c']);
		self::assertNotEmpty($violations);

	}//end testValidateDeltaShapeRejectsListShapedBody()

	/**
	 * A page/widget entry missing an id is a violation.
	 *
	 * @return void
	 */
	public function testValidateDeltaShapeRejectsEntryMissingId(): void {
		$violations = $this->service->validateDeltaShape(
			delta: ['pages' => [['title' => 'no id here']]]
		);
		self::assertNotEmpty($violations);

	}//end testValidateDeltaShapeRejectsEntryMissingId()

	/**
	 * A valid keyed delta passes validation (no violations).
	 *
	 * @return void
	 */
	public function testValidateDeltaShapeAcceptsValidDelta(): void {
		$violations = $this->service->validateDeltaShape(
			delta: [
				'pages' => [
					['id' => 'home', 'title' => 'Renamed'],
					['id' => 'old', '$op' => 'remove'],
					'__order' => ['home', 'about'],
				],
			]
		);
		self::assertSame([], $violations);

	}//end testValidateDeltaShapeAcceptsValidDelta()

	/**
	 * An empty delta is not app-blanking (no-op passthrough).
	 *
	 * @return void
	 */
	public function testWouldBlankAppFalseForEmptyDelta(): void {
		self::assertFalse($this->service->wouldBlankApp(delta: []));

	}//end testWouldBlankAppFalseForEmptyDelta()

	/**
	 * A delta that removes every page and leaves an empty menu blanks the app.
	 *
	 * @return void
	 */
	public function testWouldBlankAppTrueWhenAllPagesRemovedAndMenuEmptied(): void {
		$delta = [
			'pages' => [['id' => 'home', '$op' => 'remove']],
			'menu' => [],
		];
		self::assertTrue($this->service->wouldBlankApp(delta: $delta));

	}//end testWouldBlankAppTrueWhenAllPagesRemovedAndMenuEmptied()

	/**
	 * Build a mock ObjectEntity whose jsonSerialize returns the given array.
	 *
	 * @param array<string, mixed> $data The serialised object data.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function mockEntity(array $data): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);

		return $entity;
	}//end mockEntity()
}//end class
