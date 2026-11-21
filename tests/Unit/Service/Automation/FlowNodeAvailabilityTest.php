<?php

/**
 * Unit tests for the flow node availability check.
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
 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-ai-steps-need-the-hermiq-node-req-bqls-005
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Automation;

use OCA\Buildiq\Service\Automation\FlowNodeAvailability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * A registered node reads as there; an unknown node, no registry or a failing one read as not there.
 */
#[CoversClass(FlowNodeAvailability::class)]
final class FlowNodeAvailabilityTest extends TestCase {

	/**
	 * A container that serves one registry under OpenRegister's id.
	 *
	 * @param mixed $registry What the container returns.
	 *
	 * @return ContainerInterface
	 */
	private function container(mixed $registry): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($registry): mixed {
				if ($id !== FlowNodeAvailability::REGISTRY) {
					throw new RuntimeException('Unexpected service ' . $id);
				}

				if ($registry instanceof \Throwable) {
					throw $registry;
				}

				return $registry;
			}
		);

		return $container;
	}//end container()

	/**
	 * A registry that knows only Hermiq's agent node.
	 *
	 * @return object
	 */
	private static function registry(): object {
		return new class {
			/**
			 * The node of a type.
			 *
			 * @param string $type The type.
			 *
			 * @return object
			 */
			public function get(string $type): object {
				if ($type !== 'hermiq.agent-step') {
					throw new UnexpectedValueException('Unknown node type "' . $type . '".');
				}

				return new \stdClass();
			}
		};
	}//end registry()

	/**
	 * The registered node is there, an unregistered one is not.
	 *
	 * @return void
	 */
	public function testAsksTheRegistry(): void {
		$nodes = new FlowNodeAvailability(container: $this->container(registry: self::registry()));

		$this->assertTrue($nodes->has(type: 'hermiq.agent-step'));
		$this->assertFalse($nodes->has(type: 'openconnector.source-call'));
	}//end testAsksTheRegistry()

	/**
	 * No registry, a registry without `get()` and a failing container all read as "not there".
	 *
	 * @return void
	 */
	public function testFailsClosed(): void {
		$this->assertFalse((new FlowNodeAvailability(container: $this->container(registry: new RuntimeException('no service'))))->has(type: 'hermiq.agent-step'));
		$this->assertFalse((new FlowNodeAvailability(container: $this->container(registry: new \stdClass())))->has(type: 'hermiq.agent-step'));
		$this->assertFalse((new FlowNodeAvailability(container: $this->container(registry: 'not an object')))->has(type: 'hermiq.agent-step'));
	}//end testFailsClosed()

	/**
	 * The catalogue entry is read from the palette, so a node the engine hides
	 * has none; no registry, no palette or a failing one read as none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-script-runs-only-in-the-code-runner-req-bqss-003
	 */
	public function testReadsTheCatalogueEntry(): void {
		$registry = new class {
			/**
			 * The palette.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function palette(): array {
				return [['id' => 'openregister.filter'], 'junk', ['id' => 'openregister.code', 'configKeys' => ['source']]];
			}
		};
		$nodes = new FlowNodeAvailability(container: $this->container(registry: $registry));

		$this->assertSame(expected: ['id' => 'openregister.code', 'configKeys' => ['source']], actual: $nodes->catalogueEntry(type: 'openregister.code'));
		$this->assertNull(actual: $nodes->catalogueEntry(type: 'hermiq.agent-step'));
		$noPalette = new FlowNodeAvailability(container: $this->container(registry: self::registry()));
		$this->assertNull(actual: $noPalette->catalogueEntry(type: 'openregister.code'));
		$failing = new FlowNodeAvailability(container: $this->container(registry: new RuntimeException('no service')));
		$this->assertNull(actual: $failing->catalogueEntry(type: 'openregister.code'));
	}//end testReadsTheCatalogueEntry()
}//end class
