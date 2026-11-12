<?php

/**
 * Unit tests for the branches of ProductionVersionGuardListener that
 * ProductionVersionGuardListenerTest leaves open: the create path, a guard
 * that accepts the change, the exact error and log a rejection produces, and
 * every way the application's uuid is found (or not).
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Listener;

use OCA\Buildiq\Listener\ProductionVersionGuardListener;
use OCA\Buildiq\Service\ApplicationVersionService;
use OCA\Buildiq\Service\ListenerSlugContract;
use OCA\Buildiq\Service\ObjectSchemaSlugResolver;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the remaining branches of ProductionVersionGuardListener.
 */
#[CoversClass(ProductionVersionGuardListener::class)]
#[UsesClass(ObjectRecord::class)]
final class ProductionVersionGuardListenerBranchesTest extends TestCase {
	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock version service.
	 *
	 * @var ApplicationVersionService&MockObject
	 */
	private ApplicationVersionService&MockObject $service;

	/**
	 * Mock slug resolver; answers that every entity is an Application.
	 *
	 * @var ObjectSchemaSlugResolver&MockObject
	 */
	private ObjectSchemaSlugResolver&MockObject $slugs;

	/**
	 * Mock opt-in flag.
	 *
	 * @var ListenerSlugContract&MockObject
	 */
	private ListenerSlugContract&MockObject $contract;

	/**
	 * Listener under test.
	 *
	 * @var ProductionVersionGuardListener
	 */
	private ProductionVersionGuardListener $listener;

	/**
	 * Set up mocks and the listener.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->logger   = $this->createMock(LoggerInterface::class);
		$this->service  = $this->createMock(ApplicationVersionService::class);
		$this->slugs    = $this->createMock(ObjectSchemaSlugResolver::class);
		$this->contract = $this->createMock(ListenerSlugContract::class);

		$this->listener = new ProductionVersionGuardListener(
			logger: $this->logger,
			service: $this->service,
			slugs: $this->slugs,
			contract: $this->contract,
		);
	}//end setUp()

	/**
	 * An entity whose object data and uuid getter answer as given.
	 *
	 * @param array<string,mixed> $object The object data.
	 * @param string|null         $uuid   What getUuid() returns; null makes it throw.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $object, ?string $uuid): ObjectEntity&MockObject {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['getObject', 'getUuid'])
			->getMock();
		$entity->method('getObject')->willReturn($object);
		if ($uuid === null) {
			$entity->method('getUuid')->willThrowException(new RuntimeException(message: 'no uuid column'));
		} else {
			$entity->method('getUuid')->willReturn($uuid);
		}

		return $entity;
	}//end entity()

	/**
	 * Enable the contract and mark every entity as an Application.
	 *
	 * @return void
	 */
	private function guardIsAwake(): void {
		$this->contract->method('isEnabled')->willReturn(true);
		$this->slugs->method('isBuildiqSchema')->willReturn(true);
	}//end guardIsAwake()

	/**
	 * An event that is neither a create nor an update is ignored before anything is asked.
	 *
	 * @return void
	 */
	public function testIgnoresOtherEvents(): void {
		$this->contract->expects(self::never())->method('isEnabled');
		$this->slugs->expects(self::never())->method('isBuildiqSchema');
		$this->service->expects(self::never())->method('guardProductionVersionOwnership');

		$this->listener->handle(new Event());
	}//end testIgnoresOtherEvents()

	/**
	 * A create is guarded with the new object's uuid and proposed version, and
	 * an accepted change passes untouched.
	 *
	 * @return void
	 */
	public function testCreateAcceptedByTheGuardPassesThrough(): void {
		$this->guardIsAwake();
		$entity = $this->entity(object: ['productionVersion' => 'version-1'], uuid: 'app-1');

		$this->slugs->expects(self::once())
			->method('isBuildiqSchema')
			->with($entity, ApplicationVersionService::APPLICATION_SCHEMA);
		$this->service->expects(self::once())
			->method('guardProductionVersionOwnership')
			->with(applicationUuid: 'app-1', proposedVersionUuid: 'version-1');
		$this->logger->expects(self::never())->method('info');

		$event = new ObjectCreatingEvent($entity);
		$this->listener->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame(expected: [], actual: $event->getErrors());
	}//end testCreateAcceptedByTheGuardPassesThrough()

