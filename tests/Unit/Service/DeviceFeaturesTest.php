<?php

/**
 * Unit tests for DeviceFeatures and DeviceFeaturesListener.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pages-qr-and-barcodes/tasks.md#task-T05
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\Buildiq\Listener\DeviceFeaturesListener;
use OCA\Buildiq\Service\DeviceFeatures;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The camera is a device feature only where a page scans (REQ-BQQR-004).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(DeviceFeatures::class)]
#[CoversClass(DeviceFeaturesListener::class)]
final class DeviceFeaturesTest extends TestCase {

	/**
	 * A manifest of these pages.
	 *
	 * @param array<int, array<string, mixed>> $pages The pages.
	 *
	 * @return array<string, mixed>
	 */
	private static function manifest(array $pages): array {
		return ['version' => '1.0.0', 'pages' => $pages];
	}//end manifest()

	/**
	 * An index page that finds a record by scanning uses the camera.
	 *
	 * @return void
	 */
	public function testAScanningIndexPageUsesTheCamera(): void {
		$manifest = self::manifest([
			['id' => 'assets', 'type' => 'index', 'config' => ['schema' => 'asset', 'scan' => ['field' => 'tag', 'onMatch' => 'open']]],
		]);

		$this->assertSame(['camera'], DeviceFeatures::derive(manifest: $manifest));
	}//end testAScanningIndexPageUsesTheCamera()

	/**
	 * A form with a scan field, however deep, uses the camera once.
	 *
	 * @return void
	 */
	public function testAScanFieldInAFormUsesTheCameraOnce(): void {
		$manifest = self::manifest([
			[
				'id' => 'intake',
				'type' => 'form',
				'config' => ['fields' => [['key' => 'name', 'type' => 'string'], ['key' => 'tag', 'type' => 'scan', 'formats' => ['qr']]]],
			],
			['id' => 'move', 'type' => 'form', 'config' => ['sections' => [['fields' => [['name' => 'to', 'type' => 'scan']]]]]],
		]);

		$this->assertSame(['camera'], DeviceFeatures::derive(manifest: $manifest));
	}//end testAScanFieldInAFormUsesTheCameraOnce()

	/**
	 * An app with a code widget and no scan action or scan field keeps the camera closed.
	 *
	 * @return void
	 */
	public function testShowingACodeUsesNoCamera(): void {
		$manifest = self::manifest([
			['id' => 'asset', 'type' => 'detail', 'config' => ['widgets' => [['id' => 'label', 'type' => 'code', 'format' => 'qr', 'source' => 'tag']]]],
			['id' => 'assets', 'type' => 'index', 'config' => ['scan' => []]],
			['id' => 'broken', 'type' => 'index', 'config' => 'not a config'],
			'not a page',
		]);

		$this->assertSame([], DeviceFeatures::derive(manifest: $manifest));
		$this->assertSame([], DeviceFeatures::derive(manifest: []));
		$this->assertFalse(DeviceFeatures::includes(features: null, feature: 'camera'));
		$this->assertTrue(DeviceFeatures::includes(features: ['camera'], feature: 'camera'));
	}//end testShowingACodeUsesNoCamera()

	/**
	 * The listener over a resolver that reads the entity's own register and schema.
	 *
	 * @return DeviceFeaturesListener
	 */
	private function listener(): DeviceFeaturesListener {
		$slugs = $this->createStub(ObjectSchemaSlugResolverInterface::class);
		$slugs->method('registerSlug')->willReturnCallback(static fn (ObjectEntity $entity): string => (string)$entity->getRegister());
		$slugs->method('schemaSlug')->willReturnCallback(static fn (ObjectEntity $entity): string => (string)$entity->getSchema());

		return new DeviceFeaturesListener($slugs);
	}//end listener()

	/**
	 * An object entity.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $data   The object.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setRegister('buildiq');
		$entity->setSchema($schema);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * Saving a version stamps its device features from its manifest, on create and on update.
	 *
	 * @return void
	 */
	public function testSavingAVersionStampsItsDeviceFeatures(): void {
		$scanning = self::manifest([['id' => 'assets', 'type' => 'index', 'config' => ['scan' => ['field' => 'tag']]]]);

		$created = self::entity('applicationVersion', ['slug' => 'production', 'manifest' => $scanning]);
		$this->listener()->handle(new ObjectCreatingEvent($created));
		$this->assertSame(['camera'], $created->getObject()['deviceFeatures']);

		$updated = self::entity('applicationVersion', ['slug' => 'production', 'manifest' => self::manifest([]), 'deviceFeatures' => ['camera']]);
		$this->listener()->handle(new ObjectUpdatingEvent($updated, $created));
		$this->assertSame([], $updated->getObject()['deviceFeatures']);
	}//end testSavingAVersionStampsItsDeviceFeatures()

	/**
	 * Another schema's object is left as it was.
	 *
	 * @return void
	 */
	public function testOtherObjectsAreLeftAlone(): void {
		$other = self::entity('application', ['manifest' => self::manifest([['id' => 'a', 'type' => 'index', 'config' => ['scan' => ['field' => 'x']]]])]);

		$this->listener()->handle(new ObjectCreatingEvent($other));

		$this->assertArrayNotHasKey('deviceFeatures', $other->getObject());
	}//end testOtherObjectsAreLeftAlone()
}//end class
