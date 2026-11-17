<?php

/**
 * Unit tests for AutomationFlowBackend.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The I/O half of an automation's flow: the app version's register and
 * schemas, saving and publishing the flow, switching it on and off, binding
 * it in Application.flows, and deleting and unbinding it
 * (logic-automation-actions-that-run D2, D4).
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
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-automations-compile-deterministically-to-declarative-primitives-or-one-openregister-flow-req-bqar-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Automation;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;
use OCA\Buildiq\Service\Automation\AutomationFlowBackend;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowVersionServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Scope, save, publish, toggle, bind, remove and live read of a flow.
 *
 * @covers \OCA\Buildiq\Service\Automation\AutomationFlowBackend
 * @uses \OCA\Buildiq\Exception\UnsupportedAutomationCombinationException
 * @uses \OCA\Buildiq\Support\ObjectRecord
 */
final class AutomationFlowBackendTest extends TestCase {

	/**
	 * The ApplicationVersion uuid of the automation.
	 *
	 * @var string
	 */
	private const VERSION = 'version-1';

	/**
	 * The Application uuid of that version.
	 *
	 * @var string
	 */
	private const APPLICATION = 'aaaaaaaa-1111-4222-8333-444455556666';

	/**
	 * The flow uuid.
	 *
	 * @var string
	 */
	private const FLOW = 'ffffffff-1111-4222-8333-444455556666';

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * @var FlowService&MockObject
	 */
	private FlowService&MockObject $flows;

	/**
	 * @var FlowVersionServiceInterface&MockObject
	 */
	private FlowVersionServiceInterface&MockObject $versions;

	/**
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registers;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemas;

	/**
	 * The Application payload the object service holds.
	 *
	 * @var array<string, mixed>
	 */
	private array $application = [];