	/**
	 * A rejected create is stopped with the 422 error document and an info log naming the change.
	 *
	 * @return void
	 */
	public function testRejectedCreateIsStoppedWithErrorAndLog(): void {
		$this->guardIsAwake();
		$entity = $this->entity(object: ['productionVersion' => 'version-x'], uuid: 'app-1');
		$this->service->method('guardProductionVersionOwnership')
			->willThrowException(new RuntimeException(message: 'version belongs to another application'));

		$this->logger->expects(self::once())
			->method('info')
			->with(
				'Buildiq: blocked Application save — productionVersion guard rejected the change.',
				[
					'applicationUuid'   => 'app-1',
					'productionVersion' => 'version-x',
					'reason'            => 'version belongs to another application',
				]
			);

		$event = new ObjectCreatingEvent($entity);
		$this->listener->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(
			expected: [
				'status'  => 422,
				'code'    => 'buildiq.production_version.back_reference_mismatch',
				'message' => 'version belongs to another application',
			],
			actual: $event->getErrors()
		);
	}//end testRejectedCreateIsStoppedWithErrorAndLog()

	/**
	 * With an empty getUuid(), the object's own `id` is the application uuid.
	 *
	 * @return void
	 */
	public function testFallsBackToTheObjectId(): void {
		$this->guardIsAwake();
		$entity = $this->entity(object: ['productionVersion' => 'version-1', 'id' => 'app-from-id', 'uuid' => 'app-from-uuid'], uuid: '');

		$this->service->expects(self::once())
			->method('guardProductionVersionOwnership')
			->with(applicationUuid: 'app-from-id', proposedVersionUuid: 'version-1');

		$this->listener->handle(new ObjectUpdatingEvent($entity));
	}//end testFallsBackToTheObjectId()

	/**
	 * A non-string `id` is skipped and the object's `uuid` is used.
	 *
	 * @return void
	 */
	public function testFallsBackToTheObjectUuidWhenIdIsNotAString(): void {
		$this->guardIsAwake();
		$entity = $this->entity(object: ['productionVersion' => 'version-1', 'id' => 42, 'uuid' => 'app-from-uuid'], uuid: '');

		$this->service->expects(self::once())
			->method('guardProductionVersionOwnership')
			->with(applicationUuid: 'app-from-uuid', proposedVersionUuid: 'version-1');

		$this->listener->handle(new ObjectUpdatingEvent($entity));
	}//end testFallsBackToTheObjectUuidWhenIdIsNotAString()

	/**
	 * A getUuid() that throws is logged at debug level and the object data is used instead.
	 *
	 * @return void
	 */
	public function testThrowingUuidGetterFallsBackAndLogsDebug(): void {
		$this->guardIsAwake();
		$entity = $this->entity(object: ['productionVersion' => 'version-1', 'id' => 'app-from-id'], uuid: null);

		$this->logger->expects(self::once())
			->method('debug')
			->with(self::stringContains('no uuid column'));
		$this->service->expects(self::once())
			->method('guardProductionVersionOwnership')
			->with(applicationUuid: 'app-from-id', proposedVersionUuid: 'version-1');

		$this->listener->handle(new ObjectUpdatingEvent($entity));
	}//end testThrowingUuidGetterFallsBackAndLogsDebug()

	/**
	 * Without any uuid the guard is not consulted and the save goes through.
	 *
	 * @return void
	 */
	public function testNoUuidAnywhereSkipsTheGuard(): void {
		$this->guardIsAwake();
		$entity = $this->entity(object: ['productionVersion' => 'version-1'], uuid: '');

		$this->service->expects(self::never())->method('guardProductionVersionOwnership');

		$event = new ObjectUpdatingEvent($entity);
		$this->listener->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testNoUuidAnywhereSkipsTheGuard()
}//end class
