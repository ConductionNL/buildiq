<?php

/**
 * Unit tests for the test gate in VersionPromotionService::promote().
 *
 * A promotion onto the app's production version runs the app's test suite on
 * the source first and stops before the lock when it fails; a promotion along
 * the chain does not run it; an Application that cannot be read stops the
 * promotion rather than skip the gate (lifecycle-release-test-gate D2).
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-release-waits-for-a-green-suite-req-bqrt-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Exception\ReleaseTestsFailedException;
use OCA\Buildiq\Exception\VersionLockedException;
use OCA\Buildiq\Service\RegisterRowReader;
use OCA\Buildiq\Service\ReleaseTestGate;
use OCA\Buildiq\Service\VersionPromotionService;
use OCA\Buildiq\Service\VersionSchemaCarrier;
use OCA\Buildiq\Support\ReleaseOverride;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the gate step of a promotion.
 *
 * @covers \OCA\Buildiq\Service\VersionPromotionService
 * @uses   \OCA\Buildiq\Exception\ReleaseTestsFailedException
 * @uses   \OCA\Buildiq\Exception\VersionLockedException
 * @uses   \OCA\Buildiq\Exception\VersionPromotionException
 * @uses   \OCA\Buildiq\Service\RegisterRowReader
 * @uses   \OCA\Buildiq\Service\VersionSchemaCarrier
 * @uses   \OCA\Buildiq\Support\ReleaseOverride
 */
final class VersionPromotionTestGateTest extends TestCase {

	private const SOURCE = ['id' => 'u-dev', 'application' => 'u-app', 'promotesTo' => 'u-prod', 'manifest' => ['pages' => []]];

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var ReleaseTestGate&MockObject
	 */
	private ReleaseTestGate&MockObject $gate;

	private VersionPromotionService $service;

	/**
	 * Set up the service over an object-service double and a gate double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$logger = $this->createMock(LoggerInterface::class);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->gate = $this->createMock(ReleaseTestGate::class);

		$this->service = new VersionPromotionService(
			logger: $logger,
			objectService: $this->objectService,
			registerMapper: $registerMapper,
			schemaCarrier: new VersionSchemaCarrier(logger: $logger, registerMapper: $registerMapper, schemaMapper: $this->createMock(SchemaMapper::class)),
			rowReader: new RegisterRowReader($this->objectService),
			testGate: $this->gate,
		);
	}//end setUp()

	/**
	 * A promotion onto production runs the suite on the source, as a
	 * promotion, with the override, and a failure stops it before the lock.
	 *
	 * @return void
	 */
	public function testAPromotionOntoProductionWaitsForTheTests(): void {
		$this->objectsAre(application: ['id' => 'u-app', 'slug' => 'vergunningen', 'productionVersion' => 'u-prod']);
		$override = new ReleaseOverride(reason: 'Hotfix', userId: 'eddie', byOwner: false);

		$this->gate->expects(self::once())->method('guard')
			->with(
				self::callback(static fn (array $app): bool => $app['id'] === 'u-app' && $app['productionVersion'] === 'u-prod'),
				self::SOURCE,
				ReleaseTestGate::TRIGGER_PROMOTION,
				$override
			)
			->willThrowException(new ReleaseTestsFailedException(run: ['failed' => 1]));
		$this->objectService->expects(self::never())->method('lockObject');
		$this->objectService->expects(self::never())->method('saveObject');

		$this->expectException(ReleaseTestsFailedException::class);
		$this->service->promote(source: self::SOURCE, strategy: VersionPromotionService::STRATEGY_MIGRATE_EXISTING_DATA, override: $override);
	}//end testAPromotionOntoProductionWaitsForTheTests()

	/**
	 * A promotion along the chain (the target is not production) runs no tests.
	 *
	 * @return void
	 */
	public function testAPromotionAlongTheChainRunsNoTests(): void {
		$this->objectsAre(application: ['id' => 'u-app', 'productionVersion' => 'u-other']);
		$this->gate->expects(self::never())->method('guard');
		// The promotion goes on to take the lock; a held lock ends it here.
		$this->objectService->expects(self::once())->method('lockObject')->willThrowException(new RuntimeException('locked'));

		$this->expectException(VersionLockedException::class);
		$this->service->promote(source: self::SOURCE, strategy: VersionPromotionService::STRATEGY_START_WITH_SOURCE_DATA);
	}//end testAPromotionAlongTheChainRunsNoTests()

	/**
	 * An Application that cannot be read stops the promotion; the gate is never skipped.
	 *
	 * @return void
	 */
	public function testAnUnreadableApplicationStopsThePromotion(): void {
		$this->objectsAre(application: null);
		$this->gate->expects(self::never())->method('guard');
		$this->objectService->expects(self::never())->method('lockObject');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Application u-app of the promoted version could not be read; the promotion was not run.');
		$this->service->promote(source: self::SOURCE, strategy: VersionPromotionService::STRATEGY_MIGRATE_EXISTING_DATA);
	}//end testAnUnreadableApplicationStopsThePromotion()

	/**
	 * The object service answers the target version and this Application.
	 *
	 * @param array<string, mixed>|null $application The Application, or null for none.
	 *
	 * @return void
	 */
	private function objectsAre(?array $application): void {
		$target = $this->entity(payload: ['id' => 'u-prod', 'application' => 'u-app', 'register' => 'vergunningen-prod']);
		$app = null;
		if ($application !== null) {
			$app = $this->entity(payload: $application);
		}

		$this->objectService->method('find')->willReturnCallback(
			static fn (string|int $id): ?ObjectEntity => match ($id) {
				'u-prod' => $target,
				'u-app' => $app,
				default => null,
			}
		);
	}//end objectsAre()

	/**
	 * An object entity double that serialises to this payload.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $payload): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($payload);
		return $entity;
	}//end entity()
}//end class
