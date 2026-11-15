<?php

/**
 * Tests for storing an app version's device features on save
 * (forms-signature-audio-and-location, REQ-BQSA-004).
 *
 * The listener and DeviceFeatures are the real classes; the slug resolver
 * is a mock and the OpenRegister save events are the out-of-container stubs.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Listener;

use OCA\Buildiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\Buildiq\Listener\DeviceFeaturesListener;
use OCA\Buildiq\Service\DeviceFeatures;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Buildiq\Listener\DeviceFeaturesListener
 * @uses \OCA\Buildiq\Service\DeviceFeatures
 */
class DeviceFeaturesListenerTest extends TestCase {

	/**
	 * A manifest with an audio field and a location field.
	 *
	 * @var array<string,mixed>
	 */
	private const MANIFEST = [
		'pages' => [
			['type' => 'form', 'config' => ['fields' => [['key' => 'spraaknotitie', 'type' => 'audio'], ['key' => 'plek', 'type' => 'location']]]],
		],
	];

	/**
	 * A new version stores the features of its manifest.
	 *
	 * @return void
	 */
	public function testANewVersionStoresItsFeatures(): void {
		$event = new ObjectCreatingEvent($this->entity(data: ['manifest' => self::MANIFEST]));

		$this->listener(schema: 'applicationVersion')->handle($event);

		$this->assertSame(['deviceFeatures' => ['geolocation', 'microphone']], $event->getModifiedData());
	}//end testANewVersionStoresItsFeatures()

	/**
	 * An updated version restates its features beside other listeners' changes, and an empty list clears them.
	 *
	 * @return void
	 */
	public function testAnUpdatedVersionRestatesItsFeatures(): void {
		$event = new ObjectUpdatingEvent($this->entity(data: ['manifest' => ['pages' => []]]), $this->entity(data: ['manifest' => self::MANIFEST]));
		$event->setModifiedData(['other' => 1]);

		$this->listener(schema: 'applicationVersion')->handle($event);

		$this->assertSame(['other' => 1, 'deviceFeatures' => []], $event->getModifiedData());
	}//end testAnUpdatedVersionRestatesItsFeatures()

	/**
	 * Objects of another schema or register, a version without a manifest and foreign events are left alone.
	 *
	 * @return void
	 */
	public function testEverythingElseIsLeftAlone(): void {
		$other = new ObjectCreatingEvent($this->entity(data: ['manifest' => self::MANIFEST]));
		$this->listener(schema: 'built-app')->handle($other);
		$this->assertSame([], $other->getModifiedData());

		$foreign = new ObjectCreatingEvent($this->entity(data: ['manifest' => self::MANIFEST]));
		$this->listener(schema: 'applicationVersion', register: 'elsewhere')->handle($foreign);
		$this->assertSame([], $foreign->getModifiedData());

		$bare = new ObjectCreatingEvent($this->entity(data: ['status' => 'draft']));
		$this->listener(schema: 'applicationVersion')->handle($bare);
		$this->assertSame([], $bare->getModifiedData());

		$this->listener(schema: 'applicationVersion')->handle(new Event());
		$this->addToAssertionCount(1);
	}//end testEverythingElseIsLeftAlone()

	/**
	 * The listener with a slug resolver answering one register and schema.
	 *
	 * @param string $schema   The schema slug.
	 * @param string $register The register slug.
	 *
	 * @return DeviceFeaturesListener
	 */
	private function listener(string $schema, string $register='buildiq'): DeviceFeaturesListener {
		$slugs = $this->createMock(ObjectSchemaSlugResolverInterface::class);
		$slugs->method('registerSlug')->willReturn($register);
		$slugs->method('schemaSlug')->willReturn($schema);

		return new DeviceFeaturesListener(slugs: $slugs, features: new DeviceFeatures());
	}//end listener()

	/**
	 * An entity with object data.
	 *
	 * @param array<string,mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()
}//end class
