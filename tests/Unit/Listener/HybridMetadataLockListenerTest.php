<?php

/**
 * Unit tests for HybridMetadataLockListener.
 *
 * Covers unify-apps-with-app-type (spec unified-app-model): a hybrid app's
 * slug/name are read-only (rejected on update), virtual apps keep full edit,
 * content-only edits on a hybrid are allowed, and non-Application / create
 * events are ignored.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Listener;

use OCA\Buildiq\Listener\HybridMetadataLockListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for HybridMetadataLockListener.
 */
class HybridMetadataLockListenerTest extends TestCase {
	/**
	 * Listener under test.
	 */
	private HybridMetadataLockListener $listener;

	/**
	 * Set up the SUT.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->listener = new HybridMetadataLockListener(
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * Changing a hybrid app's slug is rejected (propagation stopped + 422 error).
	 *
	 * @return void
	 */
	public function testRejectsSlugChangeOnHybrid(): void {
		$event = $this->updateEvent(
			old: ['appType' => 'hybrid', 'slug' => 'opencatalogi', 'name' => 'OpenCatalogi'],
			new: ['appType' => 'hybrid', 'slug' => 'renamed', 'name' => 'OpenCatalogi']
		);

		$this->listener->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(422, $event->getErrors()['status']);
		self::assertSame('buildiq.hybrid_metadata.locked', $event->getErrors()['code']);

	}//end testRejectsSlugChangeOnHybrid()

	/**
	 * Changing a hybrid app's name is rejected.
	 *
	 * @return void
	 */
	public function testRejectsNameChangeOnHybrid(): void {
		$event = $this->updateEvent(
			old: ['appType' => 'hybrid', 'slug' => 'pipelinq', 'name' => 'Pipelinq'],
			new: ['appType' => 'hybrid', 'slug' => 'pipelinq', 'name' => 'Renamed']
		);

		$this->listener->handle($event);

		self::assertTrue($event->isPropagationStopped());

	}//end testRejectsNameChangeOnHybrid()

	/**
	 * A non-identity edit on a hybrid app (e.g. toggling allowUserOverrides) is
	 * allowed — only slug/name/appType are locked.
	 *
	 * @return void
	 */
	public function testAllowsContentEditOnHybrid(): void {
		$event = $this->updateEvent(
			old: ['appType' => 'hybrid', 'slug' => 'opencatalogi', 'name' => 'OpenCatalogi', 'allowUserOverrides' => false],
			new: ['appType' => 'hybrid', 'slug' => 'opencatalogi', 'name' => 'OpenCatalogi', 'allowUserOverrides' => true]
		);

		$this->listener->handle($event);

		self::assertFalse($event->isPropagationStopped());

	}//end testAllowsContentEditOnHybrid()

	/**
	 * A hybrid app's description is NOT part of the locked identity.
	 *
	 * The spec's lock requirement names `slug`, `name`, `appType` and the
	 * `baseRef` linkage, then states "All other content … SHALL remain
	 * editable" (openspec/specs/unified-app-model/spec.md). Locking
	 * `description` additionally broke every partial update: OR's saveObject()
	 * is PUT-semantic, so a payload that merely omits `description` reaches the
	 * listener as `description: null` and was rejected as a change.
	 *
	 * @return void
	 */
	public function testAllowsDescriptionChangeOnHybrid(): void {
		$event = $this->updateEvent(
			old: ['appType' => 'hybrid', 'slug' => 'pipelinq', 'name' => 'Pipelinq', 'description' => 'A'],
			new: ['appType' => 'hybrid', 'slug' => 'pipelinq', 'name' => 'Pipelinq', 'description' => 'B']
		);

		$this->listener->handle($event);

		self::assertFalse($event->isPropagationStopped());

	}//end testAllowsDescriptionChangeOnHybrid()

	/**
	 * A hybrid app's productionVersion pointer MUST be movable.
	 *
	 * The spec requires it — "The Application's `productionVersion` SHALL point
	 * at that version" — and three product paths depend on it:
	 * AppOverrideService::createHybridApp() (the app-overrides HTTP shim),
	 * MigrateAppOverridesToHybrid, and ApplicationVersionService::
	 * releaseVersion() step 3. While it was locked, all three were rejected at
	 * that step and the hybrid app kept `productionVersion: null`, which
	 * findHybridProductionVersion() treats as "no delta" — so the stored
	 * override was never served.
	 *
	 * @return void
	 */
	public function testAllowsProductionVersionChangeOnHybrid(): void {
		$event = $this->updateEvent(
			old: ['appType' => 'hybrid', 'slug' => 'pipelinq', 'name' => 'Pipelinq', 'productionVersion' => 'ver-1'],
			new: ['appType' => 'hybrid', 'slug' => 'pipelinq', 'name' => 'Pipelinq', 'productionVersion' => 'ver-2']
		);

		$this->listener->handle($event);

		self::assertFalse($event->isPropagationStopped());

	}//end testAllowsProductionVersionChangeOnHybrid()

