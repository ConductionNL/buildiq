<?php

/**
 * Unit tests for the FormLiveValuesListener paths the save-time tests leave out: how a version's id is
 * found for re-indexing, an index that cannot be written, an empty index, and the refusal and change
 * outcomes applied to the event.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Listener;

use OCA\Buildiq\Listener\FormLiveValuesListener;
use OCA\Buildiq\Service\FormLiveBindingIndex;
use OCA\Buildiq\Service\FormLiveValuesRecomputer;
use OCA\Buildiq\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Edge cases of FormLiveValuesListener.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class FormLiveValuesListenerEdgeTest extends TestCase {

	/**
	 * The slug resolver double.
	 *
	 * @var ObjectSchemaSlugResolver&MockObject
	 */
	private ObjectSchemaSlugResolver $slugs;

	/**
	 * The binding index double.
	 *
	 * @var FormLiveBindingIndex&MockObject
	 */
	private FormLiveBindingIndex $index;

	/**
	 * The recomputer double.
	 *
	 * @var FormLiveValuesRecomputer&MockObject
	 */
	private FormLiveValuesRecomputer $recomputer;

	/**
	 * Warnings logged, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * The listener over the doubles.
	 *
	 * @var FormLiveValuesListener
	 */
	private FormLiveValuesListener $listener;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->slugs = $this->createMock(ObjectSchemaSlugResolver::class);
		$this->index = $this->createMock(FormLiveBindingIndex::class);
		$this->recomputer = $this->createMock(FormLiveValuesRecomputer::class);
		$this->warnings = [];

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		$this->listener = new FormLiveValuesListener(
			slugs: $this->slugs,
			index: $this->index,
			recomputer: $this->recomputer,
			logger: $logger
		);
	}//end setUp()

	/**
	 * Any other event is ignored before a slug is resolved.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$this->slugs->expects($this->never())->method('schemaSlug');

		$this->listener->handle(new Event());
	}//end testAnotherEventIsIgnored()

	/**
	 * A version without a uuid is indexed under its id, else its slug, else not at all.
	 *
	 * @return void
	 */
	public function testAVersionWithoutAUuidFallsBackToItsIdThenSlug(): void {
		$this->asVersion();
		$indexed = [];
		$this->index->method('reindexVersion')->willReturnCallback(
			function (string $versionId, array $version) use (&$indexed): void {
				$indexed[] = $versionId;
			}
		);

		$this->listener->handle(new ObjectUpdatingEvent($this->entity(uuid: '', data: ['id' => 'v-id', 'slug' => 'v-slug'])));
		$this->listener->handle(new ObjectUpdatingEvent($this->entity(uuid: '', data: ['slug' => 'v-slug'])));
		$this->listener->handle(new ObjectUpdatingEvent($this->entity(uuid: '', data: ['name' => 'nothing'])));
		$this->listener->handle(new ObjectCreatingEvent($this->entity(uuid: 'v-uuid', data: ['id' => 'v-id'])));

		$this->assertSame(['v-id', 'v-slug', 'v-uuid'], $indexed);
	}//end testAVersionWithoutAUuidFallsBackToItsIdThenSlug()

	/**
	 * A uuid that cannot be read falls back to the version's id.
	 *
	 * @return void
	 */
	public function testAnUnreadableUuidFallsBackToTheId(): void {
		$this->asVersion();
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getUuid')->willThrowException(new RuntimeException('no uuid'));
		$entity->method('getObject')->willReturn(['id' => 'v-id']);

		$this->index->expects($this->once())->method('reindexVersion')->with('v-id', ['id' => 'v-id']);

		$this->listener->handle(new ObjectUpdatingEvent($entity));
	}//end testAnUnreadableUuidFallsBackToTheId()

	/**
	 * An index that cannot be written logs a warning naming the version, and the save goes on.
	 *
	 * @return void
	 */
	public function testAnIndexThatCannotBeWrittenIsAWarning(): void {
		$this->asVersion();
		$this->index->method('reindexVersion')->willThrowException(new RuntimeException('config full'));
		$event = new ObjectUpdatingEvent($this->entity(uuid: 'v-uuid', data: []));

		$this->listener->handle($event);

		$this->assertSame(
			[['Buildiq: the live forms of an app version could not be indexed.', ['version' => 'v-uuid', 'exception' => 'config full']]],
			$this->warnings
		);
		$this->assertFalse($event->isPropagationStopped());
	}//end testAnIndexThatCannotBeWrittenIsAWarning()

	/**
	 * With nothing indexed, no bindings are looked up.
	 *
	 * @return void
	 */
	public function testAnEmptyIndexLooksNothingUp(): void {
		$this->asObject();
		$this->index->method('isEmpty')->willReturn(true);
		$this->index->expects($this->never())->method('bindingsFor');
		$this->recomputer->expects($this->never())->method('recompute');

		$this->listener->handle(new ObjectCreatingEvent($this->entity(uuid: 'o-1', data: ['fee' => 1])));
	}//end testAnEmptyIndexLooksNothingUp()

	/**
	 * Bindings are looked up by slug and by stored id, the object data is recomputed and the changes are merged.
	 *
	 * @return void
	 */
	public function testChangesAreMergedIntoTheModifiedData(): void {
		$this->asObject();
		$this->index->method('isEmpty')->willReturn(false);
		$this->index->expects($this->once())
			->method('bindingsFor')
			->with(['permits', '3'], ['permit', '9'])
			->willReturn(['binding']);
		$this->recomputer->expects($this->once())
			->method('recompute')
			->with(['fee' => 1], ['binding'])
			->willReturn(['changes' => ['fee' => 50], 'refusal' => null]);

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn(['fee' => 1]);
		$entity->method('getRegister')->willReturn('3');
		$entity->method('getSchema')->willReturn('9');
		$event = new ObjectCreatingEvent($entity);
		$event->setModifiedData(['title' => 'kept']);

		$this->listener->handle($event);

		$this->assertSame(['title' => 'kept', 'fee' => 50], $event->getModifiedData());
		$this->assertFalse($event->isPropagationStopped());
	}//end testChangesAreMergedIntoTheModifiedData()

	/**
	 * A refusal stops the save with a 422 carrying its code and message, and changes nothing.
	 *
	 * @return void
	 */
	public function testARefusalStopsTheSave(): void {
		$this->asObject();
		$this->index->method('isEmpty')->willReturn(false);
		$this->index->method('bindingsFor')->willReturn(['binding']);
		$this->recomputer->method('recompute')->willReturn(
			['changes' => ['fee' => 50], 'refusal' => ['code' => 'check_failed', 'message' => 'Fee too low.']]
		);
		$event = new ObjectUpdatingEvent($this->entity(uuid: 'o-1', data: ['fee' => 1]));

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['status' => 422, 'code' => 'check_failed', 'message' => 'Fee too low.'], $event->getErrors());
		$this->assertSame([], $event->getModifiedData());
	}//end testARefusalStopsTheSave()

	/**
	 * Without matching bindings nothing is recomputed.
	 *
	 * @return void
	 */
	public function testNoBindingsNoRecompute(): void {
		$this->asObject();
		$this->index->method('isEmpty')->willReturn(false);
		$this->index->method('bindingsFor')->willReturn([]);
		$this->recomputer->expects($this->never())->method('recompute');

		$this->listener->handle(new ObjectUpdatingEvent($this->entity(uuid: 'o-1', data: [])));
	}//end testNoBindingsNoRecompute()

	/**
	 * Resolve every entity as buildiq's applicationVersion.
	 *
	 * @return void
	 */
	private function asVersion(): void {
		$this->slugs->method('schemaSlug')->willReturn('applicationVersion');
		$this->slugs->method('registerSlug')->willReturn('buildiq');
		$this->index->expects($this->never())->method('isEmpty');
	}//end asVersion()

	/**
	 * Resolve every entity as a permit in the permits register.
	 *
	 * @return void
	 */
	private function asObject(): void {
		$this->slugs->method('schemaSlug')->willReturn('permit');
		$this->slugs->method('registerSlug')->willReturn('permits');
		$this->index->expects($this->never())->method('reindexVersion');
	}//end asObject()

	/**
	 * An object entity with a uuid and data.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end entity()
}//end class
