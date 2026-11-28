<?php

/**
 * Tests for AutomationSubFlowTargets.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Automation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-an-automation-can-call-another-req-bqsf-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Automation;

use OCA\Buildiq\Service\Automation\AutomationSubFlowTargets;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The named automations are read with their app, sharing and compiled flow.
 *
 * @covers \OCA\Buildiq\Service\Automation\AutomationSubFlowTargets
 */
final class AutomationSubFlowTargetsTest extends TestCase {

	/**
	 * An object entity serialising to the given payload.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(array $payload): ObjectEntity {
		$entity = new class() extends ObjectEntity {
			/**
			 * @var array<string,mixed>
			 */
			public array $payload = [];

			/**
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return $this->payload;
			}
		};
		$entity->payload = $payload;

		return $entity;
	}//end entity()

	/**
	 * A readable automation is returned with the keys the step needs; an unreadable or missing one is left out.
	 *
	 * @return void
	 */
	public function testReadableAutomationsAreReturnedAndOthersLeftOut(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			callback: static function (string $id): ?ObjectEntity {
				return match ($id) {
					'a-1' => self::entity(payload: [
						'slug' => 'check-address',
						'name' => 'Controleer adres',
						'applicationSlug' => 'permits',
						'shared' => true,
						'provenance' => ['flowUuid' => 'flow-7'],
						'actions' => [['type' => 'object-op']],
					]),
					'a-2' => self::entity(payload: ['slug' => 'plain', 'shared' => 'yes', 'provenance' => 'broken']),
					'missing' => null,
					default => throw new RuntimeException('no access'),
				};
			}
		);

		$targets = (new AutomationSubFlowTargets(objectService: $objects, logger: new NullLogger()))
			->targets(uuids: ['a-1', 'a-2', 'missing', 'forbidden']);

		$this->assertSame(expected: ['a-1', 'a-2'], actual: array_keys(array: $targets));
		$this->assertSame(
			expected: [
				'slug' => 'check-address',
				'name' => 'Controleer adres',
				'applicationSlug' => 'permits',
				'shared' => true,
				'provenance' => ['flowUuid' => 'flow-7'],
			],
			actual: $targets['a-1']
		);
		// Only a literal true shares, and a malformed provenance reads as none.
		$this->assertFalse(condition: $targets['a-2']['shared']);
		$this->assertSame(expected: [], actual: $targets['a-2']['provenance']);
	}//end testReadableAutomationsAreReturnedAndOthersLeftOut()
}//end class