	/**
	 * The version payload the object service holds.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $version = null;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->flows = $this->createMock(FlowService::class);
		$this->versions = $this->createMock(FlowVersionServiceInterface::class);
		$this->registers = $this->createMock(RegisterMapper::class);
		$this->schemas = $this->createMock(SchemaMapper::class);

		$this->version = ['register' => 'openbuild-claims-main', 'application' => self::APPLICATION];
		$this->application = ['slug' => 'claims', 'name' => 'Claims', 'flows' => []];

		$this->objects->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options = null): ?ObjectEntity {
				if ($options?->schema === 'applicationVersion') {
					return $this->version === null ? null : $this->entity(data: $this->version);
				}

				return $this->entity(data: $this->application);
			}
		);
	}//end setUp()

	/**
	 * An object entity holding a payload.
	 *
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		$entity->method('getObject')->willReturn($data);

		return $entity;
	}//end entity()

	/**
	 * The backend under test.
	 *
	 * @return AutomationFlowBackend
	 */
	private function backend(): AutomationFlowBackend {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				FlowService::class => $this->flows,
				FlowVersionServiceInterface::class => $this->versions,
			}
		);

		return new AutomationFlowBackend(
			container: $container,
			objectService: $this->objects,
			registerMapper: $this->registers,
			schemaMapper: $this->schemas,
			logger: new NullLogger(),
		);
	}//end backend()

	/**
	 * A stored flow.
	 *
	 * @param array<int, mixed> $nodes   Its nodes.
	 * @param bool              $enabled Whether it is enabled.
	 * @param string            $status  Its head status.
	 *
	 * @return Flow
	 */
	private static function storedFlow(array $nodes, bool $enabled = true, string $status = 'published'): Flow {
		$flow = new Flow();
		$flow->setUuid(self::FLOW);
		$flow->setName('aut-send');
		$flow->setNodes($nodes);
		$flow->setEdges([]);
		$flow->setEnabled($enabled);
		$flow->setLifecycleStatus($status);

		return $flow;
	}//end storedFlow()

	/**
	 * A flow document.
	 *
	 * @param string $type The node type of its only node.
	 *
	 * @return array<string, mixed>
	 */
	private static function document(string $type = 'openregister.trigger-manual'): array {
		return ['name' => 'aut-send', 'nodes' => [['id' => 'trigger', 'type' => $type, 'config' => []]], 'edges' => []];
	}//end document()

	/**
	 * The register and the sorted slugs of its schemas; an unreadable schema is skipped.
	 *
	 * @return void
	 */
	public function testScopeReadsTheRegisterAndItsSchemaSlugs(): void {
		$register = new Register();
		$register->setSchemas([3, 1, 9]);
		$this->registers->expects($this->once())->method('find')->with('openbuild-claims-main')->willReturn($register);
		$this->schemas->method('find')->willReturnCallback(
			static function (int|string $id): Schema {
				if ((int)$id === 9) {
					throw new RuntimeException('gone');
				}

				$schema = new Schema();
				$schema->setSlug([3 => 'flag', 1 => 'claim'][(int)$id]);
				return $schema;
			}
		);

		$this->assertSame(['register' => 'openbuild-claims-main', 'schemas' => ['claim', 'flag']], $this->backend()->scope(versionUuid: self::VERSION));
	}//end testScopeReadsTheRegisterAndItsSchemaSlugs()

	/**
	 * A version without a register is refused, and so is a missing version.
	 *
	 * @return void
	 */
	public function testScopeRefusesAVersionWithoutARegister(): void {
		$this->version = ['application' => self::APPLICATION];
		try {
			$this->backend()->scope(versionUuid: self::VERSION);
			$this->fail('Expected a refusal');
		} catch (UnsupportedAutomationCombinationException $e) {
			$this->assertStringContainsString('has no register', $e->getMessage());
		}

		$this->version = null;
		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage('The app version of this automation could not be read.');
		$this->backend()->scope(versionUuid: self::VERSION);
	}//end testScopeRefusesAVersionWithoutARegister()

	/**
	 * A new flow is saved with app buildiq and the enabled state, published,
	 * and bound on the Application with the full payload written back.
	 *
	 * @return void
	 */
	public function testApplyCreatesPublishesAndBindsANewFlow(): void {
		$stored = self::storedFlow(nodes: self::document()['nodes'], status: 'draft');
		$this->flows->expects($this->once())
			->method('save')
			->with(['name' => 'aut-send', 'nodes' => self::document()['nodes'], 'edges' => [], 'app' => 'buildiq', 'enabled' => false], null)
			->willReturn($stored);
		$this->versions->expects($this->once())->method('publish')->with($stored);
		$this->objects->expects($this->once())
			->method('saveObject')
			->with(
				['slug' => 'claims', 'name' => 'Claims', 'flows' => [['label' => 'aut-send', 'flow' => self::FLOW]]],
				$this->callback(
					callback: static fn (ObjectSaveOptions $options): bool => $options->target->register === 'buildiq'
						&& $options->target->schema === 'built-app'
						&& $options->uuid === self::APPLICATION
				),
				false,
				false
			);

		$uuid = $this->backend()->apply(flow: self::document(), priorUuid: null, enabled: false, versionUuid: self::VERSION);

		$this->assertSame(self::FLOW, $uuid);
	}//end testApplyCreatesPublishesAndBindsANewFlow()

	/**
	 * An unchanged graph only writes the enabled state, publishes nothing and
	 * does not bind twice.
	 *
	 * @return void
	 */
	public function testApplyOfAnUnchangedGraphOnlyTogglesEnabled(): void {
		$this->application['flows'] = [['label' => 'aut-send', 'flow' => self::FLOW]];
		$this->flows->method('find')->with(self::FLOW)->willReturn(self::storedFlow(nodes: self::document()['nodes'], enabled: true));
		$this->flows->expects($this->once())->method('save')->with(['enabled' => false], self::FLOW);
		$this->versions->expects($this->never())->method('publish');
		$this->versions->expects($this->never())->method('createDraft');
		$this->objects->expects($this->never())->method('saveObject');

		$this->assertSame(self::FLOW, $this->backend()->apply(flow: self::document(), priorUuid: self::FLOW, enabled: false, versionUuid: self::VERSION));
	}//end testApplyOfAnUnchangedGraphOnlyTogglesEnabled()

	/**
	 * An unchanged graph with the same enabled state writes nothing.
	 *
	 * @return void
	 */
	public function testApplyOfAnUnchangedFlowWritesNothing(): void {
		$this->application['flows'] = [['label' => 'aut-send', 'flow' => self::FLOW]];
		$this->flows->method('find')->willReturn(self::storedFlow(nodes: self::document()['nodes'], enabled: true));
		$this->flows->expects($this->never())->method('save');
		$this->objects->expects($this->never())->method('saveObject');

		$this->backend()->apply(flow: self::document(), priorUuid: self::FLOW, enabled: true, versionUuid: self::VERSION);
	}//end testApplyOfAnUnchangedFlowWritesNothing()

	/**
	 * A changed graph on a published flow opens a draft, saves into the same
	 * flow and publishes it.
	 *
	 * @return void
	 */
	public function testApplyOfAChangedGraphDraftsSavesAndPublishes(): void {
		$this->application['flows'] = [['label' => 'aut-send', 'flow' => self::FLOW]];
		$existing = self::storedFlow(nodes: self::document()['nodes']);
		$updated = self::storedFlow(nodes: self::document(type: 'openregister.trigger-schedule')['nodes'], status: 'draft');
		$this->flows->method('find')->willReturn($existing);

		$calls = [];
		$this->versions->expects($this->once())->method('createDraft')->with($existing)->willReturnCallback(
			static function () use (&$calls) {
				$calls[] = 'draft';
				return null;
			}
		);
		$this->flows->expects($this->once())->method('save')->with($this->callback(
			static fn (array $data): bool => $data['nodes'][0]['type'] === 'openregister.trigger-schedule'
		), self::FLOW)->willReturnCallback(
			static function () use (&$calls, $updated) {
				$calls[] = 'save';
				return $updated;
			}
		);
		$this->versions->expects($this->once())->method('publish')->with($updated)->willReturnCallback(
			static function () use (&$calls) {
				$calls[] = 'publish';
				return null;
			}
		);

		$this->backend()->apply(flow: self::document(type: 'openregister.trigger-schedule'), priorUuid: self::FLOW, enabled: true, versionUuid: self::VERSION);

		$this->assertSame(['draft', 'save', 'publish'], $calls);
	}//end testApplyOfAChangedGraphDraftsSavesAndPublishes()

	/**
	 * A changed graph on a flow whose head is already a draft opens no new draft.
	 *
	 * @return void
	 */
	public function testApplyOfAChangedDraftOpensNoNewDraft(): void {
		$this->application['flows'] = [['label' => 'aut-send', 'flow' => self::FLOW]];
		$this->flows->method('find')->willReturn(self::storedFlow(nodes: [], status: 'draft'));
		$this->flows->method('save')->willReturn(self::storedFlow(nodes: self::document()['nodes'], status: 'draft'));
		$this->versions->expects($this->never())->method('createDraft');
		$this->versions->expects($this->once())->method('publish');

		$this->backend()->apply(flow: self::document(), priorUuid: self::FLOW, enabled: true, versionUuid: self::VERSION);
	}//end testApplyOfAChangedDraftOpensNoNewDraft()

	/**
	 * A prior flow that is gone is created again.
	 *
	 * @return void
	 */
	public function testApplyRecreatesAPriorFlowThatIsGone(): void {
		$this->flows->method('find')->willThrowException(new RuntimeException('not found'));
		$this->flows->expects($this->once())->method('save')->with($this->anything(), null)->willReturn(self::storedFlow(nodes: []));

		$this->assertSame(self::FLOW, $this->backend()->apply(flow: self::document(), priorUuid: 'old-uuid', enabled: true, versionUuid: self::VERSION));
	}//end testApplyRecreatesAPriorFlowThatIsGone()

	/**
	 * Remove deletes the flow and unbinds only that flow.
	 *
	 * @return void
	 */
	public function testRemoveDeletesAndUnbinds(): void {
		$other = ['label' => 'other', 'flow' => 'bbbbbbbb-1111-4222-8333-444455556666'];
		$this->application['flows'] = [['label' => 'aut-send', 'flow' => self::FLOW], $other];
		$this->flows->method('find')->willReturn(self::storedFlow(nodes: []));
		$this->flows->expects($this->once())->method('delete')->with(self::FLOW);
		$this->objects->expects($this->once())
			->method('saveObject')
			->with(
				['slug' => 'claims', 'name' => 'Claims', 'flows' => [$other]],
				$this->callback(
					callback: static fn (ObjectSaveOptions $options): bool => $options->target->register === 'buildiq'
						&& $options->target->schema === 'built-app'
						&& $options->uuid === self::APPLICATION
				),
				false,
				false
			);

		$this->backend()->remove(flowUuid: self::FLOW, versionUuid: self::VERSION);
	}//end testRemoveDeletesAndUnbinds()

	/**
	 * A flow that is already gone is only unbound; one that is not bound writes nothing.
	 *
	 * @return void
	 */
	public function testRemoveOfAGoneFlowOnlyUnbinds(): void {
		$this->flows->method('find')->willThrowException(new RuntimeException('not found'));
		$this->flows->expects($this->never())->method('delete');
		$this->objects->expects($this->never())->method('saveObject');

		$this->backend()->remove(flowUuid: self::FLOW, versionUuid: self::VERSION);
	}//end testRemoveOfAGoneFlowOnlyUnbinds()

	/**
	 * The live document mirrors the builder's shape; a gone flow reads null.
	 *
	 * @return void
	 */
	public function testLiveReadsTheStoredGraph(): void {
		$this->flows->method('find')->willReturnOnConsecutiveCalls(
			self::storedFlow(nodes: self::document()['nodes']),
			$this->throwException(new RuntimeException('not found'))
		);

		$this->assertSame(self::document(), $this->backend()->live(flowUuid: self::FLOW));
		$this->assertNull($this->backend()->live(flowUuid: self::FLOW));
	}//end testLiveReadsTheStoredGraph()
}//end class
