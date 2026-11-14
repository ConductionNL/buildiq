<?php

/**
 * Buildiq JourneyStore unit tests.
 *
 * A journey is stored in buildiq's `journey` schema, a form step keeps only
 * its reference to the form whatever the client sent, and an existing
 * journey is written under its own id.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\JourneyStore;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for {@see JourneyStore}.
 *
 * @covers \OCA\Buildiq\Service\JourneyStore
 *
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-designer-must-author-steps-over-existing-form-objects
 */
final class JourneyStoreTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The class under test.
	 *
	 * @var JourneyStore
	 */
	private JourneyStore $store;

	/**
	 * Wire the store with a double of OpenRegister's object service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->store   = new JourneyStore(objects: $this->objects);
	}//end setUp()

	/**
	 * A new journey is written into buildiq's journey schema; a form step keeps its reference and loses copied fields.
	 *
	 * @return void
	 */
	public function testANewJourneyIsStoredWithReferencesOnly(): void {
		$journey = [
			'id'    => '',
			'title' => '  Register  ',
			'steps' => [
				['id' => 'a', 'type' => 'form', 'title' => 'A', 'formId' => 'f-1', 'form' => ['fields' => [['key' => 'x']]], 'fields' => [['key' => 'y']], 'writes' => [['as' => 'org']]],
				['id' => 'c', 'type' => 'confirmation', 'message' => 'Thanks', 'fields' => ['kept']],
				'not a step',
			],
		];
		$expected = [
			'title'       => 'Register',
			'description' => '',
			'steps'       => [
				['id' => 'a', 'type' => 'form', 'title' => 'A', 'formId' => 'f-1', 'writes' => [['as' => 'org']]],
				['id' => 'c', 'type' => 'confirmation', 'message' => 'Thanks', 'fields' => ['kept']],
			],
		];

		$this->objects->expects($this->once())->method('saveObject')
			->with($expected, [], 'buildiq', 'journey', null, false, false)
			->willReturn($this->entity(uuid: 'uuid-new'));

		self::assertSame($expected + ['id' => 'uuid-new'], $this->store->save(journey: $journey));
	}//end testANewJourneyIsStoredWithReferencesOnly()

	/**
	 * An existing journey is written under its own id.
	 *
	 * @return void
	 */
	public function testAnExistingJourneyIsWrittenUnderItsId(): void {
		$this->objects->expects($this->once())->method('saveObject')
			->with($this->anything(), [], 'buildiq', 'journey', 'uuid-1', false, false)
			->willReturn($this->entity(uuid: 'uuid-1'));

		$stored = $this->store->save(journey: ['id' => 'uuid-1', 'title' => 'J', 'description' => 'D', 'steps' => []]);

		self::assertSame(['title' => 'J', 'description' => 'D', 'steps' => [], 'id' => 'uuid-1'], $stored);
	}//end testAnExistingJourneyIsWrittenUnderItsId()

	/**
	 * A form step without a form keeps an empty reference, and steps that are no list are no steps.
	 *
	 * @return void
	 */
	public function testTheStoredShapeOfOddInput(): void {
		self::assertSame(
			['title' => '', 'description' => '', 'steps' => [['type' => 'form', 'formId' => '']]],
			$this->store->storedShape(journey: ['steps' => [['type' => 'form']]])
		);
		self::assertSame([], $this->store->storedShape(journey: ['steps' => 'x'])['steps']);
	}//end testTheStoredShapeOfOddInput()

	/**
	 * A refusal by OpenRegister is rethrown with its reason.
	 *
	 * @return void
	 */
	public function testARefusedWriteIsRethrown(): void {
		$this->objects->method('saveObject')->willThrowException(new RuntimeException('schema missing'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister refused the journey: schema missing');

		$this->store->save(journey: ['title' => 'J', 'steps' => []]);
	}//end testARefusedWriteIsRethrown()

	/**
	 * A stored object double with a uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntityInterface The double.
	 */
	private function entity(string $uuid): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn($uuid);
		return $entity;
	}//end entity()
}//end class
