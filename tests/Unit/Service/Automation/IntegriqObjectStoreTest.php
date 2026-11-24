<?php

/**
 * Unit tests for IntegriqObjectStore.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Saves and deletes in the integriq register go through OpenRegister main's
 * object API, keep the acting user's rights, and fail closed
 * (logic-email-and-webhook-triggers D2, D3, D6).
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Automation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-logic-email-and-webhook-triggers/specs/automation-inbound-triggers/spec.md#requirement-mail-starts-the-automations-flow-req-bqtr-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Automation;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;
use OCA\Buildiq\Service\Automation\IntegriqObjectStore;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Save, delete and the refusals.
 *
 * @covers \OCA\Buildiq\Service\Automation\IntegriqObjectStore
 */
final class IntegriqObjectStoreTest extends TestCase {

	/**
	 * A stand-in for OpenRegister's object service with main's signatures, recording every call.
	 *
	 * @param string|null $uuid  The uuid a save returns.
	 * @param bool        $throw Whether every call throws.
	 *
	 * @return object
	 */
	private static function objectService(?string $uuid = 'stored-1', bool $throw = false): object {
		return new class($uuid, $throw) {
			/**
			 * @var list<array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * @param string|null $uuid  The uuid a save returns.
			 * @param bool        $throw Whether every call throws.
			 */
			public function __construct(private readonly ?string $uuid, private readonly bool $throw) {
			}

			/**
			 * @param array<string, mixed> $object        The object.
			 * @param ObjectSaveOptions    $options       The options.
			 * @param bool                 $_rbac         Apply RBAC.
			 * @param bool                 $_multitenancy Apply tenancy.
			 *
			 * @return object
			 */
			public function saveObject(array $object, ObjectSaveOptions $options, bool $_rbac = true, bool $_multitenancy = true): object {
				$this->calls[] = ['save', $object, $options, $_rbac, $_multitenancy];
				if ($this->throw === true) {
					throw new RuntimeException('schema validation failed');
				}

				$uuid = $this->uuid;
				return new class($uuid) {
					/**
					 * @param string|null $uuid The uuid.
					 */
					public function __construct(private readonly ?string $uuid) {
					}

					/**
					 * @return string|null
					 */
					public function getUuid(): ?string {
						return $this->uuid;
					}
				};
			}

			/**
			 * @param ObjectDeletion $deletion      The deletion.
			 * @param bool           $_rbac         Apply RBAC.
			 * @param bool           $_multitenancy Apply tenancy.
			 *
			 * @return bool
			 */
			public function deleteObject(ObjectDeletion $deletion, bool $_rbac = true, bool $_multitenancy = true): bool {
				$this->calls[] = ['delete', $deletion, $_rbac, $_multitenancy];
				if ($this->throw === true) {
					throw new RuntimeException('gone');
				}

				return true;
			}
		};
	}//end objectService()

	/**
	 * @param object|null $service The object service, or null for none.
	 *
	 * @return IntegriqObjectStore
	 */
	private function store(?object $service): IntegriqObjectStore {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn($service !== null);
		$container->method('get')->with('OCA\\OpenRegister\\Contract\\ObjectServiceInterface')->willReturn($service);
		return new IntegriqObjectStore(container: $container);
	}//end store()

	/**
	 * A save targets the integriq register and the schema, replaces the given
	 * uuid, and keeps the acting user's rights.
	 *
	 * @return void
	 */
	public function testSaveWritesThroughMainsObjectApi(): void {
		$service = self::objectService();

		$uuid = $this->store(service: $service)->save(schema: 'endpoint', object: ['endpoint' => 'buildiq/a/b'], uuid: 'prior-1');

		$this->assertSame('stored-1', $uuid);
		$this->assertCount(1, $service->calls);
		[$kind, $object, $options, $rbac, $tenancy] = $service->calls[0];
		$this->assertSame('save', $kind);
		$this->assertSame(['endpoint' => 'buildiq/a/b'], $object);
		$this->assertSame('integriq', $options->target->register);
		$this->assertSame('endpoint', $options->target->schema);
		$this->assertSame('prior-1', $options->uuid);
		$this->assertTrue($rbac);
		$this->assertTrue($tenancy);
	}//end testSaveWritesThroughMainsObjectApi()

	/**
	 * A refused save fails closed with the schema named.
	 *
	 * @return void
	 */
	public function testARefusedSaveFailsClosed(): void {
		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage('(event_subscription: schema validation failed)');

		$this->store(service: self::objectService(throw: true))->save(schema: 'event_subscription', object: [], uuid: null);
	}//end testARefusedSaveFailsClosed()

	/**
	 * A save that comes back without a uuid cannot be recorded, so it fails.
	 *
	 * @return void
	 */
	public function testASaveWithoutAUuidFails(): void {
		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage('has no uuid');

		$this->store(service: self::objectService(uuid: ''))->save(schema: 'rule', object: [], uuid: null);
	}//end testASaveWithoutAUuidFails()

	/**
	 * Without OpenRegister's object service nothing is written.
	 *
	 * @return void
	 */
	public function testWithoutTheObjectServiceTheStoreIsUnavailable(): void {
		$store = $this->store(service: null);
		$this->assertFalse($store->available());
		$this->assertFalse($store->delete(schema: 'rule', uuid: 'r-1'));

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage(IntegriqObjectStore::UNAVAILABLE_REFUSAL);
		$store->save(schema: 'rule', object: [], uuid: null);
	}//end testWithoutTheObjectServiceTheStoreIsUnavailable()

	/**
	 * A delete targets the uuid in the integriq register; a failing one answers false.
	 *
	 * @return void
	 */
	public function testDeleteTargetsTheObjectAndSwallowsAFailure(): void {
		$service = self::objectService();
		$this->assertTrue($this->store(service: $service)->delete(schema: 'consumer', uuid: 'c-1'));
		$deletion = $service->calls[0][1];
		$this->assertSame('c-1', $deletion->uuid);
		$this->assertSame('integriq', $deletion->target->register);
		$this->assertSame('consumer', $deletion->target->schema);
		$this->assertTrue($service->calls[0][2]);

		$this->assertFalse($this->store(service: self::objectService(throw: true))->delete(schema: 'consumer', uuid: 'c-1'));
		$this->assertFalse($this->store(service: $service)->delete(schema: 'consumer', uuid: ''));
	}//end testDeleteTargetsTheObjectAndSwallowsAFailure()
}//end class