	/**
	 * The regression this narrowing fixes, end to end: linking a freshly created
	 * hybrid Application to its delta-only production version. The payload sets
	 * `productionVersion` and — being PUT-semantic — carries no `description`,
	 * i.e. exactly the shape createHybridApp() / the fixture seeder send.
	 *
	 * @return void
	 */
	public function testAllowsLinkingProductionVersionOnFreshHybrid(): void {
		$event = $this->updateEvent(
			old: [
				'appType' => 'hybrid',
				'slug' => 'opencatalogi',
				'name' => 'OpenCatalogi',
				'description' => 'Seeded hybrid example.',
				'productionVersion' => null,
			],
			new: [
				'appType' => 'hybrid',
				'slug' => 'opencatalogi',
				'name' => 'OpenCatalogi',
				'description' => null,
				'productionVersion' => 'ver-1',
			]
		);

		$this->listener->handle($event);

		self::assertFalse($event->isPropagationStopped());

	}//end testAllowsLinkingProductionVersionOnFreshHybrid()

	/**
	 * appType is immutable for EVERY app — a virtual→hybrid flip is rejected.
	 *
	 * @return void
	 */
	public function testRejectsAppTypeChangeOnVirtual(): void {
		$event = $this->updateEvent(
			old: ['appType' => 'virtual', 'slug' => 'my-app', 'name' => 'My App'],
			new: ['appType' => 'hybrid', 'slug' => 'my-app', 'name' => 'My App']
		);

		$this->listener->handle($event);

		self::assertTrue($event->isPropagationStopped());

	}//end testRejectsAppTypeChangeOnVirtual()

	/**
	 * A virtual app keeps full edit of slug/name.
	 *
	 * @return void
	 */
	public function testAllowsSlugChangeOnVirtual(): void {
		$event = $this->updateEvent(
			old: ['appType' => 'virtual', 'slug' => 'my-app', 'name' => 'My App'],
			new: ['appType' => 'virtual', 'slug' => 'my-renamed-app', 'name' => 'My Renamed App']
		);

		$this->listener->handle($event);

		self::assertFalse($event->isPropagationStopped());

	}//end testAllowsSlugChangeOnVirtual()

	/**
	 * An app with no appType (legacy) reads as virtual — slug edit allowed.
	 *
	 * @return void
	 */
	public function testTreatsMissingAppTypeAsVirtual(): void {
		$event = $this->updateEvent(
			old: ['slug' => 'legacy', 'name' => 'Legacy'],
			new: ['slug' => 'legacy-renamed', 'name' => 'Legacy']
		);

		$this->listener->handle($event);

		self::assertFalse($event->isPropagationStopped());

	}//end testTreatsMissingAppTypeAsVirtual()

	/**
	 * An update with no old object (create-shaped) is ignored — a hybrid app is
	 * created with its locked identity, which is allowed.
	 *
	 * @return void
	 */
	public function testIgnoresWhenNoOldObject(): void {
		$new = $this->mockEntity(schema: 'built-app', data: ['appType' => 'hybrid', 'slug' => 'opencatalogi']);
		$event = new ObjectUpdatingEvent($new, null);

		$this->listener->handle($event);

		self::assertFalse($event->isPropagationStopped());

	}//end testIgnoresWhenNoOldObject()

	/**
	 * Build an ObjectUpdatingEvent from old/new payloads.
	 *
	 * @param array<string, mixed> $old The stored object payload.
	 * @param array<string, mixed> $new The proposed object payload.
	 * @param string $schema The schema slug both entities report.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function updateEvent(array $old, array $new, string $schema = 'application'): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent(
			$this->mockEntity(schema: $schema, data: $new),
			$this->mockEntity(schema: $schema, data: $old)
		);

	}//end updateEvent()

	/**
	 * Build a mock ObjectEntity reporting the given schema slug + object data.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $data The object payload.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function mockEntity(string $schema, array $data): ObjectEntity&MockObject {
		// Drive the schema slug via the @self.schema projection (getSchemaSlug
		// is not on the out-of-container stub; the listener falls back to
		// jsonSerialize) and the object data via getObject — both resolve
		// identically in- and out-of-container.
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($data);
		$entity->method('jsonSerialize')->willReturn(array_merge(['@self' => ['schema' => $schema]], $data));

		return $entity;
	}//end mockEntity()
}//end class
